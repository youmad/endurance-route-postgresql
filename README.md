# Endurance Route PostgreSQL

Doctrine DBAL storage for Endurance Route. Requires PHP 8.5, PostgreSQL 18,
PDO pgsql and Doctrine DBAL ^4.4.

```bash
composer require youmad/endurance-route-postgresql
```

Register `migrations/` under namespace
`Youmad\Endurance\RoutePostgresql\Migrations` in your migration runner and apply
it before use. The repository does not create schema automatically.

```php
use Youmad\Endurance\Foundation\Clock\SystemClock;
use Youmad\Endurance\Route\Application\UseCase\PublishRoute;
use Youmad\Endurance\RoutePostgresql\DoctrineDbalRouteRepository;

$routes = new DoctrineDbalRouteRepository($connection);
$publisher = new PublishRoute($routes, new SystemClock());
$route = $publisher->create($routeId, $revisionId, $metadata, $properties, $content);
$geometry = $routes->geometry($route->currentRevisionId);
```

- Storage is unscoped: authorize every read and write in your application.
  Revision reads stay pinned across pages and preserve exact floating-point bits.
- Publication checks both expected head fields atomically. Operations sharing an
  application transaction must share the same DBAL connection. Keep long parsing
  outside that transaction; a nested commit is not the outer durable commit.
  Reconcile uncertain outcomes using the original IDs.
- Retention references retain content, not access rights. Acquire multiple Route
  identity locks in sorted ID order.
- Schedule privileged maintenance for abandoned staging, retired content and
  empty identities. Respect active reservation leases; never reuse purged IDs.

Types in `Internal` and elements marked `@internal` are not public API.

License: [MPL-2.0](LICENSE).
