<?php

declare(strict_types=1);

namespace Endurance\RoutePostgresql\Tests\Integration;

use Endurance\Route\Application\UseCase\EditRouteMetadata;
use Endurance\Route\Entity\Route;
use Endurance\Route\Exception\RouteConflict;
use Endurance\Route\Exception\RouteNotFound;
use Endurance\Route\Exception\RouteRevisionNotFound;
use Endurance\Route\ValueObject\RouteMetadata;
use Endurance\RoutePostgresql\DoctrineDbalRouteRetention;
use Endurance\RoutePostgresql\Tests\Support\StorageTestCase;

final class RouteConcurrencyTest extends StorageTestCase
{
    public function testTwoMetadataWritersHaveExactlyOneWinner(): void
    {
        $route = $this->create();
        $result = $this->race($route, 'metadata', function () use ($route): void {(new EditRouteMetadata($this->storage, $this->clock))->edit($route->id, $route->expectation(), new RouteMetadata('winner')); });
        self::assertSame(RouteConflict::class, $result['result']);
        self::assertSame('winner', $this->storage->get($route->id)->metadata->name);
        self::assertSame(2, $this->storage->get($route->id)->version->value);
    }

    public function testReplacementLosesToConcurrentPurgeWithoutPartialPublication(): void
    {
        $route = $this->create();
        $result = $this->race($route, 'revise', function () use ($route): void {$this->maintenance()->purgeCard($route->id); });
        self::assertSame(RouteNotFound::class, $result['result']);
        self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM routes'));
        self::assertSame(0, (int) $this->db->fetchOne("SELECT count(*) FROM route_revisions WHERE state <> 'purged'"));
    }

    public function testPinLosingToPurgeCannotResurrectContent(): void
    {
        $route = $this->create();
        $result = $this->race($route, 'pin', function () use ($route): void {$this->maintenance()->purgeCard($route->id); });
        self::assertSame(RouteRevisionNotFound::class, $result['result']);
        self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM route_revision_references'));
    }

    public function testPinWinningBeforePurgeRetainsTheExactRevision(): void
    {
        $route = $this->create();
        $result = $this->race($route, 'purge', function () use ($route): void {(new DoctrineDbalRouteRetention($this->db, $this->storage))->pin($route->currentRevisionId, 'winner-reference'); });
        self::assertSame('ok', $result['result']);
        self::assertCount(5, iterator_to_array($this->storage->geometry($route->currentRevisionId)));
    }

    /** @return array<string,string> */
    private function race(Route $route, string $operation, \Closure $winner): array
    {
        $autoload = getenv('ENDURANCE_ROUTE_AUTOLOAD') ?: dirname(__DIR__, 2).'/vendor/autoload.php';
        $tag = 'route-race-'.bin2hex(random_bytes(8));
        $payload = tempnam(sys_get_temp_dir(), 'route-race-');
        file_put_contents($payload, json_encode(['tag' => $tag, 'id' => $route->id->toString(), 'operation' => $operation], JSON_THROW_ON_ERROR));
        $process = null;
        $pipes = [];
        $this->db->beginTransaction();
        $this->storage->lockIdentity($route->id);
        try {
            $process = proc_open([getenv('PHP_BIN') ?: PHP_BINARY, dirname(__DIR__).'/Support/concurrent-worker.php', $autoload, $payload], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            fclose($pipes[0]);
            stream_set_timeout($pipes[1], 8);
            self::assertSame("READY\n", fgets($pipes[1]));
            $deadline = microtime(true) + 4;
            $blocked = false;
            do {
                $blocked = false !== $this->db->fetchOne("SELECT 1 FROM pg_stat_activity WHERE application_name=? AND wait_event_type='Lock'", [$tag]);
                if ($blocked) {
                    break;
                }usleep(10000);
            } while (microtime(true) < $deadline);
            self::assertTrue($blocked, 'Worker must really wait on a different PostgreSQL transaction.');
            $winner();
            $this->db->commit();
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $pipes = [];
            self::assertSame(0, proc_close($process), $errors);
            $process = null;

            return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        } finally {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            if (is_resource($process)) {
                proc_terminate($process);
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }proc_close($process);
            }
            unlink($payload);
        }
    }
}
