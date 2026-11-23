<?php

declare(strict_types=1);

namespace Endurance\RoutePostgresql;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Endurance\Geo\PathPoint;
use Endurance\Geo\PathPosition;
use Endurance\Geo\PathSequence;
use Endurance\Geo\ViaPoint;
use Endurance\Route\Application\Port\RouteRevisionWriter;
use Endurance\Route\Entity\Route;
use Endurance\Route\Entity\RouteRevision;
use Endurance\Route\Exception\RouteAlreadyExists;
use Endurance\Route\ValueObject\ExpectedRouteState;
use Endurance\Route\ValueObject\NavigationCue;
use Endurance\Route\ValueObject\RouteContentSummary;
use Endurance\Route\ValueObject\RouteId;
use Endurance\Route\ValueObject\RouteRevisionId;
use Endurance\Route\ValueObject\RouteWaypoint;

final class DoctrineDbalRouteRevisionWriter implements RouteRevisionWriter
{
    private readonly PathSequence $sequence;
    /** @var list<array<string, int|string|null>> */
    private array $buffer = [];
    private ?string $table = null;
    private bool $closed = false;
    private bool $failed = false;
    private int $geometry = 0;
    private int $parts = 0;
    private int $geometryHeights = 0;
    private int $via = 0;
    private int $viaHeights = 0;
    private int $waypoints = 0;
    private int $cues = 0;

    /** @internal Obtain writers from the repository that owns the reservation. */
    public function __construct(private readonly Connection $connection, private readonly DoctrineDbalRouteRepository $repository, private readonly RouteId $routeId, private readonly RouteRevisionId $revisionId, private readonly string $token, private readonly int $batchSize)
    {
        $repository->assertUsesConnection($connection);
        $this->sequence = new PathSequence();
    }

    public function assertUsesConnection(Connection $connection): void
    {
        if ($this->connection !== $connection) {
            throw new \LogicException('Route revision writer uses another DBAL connection.');
        }
    }

    public function appendGeometry(PathPoint $point): void
    {
        $this->assertOpen();
        $part = $this->sequence->accept($point->position);
        $this->enqueue('geometry_points', ['part_index' => $point->position->partIndex, 'point_index' => $point->position->pointIndex, ...RowCodec::coordinate($point->coordinate), ...RowCodec::elevation($point->elevation)]);
        ++$this->geometry;
        $this->parts += $part ? 1 : 0;
        $this->geometryHeights += null === $point->elevation ? 0 : 1;
    }

    public function appendViaPoint(ViaPoint $point): void
    {
        $this->assertIndex($point->index, $this->via);
        $this->enqueue('via_points', ['point_index' => $point->index, ...RowCodec::coordinate($point->coordinate), ...RowCodec::elevation($point->elevation)]);
        ++$this->via;
        $this->viaHeights += null === $point->elevation ? 0 : 1;
    }

    public function appendWaypoint(RouteWaypoint $point): void
    {
        $this->assertIndex($point->index, $this->waypoints);
        $this->enqueue('waypoints', ['point_index' => $point->index, ...RowCodec::coordinate($point->coordinate), 'name' => $point->name, ...RowCodec::elevation($point->elevation), ...RowCodec::anchor($point->anchor, $this->revisionId)]);
        ++$this->waypoints;
    }

    public function appendCue(NavigationCue $cue): void
    {
        $this->assertIndex($cue->index, $this->cues);
        $this->enqueue('cues', ['point_index' => $cue->index, 'instruction' => $cue->instruction, ...RowCodec::coordinate($cue->coordinate), ...RowCodec::anchor($cue->anchor, $this->revisionId)]);
        ++$this->cues;
    }

    public function hasGeometryPosition(PathPosition $position): bool
    {
        $this->assertOpen();
        $this->flush();

        return false !== $this->connection->fetchOne("SELECT 1 FROM route_geometry_points p JOIN route_revisions r ON r.id=p.revision_id WHERE p.revision_id=? AND p.part_index=? AND p.point_index=? AND r.state='staging' AND r.write_token=?", [$this->revisionId->toString(), $position->partIndex, $position->pointIndex, $this->token]);
    }

    public function publishNew(Route $route, RouteRevision $revision): void
    {
        $this->publish($route, $revision, null);
    }

    public function publishReplacement(Route $route, RouteRevision $revision, ExpectedRouteState $expected): void
    {
        $this->publish($route, $revision, $expected);
    }

    public function discard(): void
    {
        // Also run after a nested publication: an enclosing transaction may have
        // rolled back. Committed/lost-ack publications no longer match the token.
        $this->connection->executeStatement("DELETE FROM route_revisions WHERE id=? AND write_token=? AND state='staging'", [$this->revisionId->toString(), $this->token]);
        $this->buffer = [];
        $this->closed = true;
    }

