<?php

declare(strict_types=1);

namespace Endurance\RoutePostgresql;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Endurance\Foundation\ValueObject\Uuid;
use Endurance\Geo\PathGeometry;
use Endurance\Geo\ViaPoints;
use Endurance\Route\Application\Port\RouteRepository;
use Endurance\Route\Application\Port\RouteRevisionReader;
use Endurance\Route\Entity\Route;
use Endurance\Route\Entity\RouteRevision;
use Endurance\Route\Exception\RouteNotFound;
use Endurance\Route\Exception\RouteRevisionAlreadyExists;
use Endurance\Route\Exception\RouteRevisionNotFound;
use Endurance\Route\ValueObject\ExpectedRouteState;
use Endurance\Route\ValueObject\RouteId;
use Endurance\Route\ValueObject\RouteRevisionId;

/** Unscoped storage: an application must authorize every read and write. */
final readonly class DoctrineDbalRouteRepository implements RouteRepository, RouteRevisionReader
{
    public function __construct(private Connection $connection, private int $batchSize = 256, private int $pageSize = 512)
    {
        if ($batchSize < 1 || $batchSize > 1000 || $pageSize < 1 || $pageSize > 10000) {
            throw new \InvalidArgumentException('Invalid route batch/page size.');
        }
    }

    public function assertUsesConnection(Connection $connection): void
    {
        if ($this->connection !== $connection) {
            throw new \LogicException('Route repository uses another DBAL connection.');
        }
    }

    public function get(RouteId $id): Route
    {
        $columns = RouteReadProjection::cardColumns('t');
        $row = $this->connection->fetchAssociative("SELECT {$columns} FROM routes t WHERE t.id = ?", [$id->toString()]);
        if (false === $row) {
            throw new RouteNotFound('Route not found.');
        }

        return RouteReadProjection::readCard($row);
    }

    /** Caller must be inside a transaction; this is the first lock for a route. */
    public function lockIdentity(RouteId $id): void
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Identity locks require a transaction.');
        }
        if (false === $this->connection->fetchOne('SELECT id FROM route_identities WHERE id = ? FOR UPDATE', [$id->toString()])) {
            throw new RouteNotFound('Route not found.');
        }
    }

    public function beginRevision(RouteId $routeId, RouteRevisionId $revisionId): DoctrineDbalRouteRevisionWriter
    {
        $token = Uuid::generate()->toString();
        try {
            $this->connection->transactional(function () use ($routeId, $revisionId, $token): void {
                $this->connection->executeStatement('INSERT INTO route_identities(id) VALUES (?) ON CONFLICT DO NOTHING', [$routeId->toString()]);
                $this->lockIdentity($routeId);
                $this->connection->insert('route_revisions', ['id' => $revisionId->toString(), 'route_id' => $routeId->toString(), 'state' => 'staging', 'write_token' => $token]);
            });
        } catch (UniqueConstraintViolationException $e) {
            throw new RouteRevisionAlreadyExists('Revision ID already exists.', previous: $e);
        }

        return new DoctrineDbalRouteRevisionWriter($this->connection, $this, $routeId, $revisionId, $token, $this->batchSize);
    }

    public function saveMetadata(Route $route, ExpectedRouteState $expected): void
    {
        $this->connection->transactional(function () use ($route, $expected): void {
            $this->lockIdentity($route->id);
            $current = $this->get($route->id);
            $current->assertExpected($expected);
            if (!$route->version->equals($expected->version->next()) || !$route->currentRevisionId->equals($current->currentRevisionId) || !$route->createdAt->equals($current->createdAt)) {
                throw new \InvalidArgumentException('Metadata save must preserve content and advance one version.');
            }
            $this->connection->update('routes', RowCodec::card($route), ['id' => $route->id->toString()]);
        });
    }

    public function getRevision(RouteRevisionId $id): RouteRevision
    {
        $columns = RouteReadProjection::revisionColumns('v');
        $row = $this->connection->fetchAssociative("SELECT {$columns} FROM route_revisions v WHERE v.id = ? AND v.state = 'published'", [$id->toString()]);
        if (false === $row) {
            throw new RouteRevisionNotFound('Revision not found.');
        }

        return RouteReadProjection::readRevision($row);
    }

    public function geometry(RouteRevisionId $id): PathGeometry
    {
        $columns = RouteReadProjection::geometryColumns('p');

        return new PathGeometry($this->read('geometry_points', $columns, $id, 'geometryPointCount', RouteReadProjection::readGeometryPoint(...)));
    }

    public function viaPoints(RouteRevisionId $id): ViaPoints
    {
        $columns = RouteReadProjection::viaPointColumns('p');

        return new ViaPoints($this->read('via_points', $columns, $id, 'viaPointCount', RouteReadProjection::readViaPoint(...)));
    }

    public function waypoints(RouteRevisionId $id): iterable
    {
        $columns = RouteReadProjection::waypointColumns('p');

        return $this->read('waypoints', $columns, $id, 'waypointCount', RouteReadProjection::readWaypoint(...));
    }

    public function cues(RouteRevisionId $id): iterable
    {
        $columns = RouteReadProjection::cueColumns('p');

        return $this->read('cues', $columns, $id, 'cueCount', RouteReadProjection::readCue(...));
    }

    /**
     * @template T
     *
     * @param \Closure(array<string,mixed>): T $decode
     *
     * @return \Generator<int,T>
     */
    private function read(string $table, string $columns, RouteRevisionId $id, string $countField, \Closure $decode): \Generator
    {
        $descriptor = $this->getRevision($id);
        $geometry = 'geometry_points' === $table;
        $order = $geometry ? 'p.part_index, p.point_index' : 'p.point_index';
        $cursor = null;
        $seen = 0;
        do {
            $params = [$id->toString()];
            $where = '';
            if (null !== $cursor) {
                $where = $geometry ? ' AND (p.part_index,p.point_index) > (?,?)' : ' AND p.point_index > ?';
                $params = [...$params, ...$cursor];
            }
            $rows = $this->connection->fetchAllAssociative("SELECT {$columns} FROM route_{$table} p JOIN route_revisions r ON r.id=p.revision_id AND r.state='published' WHERE p.revision_id=?".$where.' ORDER BY '.$order.' LIMIT '.$this->pageSize, $params);
            foreach ($rows as $row) {
                $cursor = $geometry ? [(int) $row['part_index'], (int) $row['point_index']] : [(int) $row['point_index']];
                ++$seen;
                yield $decode($row);
            }
        } while (count($rows) === $this->pageSize);
        $this->getRevision($id); // Concurrent purge cannot turn a partial read into success.
        if ($descriptor->summary->$countField !== $seen) {
            throw new \UnexpectedValueException('Stored route content count does not match its descriptor.');
        }
    }
}
