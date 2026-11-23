<?php

declare(strict_types=1);

namespace Endurance\RoutePostgresql\Tests\Integration;

use Doctrine\DBAL\Exception\DriverException;
use Endurance\Foundation\ValueObject\Coordinate;
use Endurance\Geo\Elevation;
use Endurance\Geo\ElevationOrigin;
use Endurance\Geo\PathGeometry;
use Endurance\Geo\PathPoint;
use Endurance\Geo\PathPosition;
use Endurance\Geo\ViaPoint;
use Endurance\Geo\ViaPoints;
use Endurance\Route\Application\RevisionContent;
use Endurance\Route\Application\UseCase\EditRouteMetadata;
use Endurance\Route\Application\UseCase\PublishRoute;
use Endurance\Route\Exception\RouteConflict;
use Endurance\Route\Exception\RouteRevisionAlreadyExists;
use Endurance\Route\Exception\RouteRevisionNotFound;
use Endurance\Route\ValueObject\GeometryAnchor;
use Endurance\Route\ValueObject\GeometryCoverage;
use Endurance\Route\ValueObject\NavigationCue;
use Endurance\Route\ValueObject\ReportedDistance;
use Endurance\Route\ValueObject\RevisionProperties;
use Endurance\Route\ValueObject\RevisionProvenance;
use Endurance\Route\ValueObject\RouteId;
use Endurance\Route\ValueObject\RouteMetadata;
use Endurance\Route\ValueObject\RouteRevisionId;
use Endurance\Route\ValueObject\RouteWaypoint;
use Endurance\Route\ValueObject\ViaPointAnchor;
use Endurance\RoutePostgresql\DoctrineDbalRouteMaintenance;
use Endurance\RoutePostgresql\DoctrineDbalRouteRepository;
use Endurance\RoutePostgresql\DoctrineDbalRouteRetention;
use Endurance\RoutePostgresql\Tests\Support\StorageTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class RouteStorageTest extends StorageTestCase
{
    /** @param class-string<DoctrineDbalRouteMaintenance|DoctrineDbalRouteRetention> $collaborator */
    #[DataProvider('transactionalCollaborators')]
    public function testStorageCollaboratorsRejectAnotherConnectionEvenWhenBothHaveATransaction(string $collaborator): void
    {
        $other = self::connect();
        $this->db->beginTransaction();
        try {
            $other->beginTransaction();
            $this->expectException(\LogicException::class);
            $this->expectExceptionMessage('Route repository uses another DBAL connection.');
            new $collaborator($other, $this->storage);
        } finally {
            $other->close();
            $this->db->rollBack();
        }
    }

    /** @return iterable<string, array{class-string<DoctrineDbalRouteMaintenance|DoctrineDbalRouteRetention>}> */
    public static function transactionalCollaborators(): iterable
    {
        yield 'retention' => [DoctrineDbalRouteRetention::class];
        yield 'maintenance' => [DoctrineDbalRouteMaintenance::class];
    }

    public function testViaOnlyRouteDoesNotInventConnectingGeometry(): void
    {
        $via = [new ViaPoint(0, new Coordinate(50, 65)), new ViaPoint(1, new Coordinate(51, 66), new Elevation(0.0))];
        $route = (new PublishRoute($this->storage, $this->clock))->create(RouteId::generate(), RouteRevisionId::generate(), new RouteMetadata(), new RevisionProperties(), new RevisionContent(viaPoints: new ViaPoints($via)));
        self::assertSame([], iterator_to_array($this->storage->geometry($route->currentRevisionId)));
        self::assertEquals($via, iterator_to_array($this->storage->viaPoints($route->currentRevisionId)));
        self::assertSame(GeometryCoverage::None, $this->storage->getRevision($route->currentRevisionId)->properties->geometryCoverage);
        self::assertNull($this->storage->getRevision($route->currentRevisionId)->properties->reportedDistance);
    }

    public function testFailedReplacementPreservesThePreviouslyPublishedCardAndPath(): void
    {
        $old = $this->create();
        $this->db->executeStatement("CREATE FUNCTION fail_route_update_test() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'injected replacement failure'; END $$");
        $this->db->executeStatement('CREATE TRIGGER fail_route_update_test BEFORE UPDATE ON routes FOR EACH ROW EXECUTE FUNCTION fail_route_update_test()');
        try {
            try {
                (new PublishRoute($this->storage, $this->clock))->revise($old->id, $old->expectation(), RouteRevisionId::generate(), $this->properties(), $this->content(2));
                self::fail('Expected failure.');
            } catch (DriverException $e) {
                self::assertStringContainsString('injected replacement failure', $e->getMessage());
            }
        } finally {
            $this->db->executeStatement('DROP TRIGGER fail_route_update_test ON routes');
            $this->db->executeStatement('DROP FUNCTION fail_route_update_test()');
        }
        self::assertEquals($old, $this->storage->get($old->id));
        self::assertCount(5, iterator_to_array($this->storage->geometry($old->currentRevisionId)));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT count(*) FROM route_revisions'));
    }

    public function testDiscardAfterPublicationAcknowledgementCannotDeleteCommittedData(): void
    {
        $routeId = RouteId::generate();
        $id = RouteRevisionId::generate();
        $writer = $this->storage->beginRevision($routeId, $id);
        $summary = (new \Endurance\Route\Application\RouteContentValidator())->write($id, $this->content(), $writer);
        $revision = new \Endurance\Route\Entity\RouteRevision($id, $routeId, $this->clock->now(), $this->properties(), $summary);
        $route = \Endurance\Route\Entity\Route::create($revision, new RouteMetadata());
        $writer->publishNew($route, $revision);
        $writer->discard();
        $writer->discard();
        self::assertEquals($route, $this->storage->get($routeId));
        self::assertCount(5, iterator_to_array($this->storage->geometry($id)));
        $this->expectException(\LogicException::class);
        $writer->appendGeometry($this->point(5));
    }

    public function testAllStreamsDescriptorsAndRepeatedPassAnchorsSurviveReconnection(): void
    {
        $id = RouteRevisionId::generate();
        $point = $this->point(0);
        $height = new Elevation(123.45678901234567, ElevationOrigin::Reported, 'barometer', 'EGM96');
        $points = [$point, $this->point(1), new PathPoint(new PathPosition(1, 0), $point->coordinate, $height), $this->point(1, 1), $this->point(2, 1)];
        $via = [new ViaPoint(0, $point->coordinate, $height), new ViaPoint(1, $point->coordinate)];
        $waypoint = new RouteWaypoint(0, $point->coordinate, 'Second pass', $height, new GeometryAnchor($id, new PathPosition(1, 0)));
        $cue = new NavigationCue(0, 'Поворот', null, new ViaPointAnchor($id, 1));
        $properties = new RevisionProperties(GeometryCoverage::Partial, new ReportedDistance(9876.543210987654), new RevisionProvenance('import', 'opaque-source', 'r1', 'parser-1'));
        $route = (new PublishRoute($this->storage, $this->clock))->create(RouteId::generate(), $id, new RouteMetadata('Петля'), $properties, new RevisionContent(new PathGeometry($points), new ViaPoints($via), [$waypoint], [$cue]));
        $other = self::connect();
        try {
            $reader = new DoctrineDbalRouteRepository($other, 2, 1);
            self::assertEquals($route, $reader->get($route->id));
            self::assertEquals($points, iterator_to_array($reader->geometry($id)));
            self::assertEquals($via, iterator_to_array($reader->viaPoints($id)));
            self::assertEquals([$waypoint], iterator_to_array($reader->waypoints($id)));
            self::assertEquals([$cue], iterator_to_array($reader->cues($id)));
            self::assertEquals($properties, $reader->getRevision($id)->properties);
            self::assertSame(2, $reader->getRevision($id)->summary->geometryPartCount);
        } finally {
            $other->close();
        }
    }

    public function testFloatingPointBitsSurviveLowPhpAndDatabaseDisplayPrecision(): void
    {
        $oldPrecision = ini_set('precision', '3');
        $oldSerialize = ini_set('serialize_precision', '3');
        $this->db->executeStatement('SET extra_float_digits=-3');
        try {
            $lat = 50.123456789012345;
            $lon = -0.0;
            $height = 123.45678901234567;
            $distance = 9876.543210987654;
            $content = new RevisionContent(new PathGeometry([new PathPoint(new PathPosition(0, 0), new Coordinate($lat, $lon), new Elevation($height))]));
            $route = (new PublishRoute($this->storage, $this->clock))->create(RouteId::generate(), RouteRevisionId::generate(), new RouteMetadata(), new RevisionProperties(GeometryCoverage::Complete, new ReportedDistance($distance)), $content);
            $decoded = iterator_to_array($this->storage->geometry($route->currentRevisionId))[0];
            self::assertSame(pack('E', $lat), pack('E', $decoded->coordinate->latitude));
            self::assertSame(pack('E', $lon), pack('E', $decoded->coordinate->longitude));
            self::assertNotNull($decoded->elevation);
            self::assertSame(pack('E', $height), pack('E', $decoded->elevation->metres));
            $reported = $this->storage->getRevision($route->currentRevisionId)->properties->reportedDistance;
            self::assertNotNull($reported);
            self::assertSame(pack('E', $distance), pack('E', $reported->metres));
        } finally {
            ini_set('precision', $oldPrecision);
            ini_set('serialize_precision', $oldSerialize);
            $this->db->executeStatement('RESET extra_float_digits');
        }
    }

    public function testFlushedStagingIsInvisibleAndLateSourceFailureCleansIt(): void
    {
        $id = RouteRevisionId::generate();
        $points = (function () use ($id): \Generator {
            yield $this->point(0);
            yield $this->point(1);
            self::assertSame(2, (int) $this->db->fetchOne('SELECT count(*) FROM route_geometry_points'));
            self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM routes'));
            try {
                $this->storage->getRevision($id);
                self::fail('Staging leaked.');
            } catch (RouteRevisionNotFound) {
            }
            throw new \RuntimeException('Malformed source ending');
        })();
        try {
            (new PublishRoute($this->storage, $this->clock))->create(RouteId::generate(), $id, new RouteMetadata(), $this->properties(), new RevisionContent(new PathGeometry($points)));
            self::fail('Expected source failure.');
        } catch (\RuntimeException $e) {
            self::assertSame('Malformed source ending', $e->getMessage());
        }
        self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM route_revisions'));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM route_geometry_points'));
    }

    public function testMetadataEditsPreserveRevisionAndStaleRevisionReplacementFails(): void
    {
        $old = $this->create();
        $edited = (new EditRouteMetadata($this->storage, $this->clock))->edit($old->id, $old->expectation(), new RouteMetadata('New name'));
        self::assertEquals($old->currentRevisionId, $edited->currentRevisionId);
        self::assertSame(2, $edited->version->value);
        try {
            (new PublishRoute($this->storage, $this->clock))->revise($old->id, $old->expectation(), RouteRevisionId::generate(), $this->properties(), $this->content());
            self::fail('Stale update accepted.');
        } catch (RouteConflict) {
        }
        self::assertSame(1, (int) $this->db->fetchOne('SELECT count(*) FROM route_revisions'));
    }

    public function testPublishedRowsCannotBeMutatedWithSql(): void
    {
        $this->create();
        foreach (['UPDATE route_geometry_points SET latitude=0', 'DELETE FROM route_geometry_points', "UPDATE route_revisions SET properties='{}'::jsonb", 'DELETE FROM route_revisions'] as $sql) {
            try {
                $this->db->executeStatement($sql);
                self::fail('Published content mutated.');
            } catch (DriverException $e) {
                self::assertSame('23514', $e->getSQLState());
            }
        }
    }

    public function testRetainedHistoricalRevisionSurvivesCardPurgeAndFinalUnpinErasesOnlyPayload(): void
    {
        $old = $this->create();
        $pin = new DoctrineDbalRouteRetention($this->db, $this->storage);
        $pin->pin($old->currentRevisionId, 'activity:opaque:planned');
        $pin->pin($old->currentRevisionId, 'activity:opaque:planned');
        $new = (new PublishRoute($this->storage, $this->clock))->revise($old->id, $old->expectation(), RouteRevisionId::generate(), $this->properties(), $this->content(3));
        $this->maintenance()->purgeCard($old->id);
        self::assertCount(5, iterator_to_array($this->storage->geometry($old->currentRevisionId)));
        try {
            $this->storage->getRevision($new->currentRevisionId);
            self::fail('Unretained revision survived.');
        } catch (RouteRevisionNotFound) {
        }
        $pin->unpin($old->currentRevisionId, 'activity:opaque:planned');
        $pin->unpin($old->currentRevisionId, 'activity:opaque:planned');
        self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM route_geometry_points'));
        self::assertSame(2, (int) $this->db->fetchOne("SELECT count(*) FROM route_revisions WHERE state='purged'"));
        $this->expectException(RouteRevisionAlreadyExists::class);
        $this->storage->beginRevision($old->id, $old->currentRevisionId);
    }

    public function testStagingCleanupFencesAbandonedWritersAndKeepsFreshReservations(): void
    {
        $oldId = RouteRevisionId::generate();
        $writer = $this->storage->beginRevision(RouteId::generate(), $oldId);
        $writer->appendGeometry($this->point(0));
        $writer->appendGeometry($this->point(1));
        $freshId = RouteRevisionId::generate();
        $fresh = $this->storage->beginRevision(RouteId::generate(), $freshId);
        $this->db->executeStatement("UPDATE route_revisions SET touched_at=clock_timestamp()-interval '2 days' WHERE id=?", [$oldId->toString()]);
        self::assertSame(1, $this->maintenance()->cleanupStaging(new \DateTimeImmutable('-1 day')));
        self::assertSame($freshId->toString(), $this->db->fetchOne('SELECT id FROM route_revisions'));
        $fresh->discard();
        $writer->appendGeometry($this->point(2));
        $this->expectException(\LogicException::class);
        $writer->appendGeometry($this->point(3));
    }

    public function testOpenIteratorStaysPinnedAcrossCurrentRevisionReplacement(): void
    {
        $old = $this->create();
        $iterator = $this->storage->geometry($old->currentRevisionId)->getIterator();
        $iterator->rewind();
        (new PublishRoute($this->storage, $this->clock))->revise($old->id, $old->expectation(), RouteRevisionId::generate(), $this->properties(), $this->content(1));
        $count = 0;
        while ($iterator->valid()) {
            ++$count;
            $iterator->next();
        }self::assertSame(5, $count);
    }

    public function testConcurrentPurgeCannotSilentlyTruncateAnOpenIterator(): void
    {
        $route = $this->create();
        $iterator = $this->storage->geometry($route->currentRevisionId)->getIterator();
        $iterator->rewind();
        $this->maintenance()->purgeCard($route->id);
        $this->expectException(RouteRevisionNotFound::class);
        while ($iterator->valid()) {
            $iterator->next();
        }
    }

    public function testDatabaseFailureAfterSealingRollsBackPublication(): void
    {
        $this->db->executeStatement("CREATE FUNCTION fail_route_insert_test() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'injected card failure'; END $$");
        $this->db->executeStatement('CREATE TRIGGER fail_route_test BEFORE INSERT ON routes FOR EACH ROW EXECUTE FUNCTION fail_route_insert_test()');
        try {
            try {
                $this->create();
                self::fail('Expected failure.');
            } catch (DriverException $e) {
                self::assertStringContainsString('injected card failure', $e->getMessage());
            }
        } finally {
            $this->db->executeStatement('DROP TRIGGER fail_route_test ON routes');
            $this->db->executeStatement('DROP FUNCTION fail_route_insert_test()');
        }
        self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM routes'));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM route_revisions'));
    }

    public function testCleanupSkipsLockedStagingAndRechecksItsHeartbeat(): void
    {
        $id = RouteRevisionId::generate();
        $writer = $this->storage->beginRevision(RouteId::generate(), $id);
        $this->db->executeStatement("UPDATE route_revisions SET touched_at=clock_timestamp()-interval '2 days' WHERE id=?", [$id->toString()]);
        $other = self::connect();
        $other->beginTransaction();
        $other->executeStatement('SELECT id FROM route_revisions WHERE id=? FOR UPDATE', [$id->toString()]);
        try {
            self::assertSame(0, $this->maintenance()->cleanupStaging(new \DateTimeImmutable('-1 day')));
            $other->executeStatement('UPDATE route_revisions SET touched_at=clock_timestamp() WHERE id=?', [$id->toString()]);
            $other->commit();
        } finally {
            if ($other->isTransactionActive()) {
                $other->rollBack();
            }$other->close();
        }
        self::assertSame(0, $this->maintenance()->cleanupStaging(new \DateTimeImmutable('-1 day')));
        $writer->discard();
    }

    public function testLargeRouteUsesBoundedMemoryForWriteAndRead(): void
    {
        $storage = new DoctrineDbalRouteRepository($this->db, 256, 512);
        gc_collect_cycles();
        $baseline = memory_get_usage(false);
        memory_reset_peak_usage();
        $start = microtime(true);
        $route = (new PublishRoute($storage, $this->clock))->create(RouteId::generate(), RouteRevisionId::generate(), new RouteMetadata('100001 points'), $this->properties(), $this->content(100001));
        $count = 0;
        foreach ($storage->geometry($route->currentRevisionId) as $point) {
            if ($point->position->pointIndex !== $count) {
                self::fail(sprintf('Geometry point out of order at offset %d: got index %d.', $count, $point->position->pointIndex));
            }
            ++$count;
        }
        $growth = memory_get_peak_usage(false) - $baseline;
        self::assertSame(100001, $count);
        self::assertLessThanOrEqual(8 * 1024 * 1024, $growth);
        fwrite(STDERR, sprintf("\nRoute 100001 points: %.2fs, peak growth %.2f MiB\n", microtime(true) - $start, $growth / 1024 / 1024));
    }
}