    /** @param array<string,int|string|null> $values */
    private function enqueue(string $table, array $values): void
    {
        $this->assertOpen();
        if ($table !== $this->table) {
            $this->flush();
            $this->table = $table;
        }
        $this->buffer[] = ['revision_id' => $this->revisionId->toString(), ...$values];
        if (count($this->buffer) >= $this->batchSize) {
            $this->flush();
        }
    }

    private function flush(): void
    {
        if ([] === $this->buffer) {
            return;
        }
        try {
            $this->connection->transactional(function (): void {
                $this->lockStaging();
                $columns = array_keys($this->buffer[0]);
                $tuple = '('.implode(',', array_fill(0, count($columns), '?')).')';
                $params = [];
                foreach ($this->buffer as $row) {
                    array_push($params, ...array_values($row));
                }
                $this->connection->executeStatement('INSERT INTO route_'.$this->table.' ('.implode(',', $columns).') VALUES '.implode(',', array_fill(0, count($this->buffer), $tuple)), $params);
                $this->connection->executeStatement('UPDATE route_revisions SET touched_at=clock_timestamp() WHERE id=?', [$this->revisionId->toString()]);
            });
            $this->buffer = [];
        } catch (\Throwable $e) {
            $this->failed = true;
            throw $e;
        }
    }

    private function lockStaging(): void
    {
        if (false === $this->connection->fetchOne("SELECT id FROM route_revisions WHERE id=? AND route_id=? AND write_token=? AND state='staging' FOR UPDATE", [$this->revisionId->toString(), $this->routeId->toString(), $this->token])) {
            throw new \LogicException('Route reservation expired or was closed.');
        }
    }

    private function publish(Route $route, RouteRevision $revision, ?ExpectedRouteState $expected): void
    {
        $this->assertOpen();
        if (!$route->id->equals($this->routeId) || !$revision->routeId->equals($this->routeId) || !$revision->id->equals($this->revisionId) || !$route->currentRevisionId->equals($this->revisionId) || !$route->updatedAt->equals($revision->createdAt)) {
            throw new \InvalidArgumentException('Publication must match the reservation and timestamp.');
        }
        $summary = new RouteContentSummary($this->geometry, $this->parts, $this->geometryHeights, $this->via, $this->viaHeights, $this->waypoints, $this->cues);
        if ($summary != $revision->summary) {
            throw new \InvalidArgumentException('Descriptor does not match staged content.');
        }
        $this->flush();
        $this->connection->transactional(function () use ($route, $revision, $expected): void {
            $this->repository->lockIdentity($this->routeId);
            $this->lockStaging();
            if (null === $expected) {
                if (1 !== $route->version->value || !$route->createdAt->equals($revision->createdAt)) {
                    throw new \InvalidArgumentException('Invalid new route card.');
                }
                if (false !== $this->connection->fetchOne('SELECT id FROM routes WHERE id=?', [$this->routeId->toString()])) {
                    throw new RouteAlreadyExists('Route already exists.');
                }
            } else {
                $current = $this->repository->get($this->routeId);
                $current->assertExpected($expected);
                if (!$route->version->equals($expected->version->next()) || !$route->createdAt->equals($current->createdAt) || $route->metadata != $current->metadata) {
                    throw new \InvalidArgumentException('Replacement must preserve the card metadata and advance its version.');
                }
            }
            foreach (['geometry_points' => $this->geometry, 'via_points' => $this->via, 'waypoints' => $this->waypoints, 'cues' => $this->cues] as $table => $count) {
                if ((int) $this->connection->fetchOne('SELECT count(*) FROM route_'.$table.' WHERE revision_id=?', [$this->revisionId->toString()]) !== $count) {
                    throw new \UnexpectedValueException('Staged content was altered.');
                }
            }
            $this->connection->update('route_revisions', ['state' => 'published', 'write_token' => null, 'created_at' => RowCodec::time($revision->createdAt), 'properties' => RowCodec::properties($revision->properties), 'summary' => json_encode(get_object_vars($revision->summary), JSON_THROW_ON_ERROR), 'reported_distance_m' => RowCodec::number($revision->properties->reportedDistance?->metres)], ['id' => $this->revisionId->toString()]);
            if (null === $expected) {
                try {
                    $this->connection->insert('routes', RowCodec::card($route));
                } catch (UniqueConstraintViolationException $e) {
                    throw new RouteAlreadyExists('Route already exists.', previous: $e);
                }
            } else {
                $this->connection->update('routes', RowCodec::card($route), ['id' => $this->routeId->toString()]);
            }
        });
        $this->closed = true;
    }

    private function assertIndex(int $actual, int $expected): void
    {
        $this->assertOpen();
        if ($actual !== $expected) {
            throw new \InvalidArgumentException('Stream indexes must be consecutive from zero.');
        }
    }

    private function assertOpen(): void
    {
        if ($this->closed || $this->failed) {
            throw new \LogicException('Route writer is closed or failed.');
        }
    }
}
