<?php

declare(strict_types=1);

namespace Youmad\Endurance\RoutePostgresql;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Youmad\Endurance\Route\Exception\RouteNotFound;
use Youmad\Endurance\Route\ValueObject\RouteId;

/** Privileged lifecycle operations; the application supplies authorization. */
final readonly class DoctrineDbalRouteMaintenance
{
    public function __construct(private Connection $connection, private DoctrineDbalRouteRepository $repository)
    {
        $repository->assertUsesConnection($connection);
    }

    public function purgeCard(RouteId $id): void
    {
        $this->connection->transactional(function () use ($id): void {
            $this->repository->lockIdentity($id);
            $this->connection->delete('routes', ['id' => $id->toString()]);
            $this->connection->executeStatement("DELETE FROM route_revisions WHERE route_id=? AND state='staging'", [$id->toString()]);
            $this->collectUnreferenced($id);
        });
    }

    public function collectUnreferenced(RouteId $id): int
    {
        return $this->connection->transactional(function () use ($id): int {
            $this->repository->lockIdentity($id);
            if (false !== $this->connection->fetchOne('SELECT 1 FROM routes WHERE id=?', [$id->toString()])) {
                return 0;
            }
            $removed = $this->connection->executeStatement("UPDATE route_revisions r SET state='purged',properties=NULL,summary=NULL,created_at=NULL,reported_distance_m=NULL WHERE route_id=? AND state='published' AND NOT EXISTS(SELECT 1 FROM route_revision_references ref WHERE ref.revision_id=r.id)", [$id->toString()]);
            foreach (['cues', 'waypoints', 'via_points', 'geometry_points'] as $table) {
                $this->connection->executeStatement('DELETE FROM route_'.$table." p USING route_revisions r WHERE p.revision_id=r.id AND r.route_id=? AND r.state='purged'", [$id->toString()]);
            }

            return $removed;
        });
    }

    public function cleanupStaging(\DateTimeImmutable $olderThan, int $limit = 100): int
    {
        if ($limit < 1 || $limit > 1000) {
            throw new \InvalidArgumentException('Invalid cleanup limit.');
        }
        $cutoff = $olderThan->format('Y-m-d H:i:s.uP');
        $rows = $this->connection->fetchAllAssociative("SELECT id,route_id FROM route_revisions WHERE state='staging' AND touched_at < ? ORDER BY touched_at,id LIMIT ".$limit, [$cutoff]);
        $removed = 0;
        foreach ($rows as $row) {
            try {
                $removed += $this->connection->transactional(function () use ($row, $cutoff): int {
                    $this->repository->lockIdentity(RouteId::fromString($row['route_id']));
                    $id = $this->connection->fetchOne("SELECT id FROM route_revisions WHERE id=? AND state='staging' AND touched_at < ? FOR UPDATE SKIP LOCKED", [$row['id'], $cutoff]);

                    return false === $id ? 0 : $this->connection->delete('route_revisions', ['id' => $id]);
                });
            } catch (RouteNotFound) { /* Another cleanup removed the empty identity. */
            }
        }
        $this->cleanupEmptyIdentities($limit);

        return $removed;
    }

    public function cleanupEmptyIdentities(int $limit = 100): int
    {
        if ($limit < 1 || $limit > 1000) {
            throw new \InvalidArgumentException('Invalid cleanup limit.');
        }
        $ids = $this->connection->fetchFirstColumn('SELECT id FROM route_identities i WHERE NOT EXISTS(SELECT 1 FROM route_revisions r WHERE r.route_id=i.id) ORDER BY id LIMIT '.$limit);
        $removed = 0;
        foreach ($ids as $id) {
            try {
                $removed += $this->connection->transactional(function () use ($id): int {
                    $this->repository->lockIdentity(RouteId::fromString($id));

                    return $this->connection->executeStatement('DELETE FROM route_identities i WHERE id=? AND NOT EXISTS(SELECT 1 FROM route_revisions r WHERE r.route_id=i.id)', [$id]);
                });
            } catch (ForeignKeyConstraintViolationException|RouteNotFound) { /* Retained or already removed by another cleanup. */
            }
        }

        return $removed;
    }
}
