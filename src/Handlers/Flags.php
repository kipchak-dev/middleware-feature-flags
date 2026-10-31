<?php

declare(strict_types=1);

namespace Kipchak\Middleware\FeatureFlags\Handlers;

use Kipchak\Driver\Config\Config;
use Kipchak\Driver\FeatureFlags\Exception\FeatureFlagException;
use Kipchak\Driver\FeatureFlags\Library\FeatureFlagsClient;
use Kipchak\Driver\Logger\Logger;
use Kipchak\Middleware\FeatureFlags\Config\Settings;
use Kipchak\Middleware\FeatureFlags\Library\Guard;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds each request's flag Context and puts the request's flags on it, as the attribute 'kipchak.feature-flags'
 * (a RequestFlags), with the Context itself as 'kipchak.feature-flags.context'. Then applies the gates in config:
 * a request whose path matches a gate whose flag is off gets 404 (or 403) instead of reaching its route.
 *
 * Register with FeatureFlags::initialise($app) before the authentication middlewares, so it runs after them and
 * can use the consumer they identify. Safe in FrankenPHP worker mode: nothing about a request is kept after it.
 */
final class Flags implements MiddlewareInterface
{
    private readonly Settings $settings;
    private readonly Guard $guard;

    public function __construct(?Settings $settings = null, ?FeatureFlagsClient $client = null, ?LoggerInterface $logger = null)
    {
        $this->settings = $settings ?? Settings::fromArray(Config::get(Settings::CONFIG_KEY), Config::get('kipchak.identity'), Config::get('kipchak.auth.key') ?: null);
        $this->guard = new Guard($this->settings, $client, $logger ?? Logger::get());
    }

    public function __invoke(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $this->process($request, $handler);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        [$request, $flags] = $this->guard->attach($request);

        foreach ($this->settings->preload as $flag) {
            try {
                $flags->evaluate($flag);
            } catch (FeatureFlagException) {
                // A strict connection throws; a preloaded flag is only a convenience, so it is left unevaluated.
            }
        }

        foreach ($this->settings->gates as $gate) {
            if ($gate->matches($request) && ($denied = $this->guard->check($gate->requirement, $flags)) !== null) {
                return $denied;
            }
        }

        return $handler->handle($request);
    }
}
