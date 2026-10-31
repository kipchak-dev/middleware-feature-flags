<?php

declare(strict_types=1);

namespace Kipchak\Middleware\FeatureFlags\Library;

use Kipchak\Driver\FeatureFlags\Request\Context;
use Kipchak\Middleware\FeatureFlags\Config\Settings;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Builds the flag Context for a request from the settings: the consumer as the user id, so a percentage rollout
 * keeps each consumer (an API key's client, a token's client_id) in or out of it on every request and every
 * worker, and the configured attributes.
 *
 * Reads only the request, so one builder serves every request in a FrankenPHP worker.
 */
final readonly class ContextBuilder
{
    public function __construct(private Settings $settings)
    {
    }

    public function build(ServerRequestInterface $request): Context
    {
        $ipAddress = $this->settings->ipAddress?->resolve($request);
        $userId = $this->settings->identity->consumerOf($request);
        if ($userId === null && $this->settings->anonymous === Settings::ANONYMOUS_IP && $ipAddress !== null) {
            // Requests without a consumer are bucketed by address, so their rollouts are sticky too.
            $userId = 'anonymous-' . substr(hash('sha256', $ipAddress), 0, 16);
        }

        $attributes = [];
        if ($this->settings->tenantAttribute !== null) {
            $attributes[$this->settings->tenantAttribute] = $this->settings->identity->tenantOf($request);
        }
        foreach ($this->settings->attributes as $source) {
            $attributes[$source->name] = $source->resolve($request);
        }

        return new Context(
            userId: $userId,
            attributes: array_filter($attributes, static fn (mixed $value): bool => $value !== null),
            sessionId: $this->settings->session?->resolve($request),
            ipAddress: $ipAddress,
        );
    }
}
