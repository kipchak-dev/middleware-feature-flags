<?php

declare(strict_types=1);

namespace Kipchak\Middleware\FeatureFlags\Library;

use Kipchak\Driver\FeatureFlags\Library\FeatureFlagsClient;
use Kipchak\Driver\FeatureFlags\Request\Context;
use Kipchak\Driver\FeatureFlags\Result\Flag;

/**
 * The flags for one request: the connection and the request's Context together, so controllers ask
 * $flags->isEnabled('new-checkout') without building a context.
 *
 * Each flag is evaluated once per request and the answer kept, so a gate and the controller behind it always agree,
 * even for a rollout that is not sticky. Built for each request and dropped with it.
 */
final class RequestFlags
{
    /** @var array<string, Flag> */
    private array $evaluated = [];

    public function __construct(
        private readonly FeatureFlagsClient $client,
        private readonly Context $context,
        public readonly string $connection = 'default',
    ) {
    }

    public function context(): Context
    {
        return $this->context;
    }

    /**
     * Whether the flag is on, or $default when the provider does not have it or cannot be reached.
     */
    public function isEnabled(string $flag, bool $default = false): bool
    {
        $result = $this->evaluate($flag);

        return $result->isKnown() ? $result->enabled : $default;
    }

    /**
     * The flag's value, or $default when it has none, is unknown, or the provider cannot be reached.
     */
    public function value(string $flag, mixed $default = null): mixed
    {
        $result = $this->evaluate($flag);

        return $result->isKnown() && $result->value !== null ? $result->value : $default;
    }

    /**
     * The name of the variant this request's consumer was given, or null.
     */
    public function variant(string $flag): ?string
    {
        return $this->evaluate($flag)->variant;
    }

    /**
     * Everything the provider says about the flag for this request. Throws FeatureFlagException only for a
     * connection with 'strict' => true.
     */
    public function evaluate(string $flag): Flag
    {
        return $this->evaluated[$flag] ??= $this->client->evaluate($flag, $this->context);
    }

    /**
     * The flags evaluated for this request so far, preloaded ones included, by name.
     *
     * @return array<string, Flag>
     */
    public function evaluated(): array
    {
        return $this->evaluated;
    }

    /**
     * The connection itself, for anything that needs a different context.
     */
    public function client(): FeatureFlagsClient
    {
        return $this->client;
    }
}
