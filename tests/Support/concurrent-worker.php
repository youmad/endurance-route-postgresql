<?php

declare(strict_types=1);
/** @var list<string> $arguments */
$arguments = $_SERVER['argv'];
require $arguments[1];
require_once __DIR__.'/StorageTestCase.php';
use Endurance\Foundation\Clock\FrozenClock;
use Endurance\Foundation\ValueObject\Coordinate;
use Endurance\Geo\PathGeometry;
use Endurance\Geo\PathPoint;
use Endurance\Geo\PathPosition;
use Endurance\Route\Application\RevisionContent;
use Endurance\Route\Application\UseCase\EditRouteMetadata;
use Endurance\Route\Application\UseCase\PublishRoute;
use Endurance\Route\ValueObject\GeometryCoverage;
use Endurance\Route\ValueObject\RevisionProperties;
use Endurance\Route\ValueObject\RouteId;
use Endurance\Route\ValueObject\RouteMetadata;
use Endurance\Route\ValueObject\RouteRevisionId;
use Endurance\RoutePostgresql\DoctrineDbalRouteMaintenance;
use Endurance\RoutePostgresql\DoctrineDbalRouteRepository;
use Endurance\RoutePostgresql\DoctrineDbalRouteRetention;
use Endurance\RoutePostgresql\Tests\Support\StorageTestCase;

$payload = json_decode(file_get_contents($arguments[2]), true, 512, JSON_THROW_ON_ERROR);
$db = StorageTestCase::connect();
$db->executeStatement("SELECT set_config('application_name',?,false)", [$payload['tag']]);
$db->executeStatement("SET lock_timeout='5s'");
$storage = new DoctrineDbalRouteRepository($db, 2, 2);
$id = RouteId::fromString($payload['id']);
$snapshot = $storage->get($id);
echo "READY\n";
fflush(STDOUT);
try {
    switch ($payload['operation']) {
        case 'metadata': (new EditRouteMetadata($storage, new FrozenClock($snapshot->updatedAt)))->edit($id, $snapshot->expectation(), new RouteMetadata('worker'));
            break;
        case 'revise': (new PublishRoute($storage, new FrozenClock($snapshot->updatedAt)))->revise($id, $snapshot->expectation(), RouteRevisionId::generate(), new RevisionProperties(GeometryCoverage::Complete), new RevisionContent(new PathGeometry([new PathPoint(new PathPosition(0, 0), new Coordinate(0, 0))])));
            break;
        case 'pin': (new DoctrineDbalRouteRetention($db, $storage))->pin($snapshot->currentRevisionId, 'worker-reference');
            break;
        case 'purge': (new DoctrineDbalRouteMaintenance($db, $storage))->purgeCard($id);
            break;
        default: throw new LogicException('Unknown worker operation.');
    }
    echo json_encode(['result' => 'ok'], JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    echo json_encode(['result' => $e::class, 'message' => $e->getMessage()], JSON_THROW_ON_ERROR);
}
$db->close();
