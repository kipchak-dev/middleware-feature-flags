# Feature Flag Middleware for Kipchak

Gates routes on feature flags, and gives controllers the flags for the request they are handling. The flags come
from the [Feature Flags driver](https://kipchak.dev/docs/driver-feature-flags), so they can be managed in
[Unleash](https://www.getunleash.io), [Flagsmith](https://www.flagsmith.com) or [GrowthBook](https://www.growthbook.io).

* **Rollouts per API key**: the consumer (an API key's client, a token's `client_id`) is the flag's user id, so a
  percentage rollout keeps each consumer in or out of it on every request and every worker.
* **Gates**: a route, group or path pattern answers 404 (or 403) unless its flag is on; a kill switch works the
  other way round; a gate can also require a variant.
* **Targeting attributes**: token claims, headers, request attributes and fixed values become context attributes,
  for rules such as "plan is pro" or "app version at least 42".
* **One answer per request**: each flag is evaluated once per request, so a gate and the controller behind it
  always agree.
* **Never takes the API down**: when the flag service is down, the last flags fetched keep being used; before any
  have been fetched, gates deny, allow or answer 503, as configured.

#### Composer Package

`kipchak/middleware-feature-flags`

## Installation

```bash
composer require kipchak/middleware-feature-flags
```

Set up the [Feature Flags driver](https://kipchak.dev/docs/driver-feature-flags) first: install the SDK for your
flag service, configure a connection in `config/kipchak.feature-flags.php`, and initialise the driver in
`drivers/drivers.php`.

Copy [`sample.config.php`](sample.config.php) to `config/kipchak.feature-flags.middleware.php` and add the
middleware in `middlewares/middlewares.php` **before** the authentication middlewares, so it runs after them and
knows who the consumer is:

```php
use Kipchak\Middleware\FeatureFlags\FeatureFlags;

FeatureFlags::initialise($app);
// Then the authentication middlewares.
```

## Gates

Attach a gate to a route or group:

```php
use Kipchak\Middleware\FeatureFlags\Handlers\Gate;

$app->post('/v2/checkout', [Checkout::class, 'create'])->add(new Gate('new-checkout'));

// A kill switch: the group answers 403 while 'orders-frozen' is on.
$app->group('/v1/orders', function ($group) { /* ... */ })->add(new Gate('orders-frozen', enabled: false, status: 403));

// Only for consumers given the 'vector' variant of an experiment.
$app->get('/v2/search', [Search::class, 'vector'])->add(new Gate('search-engine', variants: ['vector']));
```

Or gate paths in config, for every request:

```php
'gates' => [
    ['path' => '/v2/checkout*', 'flag' => 'new-checkout'],
    ['path' => '/v1/orders*', 'methods' => ['POST', 'PUT', 'PATCH'], 'flag' => 'orders-frozen', 'enabled' => false, 'status' => 403],
],
```

A path is exact, or a pattern where `*` matches anything, slashes included. Every gate that matches a request must
let it through.

| Gate option | Default | Meaning |
|---|---|---|
| `flag` | required | The flag's name in the flag service |
| `enabled` | `true` | Whether the flag must be on (`true`) or off (`false`, for a kill switch) |
| `variants` | none | The request's consumer must also have been given one of these variants |
| `status` | `deny_status` | `404` or `403` when the flag does not let the request through |
| `on_error` | `on_error` | `closed`, `open` or `unavailable` when the flag cannot be evaluated |
| `methods` | every method | Config gates only: the methods it applies to |
| `connection` | `connection` | `Gate` only: another connection in `kipchak.feature-flags` |

A request that is denied gets the standard Kipchak error envelope:

| Status | `status` | `error` | When |
|---|---|---|---|
| 404 | `RESOURCE_NOT_FOUND` | `not_found` | The flag is off, and the gate's status is 404: the route looks as if it does not exist |
| 403 | `INSUFFICIENT_PRIVILEGES` | `feature_not_enabled` | The flag is off, and the gate's status is 403 |
| 503 | `SERVER_ERROR` | `feature_flags_unavailable` | The flag cannot be evaluated, and `on_error` is `unavailable`; with `Retry-After: 5` |

The `status` comes from kipchak/core's `ErrorStatus`, as kipchak/middleware-error writes it, so a gated 404 says what a
missing route says. **Upgrading to 1.5:** it requires kipchak/core 3.18, and these statuses replace `NOT FOUND`,
`FORBIDDEN` and `SERVICE UNAVAILABLE`. The `error` codes are unchanged.

A flag the service does not have counts as off, and the first time a gate meets one the middleware logs a warning.
Unleash does not report unknown flags, so there they are simply off.

## In controllers

```php
use Kipchak\Middleware\FeatureFlags\FeatureFlags;

$flags = FeatureFlags::of($request);

if ($flags->isEnabled('new-checkout')) {
    // ...
}
$limit = $flags->value('rate-limit', 100);
$engine = $flags->variant('search-engine');
```

| Method | Returns |
|---|---|
| `isEnabled($flag, $default = false)` | Whether the flag is on. `$default` for a flag the service does not have, or when it cannot be reached. |
| `value($flag, $default = null)` | The flag's value, or `$default` |
| `variant($flag)` | The variant the consumer was given, or `null` |
| `evaluate($flag)` | A `Flag` with `enabled`, `value`, `variant` and `source` |
| `evaluated()` | Every flag evaluated for this request so far, by name, preloaded ones included |
| `context()` | The request's `Context` |
| `client()` | The connection, to evaluate for a different context |

Each flag is evaluated once per request and the answer kept, so the controller sees what the gate saw, even for a
rollout that is not sticky. The flags are also on the request as the attribute `kipchak.feature-flags`, and the
`Context` as `kipchak.feature-flags.context`. List flags under `preload` to have them evaluated on every request.

## The context

Each request's `Context` is built from it:

| Context | Comes from |
|---|---|
| `userId` | The consumer, from `consumer` or the shared `config/kipchak.identity.php`. With `'anonymous' => 'ip'`, requests without one use a hash of their address. |
| `attributes` | `attributes`, plus the tenant from Kipchak Identity as `tenant` (`tenant_attribute`) |
| `ipAddress` | `ip_address`: the connection's address (`remote_addr`), or sources such as a header your proxy sets |
| `sessionId` | `session`, when set |

```php
'attributes' => [
    'plan' => ['source' => 'token', 'claim' => 'plan'],
    'beta' => ['source' => 'token', 'claim' => 'features.beta'],
    'country' => [['source' => 'header', 'name' => 'CF-IPCountry'], ['source' => 'header', 'name' => 'X-Country']],
    'app_version' => ['source' => 'header', 'name' => 'X-App-Version', 'cast' => 'int'],
    'tier' => ['source' => 'api_key', 'map' => ['acme' => 'enterprise']],
    'region' => 'eu-west',
],
```

Sources are written as in [Consumer Identity](https://kipchak.dev/docs/identity): `token`, `attribute`, `header`,
`api_key` and `callable`, each with an optional `map`. Token claims and request attributes keep their type, so a
boolean claim stays a boolean and a list of roles stays a list. Headers and the other sources give strings, which
`cast` converts to `int`, `float`, `bool` or `list` (split on commas). An attribute with no value is left out.

### Sticky rollouts

A percentage rollout is sticky when the flag service hashes the user id, and the consumer is the user id:

* **Unleash**: a gradual rollout with stickiness `default` or `userId`.
* **Flagsmith**: a segment with a percentage split condition; Flagsmith hashes the identity.
* **GrowthBook**: a percentage rollout or experiment with the hash attribute `id`.

The API key's client name is the user id, never the key itself. Flagsmith's remote evaluation stores each identity
and its attributes in Flagsmith; use a server-side key for local evaluation, as the driver recommends.

## When the flag service is down

The driver keeps using the last flags it fetched, for as long as the service is down, so a running API keeps its
gates. Before anything has been fetched, such as in a worker that starts while the service is down, a flag cannot be
evaluated, and `on_error` decides:

| `on_error` | The gate |
|---|---|
| `closed` (default) | Denies, as if the flag were off |
| `open` | Lets the request through, as if the flag were on |
| `unavailable` | Answers 503 with `Retry-After: 5` |

Give the connection a `bootstrap_file` to have flags from the start. The middleware logs what its gates did at most
once a minute; the driver logs why the service could not be reached.

## With the Response Cache

A cached response is served before route middleware runs, so a `Gate` on a route does not stop a shared cache from
serving its response to a consumer the flag would deny. For gated routes that are cached, gate them in this
middleware's config (which runs before the cache when `FeatureFlags::initialise()` comes after
`ResponseCache::initialise()`), or cache them per consumer (`'scope' => 'consumer'`).

## Configuration

| Key | Default | Meaning |
|---|---|---|
| `enabled` | `true` | Put the flags on every request and apply the gates in config |
| `connection` | `default` | The connection in `kipchak.feature-flags` |
| `consumer`, `tenant` | from `kipchak.identity` | Who a request is from, and its tenant |
| `attributes` | none | Context attributes and where they come from |
| `tenant_attribute` | `tenant` | The attribute the tenant goes in; `null` to leave it out |
| `anonymous` | `none` | `none`, or `ip` for a hash of the address as the user id of requests without a consumer |
| `ip_address` | `remote_addr` | `remote_addr`, `null`, or a list of sources |
| `session` | none | Sources for the session id |
| `preload` | none | Flags evaluated on every request |
| `deny_status` | `404` | `404` or `403` |
| `on_error` | `closed` | `closed`, `open` or `unavailable` |
| `gates` | none | Gates by path |

## FrankenPHP worker mode

Settings are read once per worker, and the flag definitions stay in the driver between requests. The flags and the
context belong to one request and are dropped with it.

## Testing

```bash
composer install
vendor/bin/phpunit --testsuite Unit

# Integration tests run against real Unleash, Flagsmith and GrowthBook servers in Docker. The script starts them,
# creates the flags the tests expect and writes the keys to tests/Integration/.keys.env:
tests/Integration/start-services.sh
vendor/bin/phpunit

docker compose -f tests/Integration/compose.yaml down -v
```

## Licence

MIT
