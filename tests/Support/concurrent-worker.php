<?php

declare(strict_types=1);
/** @var list<string> $arguments */
$arguments = $_SERVER['argv'];
require $arguments[1];
require_once __DIR__.'/StorageTestCase.php';
use Youmad\Endurance\Foundation\Clock\FrozenClock;
use Youmad\Endurance\Foundation\ValueObject\Coordinate;
use Youmad\Endurance\Geo\PathGeometry;
use Youmad\Endurance\Geo\PathPoint;
use Youmad\Endurance\Geo\PathPosition;
use Youmad\Endurance\Route\Application\RevisionContent;
use Youmad\Endurance\Route\Application\UseCase\EditRouteMetadata;
use Youmad\Endurance\Route\Application\UseCase\PublishRoute;
use Youmad\Endurance\Route\ValueObject\GeometryCoverage;
use Youmad\Endurance\Route\ValueObject\RevisionProperties;
use Youmad\Endurance\Route\ValueObject\RouteId;
use Youmad\Endurance\Route\ValueObject\RouteMetadata;
use Youmad\Endurance\Route\ValueObject\RouteRevisionId;
use Youmad\Endurance\RoutePostgresql\DoctrineDbalRouteMaintenance;
use Youmad\Endurance\RoutePostgresql\DoctrineDbalRouteRepository;
use Youmad\Endurance\RoutePostgresql\DoctrineDbalRouteRetention;
use Youmad\Endurance\RoutePostgresql\Tests\Support\StorageTestCase;

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
