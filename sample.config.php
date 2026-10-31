<?php

/**
 * Copy to config/kipchak.feature-flags.middleware.php.
 *
 * Gates routes on feature flags from the Feature Flags driver (config/kipchak.feature-flags.php), with each
 * consumer as the user id, so a percentage rollout keeps each API key or client in or out of it.
 * See https://kipchak.dev/docs/mw-feature-flags.
 */

return [
    // Put each request's flags on it and apply the gates below (FeatureFlags::initialise). With false, only
    // Kipchak\Middleware\FeatureFlags\Handlers\Gate on routes and groups does anything.
    'enabled' => true,

    // The connection in kipchak.feature-flags to evaluate flags with.
    'connection' => 'default',

    // Who a request is from, as the flag's user id. Leave out to use config/kipchak.identity.php.
    // 'consumer' => [['source' => 'token', 'claim' => 'client_id'], ['source' => 'api_key']],

    // Context attributes for targeting rules. Each is a fixed value, a source, or a list of sources tried in turn:
    // 'token' (a claim of the verified JWT), 'attribute' (a request attribute), 'header', 'api_key' or 'callable',
    // as in kipchak.identity, with an optional 'map' and a 'cast' ('string', 'int', 'float', 'bool' or 'list').
    'attributes' => [
        // 'plan' => ['source' => 'token', 'claim' => 'plan'],
        // 'country' => ['source' => 'header', 'name' => 'CF-IPCountry'],
        // 'app_version' => ['source' => 'header', 'name' => 'X-App-Version', 'cast' => 'int'],
        // 'region' => 'eu-west',
    ],

    // The attribute the tenant (from kipchak.identity) is put in; null to leave it out.
    'tenant_attribute' => 'tenant',

    // Requests without a consumer: 'none' (no user id, so rollouts are not sticky for them) or 'ip' (a hash of
    // their address as the user id).
    'anonymous' => 'none',

    // The client's address: 'remote_addr', null, or a list of sources such as a header your proxy sets.
    'ip_address' => 'remote_addr',
    // 'session' => [['source' => 'header', 'name' => 'X-Session-Id']],

    // Flags to evaluate on every request, ready for controllers through FeatureFlags::of($request)->evaluated().
    'preload' => [],

    'deny_status' => 404,       // 404 (as if the route did not exist) or 403, when a gate's flag is off
    'on_error' => 'closed',     // when a flag cannot be evaluated: 'closed' (deny), 'open' (allow) or 'unavailable' (503)

    // Gates by path ('*' matches anything, slashes included), applied to every request.
    'gates' => [
        // ['path' => '/v2/checkout*', 'flag' => 'new-checkout'],
        // ['path' => '/v1/orders*', 'methods' => ['POST', 'PUT', 'PATCH'], 'flag' => 'orders-frozen', 'enabled' => false, 'status' => 403],
    ],
];
