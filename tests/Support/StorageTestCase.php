<?php

declare(strict_types=1);

namespace Endurance\RoutePostgresql\Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Endurance\Foundation\Clock\FrozenClock;
use Endurance\Foundation\ValueObject\Coordinate;
use Endurance\Foundation\ValueObject\Instant;
use Endurance\Geo\PathGeometry;
use Endurance\Geo\PathPoint;
use Endurance\Geo\PathPosition;
use Endurance\Route\Application\RevisionContent;
use Endurance\Route\Application\UseCase\PublishRoute;
use Endurance\Route\Entity\Route;
use Endurance\Route\ValueObject\GeometryCoverage;
use Endurance\Route\ValueObject\RevisionProperties;
use Endurance\Route\ValueObject\RouteId;
use Endurance\Route\ValueObject\RouteMetadata;
use Endurance\Route\ValueObject\RouteRevisionId;
use Endurance\RoutePostgresql\DoctrineDbalRouteRepository;
use PHPUnit\Framework\TestCase;

abstract class StorageTestCase extends TestCase
{
    protected Connection $db;
    protected DoctrineDbalRouteRepository $storage;
    protected FrozenClock $clock;

    public static function connect(): Connection
    {
        return DriverManager::getConnection(require dirname(__DIR__).'/connection.php');
    }

    protected function setUp(): void
    {
        $this->db = self::connect();
        $this->db->executeStatement('TRUNCATE route_identities CASCADE');
        $this->storage = new DoctrineDbalRouteRepository($this->db, 2, 2);
        $this->clock = new FrozenClock(Instant::fromDateTimeImmutable(new \DateTimeImmutable('2026-10-15T18:49:56.123456+05:00')));
    }

    protected function tearDown(): void
    {
        while ($this->db->isTransactionActive()) {
            $this->db->rollBack();
        }
        $this->db->close();
    }

    protected function content(int $count = 5): RevisionContent
    {
        return new RevisionContent(new PathGeometry((function () use ($count): \Generator { for ($i = 0; $i < $count; ++$i) { yield $this->point($i); } })()));
    }

    protected function point(int $index, int $part = 0): PathPoint
    {
        return new PathPoint(new PathPosition($part, $index), new Coordinate(50.123456789012345, 65.0 + $index / 100000));
    }

    protected function properties(): RevisionProperties
    {
        return new RevisionProperties(GeometryCoverage::Complete);
    }

    protected function create(): Route
    {
        return (new PublishRoute($this->storage, $this->clock))->create(RouteId::generate(), RouteRevisionId::generate(), new RouteMetadata('Route', 'Описание'), $this->properties(), $this->content());
    }

    protected function maintenance(): \Endurance\RoutePostgresql\DoctrineDbalRouteMaintenance
    {
        return new \Endurance\RoutePostgresql\DoctrineDbalRouteMaintenance($this->db, $this->storage);
    }
}
