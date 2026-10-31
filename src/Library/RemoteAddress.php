<?php

declare(strict_types=1);

namespace Kipchak\Middleware\FeatureFlags\Library;

use Kipchak\Identity\Resolver;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The address the request came from, as the web server saw it (REMOTE_ADDR). Behind a proxy or load balancer that
 * is the proxy's address: use a header source the proxy sets instead.
 */
final readonly class RemoteAddress implements Resolver
{
    public function resolve(ServerRequestInterface $request): ?string
    {
        $address = $request->getServerParams()['REMOTE_ADDR'] ?? null;

        return is_string($address) && $address !== '' ? $address : null;
    }
}
