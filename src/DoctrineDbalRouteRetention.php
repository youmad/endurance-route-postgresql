<?php

declare(strict_types=1);

namespace Endurance\RoutePostgresql;

use Doctrine\DBAL\Connection;
use Endurance\Route\Exception\RouteConflict;
use Endurance\Route\ValueObject\RouteId;
use Endurance\Route\ValueObject\RouteRevisionId;

/** Reference keys retain content; they never grant read access. */
final readonly class DoctrineDbalRouteRetention
{
    public function __construct(private Connection $connection, private DoctrineDbalRouteRepository $repository)
    {
        $repository->assertUsesConnection($connection);
    }

    public function pin(RouteRevisionId $revisionId, string $referenceKey): void
    {
        if ('' === trim($referenceKey)) {
            throw new \InvalidArgumentException('Reference key must not be blank.');
        }
        $this->connection->transactional(function () use ($revisionId, $referenceKey): void {
            $revision = $this->repository->getRevision($revisionId);
            $this->repository->lockIdentity($revision->routeId);
            $this->repository->getRevision($revisionId);
            $this->connection->executeStatement('INSERT INTO route_revision_references(reference_key,revision_id) VALUES (?,?) ON CONFLICT DO NOTHING', [$referenceKey, $revisionId->toString()]);
            if ($this->connection->fetchOne('SELECT revision_id FROM route_revision_references WHERE reference_key=?', [$referenceKey]) !== $revisionId->toString()) {
                throw new RouteConflict('Reference already names another revision.');
            }
        });
    }

    public function unpin(RouteRevisionId $revisionId, string $referenceKey): void
    {
        $this->connection->transactional(function () use ($revisionId, $referenceKey): void {
            $routeId = $this->connection->fetchOne('SELECT route_id FROM route_revisions WHERE id=?', [$revisionId->toString()]);
            if (false === $routeId) {
                return;
            }
            $id = RouteId::fromString($routeId);
            $this->repository->lockIdentity($id);
            $this->connection->delete('route_revision_references', ['reference_key' => $referenceKey, 'revision_id' => $revisionId->toString()]);
            (new DoctrineDbalRouteMaintenance($this->connection, $this->repository))->collectUnreferenced($id);
        });
    }
}
