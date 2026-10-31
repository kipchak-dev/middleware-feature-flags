<?php

declare(strict_types=1);

namespace Kipchak\Middleware\FeatureFlags;

use Kipchak\Core\Components\App;
use Kipchak\Core\Interfaces;
use Kipchak\Driver\Config\Config;
use Kipchak\Driver\FeatureFlags\Request\Context;
use Kipchak\Middleware\FeatureFlags\Config\InvalidConfiguration;
use Kipchak\Middleware\FeatureFlags\Config\Settings;
use Kipchak\Middleware\FeatureFlags\Handlers\Flags;
use Kipchak\Middleware\FeatureFlags\Library\Guard;
use Kipchak\Middleware\FeatureFlags\Library\RequestFlags;
use Psr\Http\Message\ServerRequestInterface;

final class FeatureFlags implements Interfaces\Middleware
{
    /**
     * Puts each request's flags on it and applies the gates in kipchak.feature-flags.middleware, unless that config
     * has 'enabled' => false. Handlers\Gate can be attached to routes and groups either way.
     *
     * Needs the Feature Flags driver initialised in drivers/drivers.php. Call this before the authentication
     * middlewares in middlewares/middlewares.php: Slim runs the last-added middleware first, so the flags then
     * see the consumer the authentication middlewares identified.
     */
    public static function initialise(App $app): void
    {
        $config = Config::get(Settings::CONFIG_KEY);
        $settings = Settings::fromArray($config, Config::get('kipchak.identity'), Config::get('kipchak.auth.key') ?: null);

        $key = 'drivers.featureflags.' . $settings->connection;
        if (!$app->getContainer()->has($key)) {
            throw InvalidConfiguration::at('connection', "is '{$settings->connection}', which is not a connection in kipchak.feature-flags. "
                . 'Initialise Kipchak\Driver\FeatureFlags\FeatureFlags in drivers/drivers.php first');
        }

        if ($settings->enabled) {
            $app->add(new Flags($settings));
        }
    }

    /**
     * The request's flags, for a controller:
     *
     *   if (FeatureFlags::of($request)->isEnabled('new-checkout')) { ... }
     *
     * @throws \LogicException When neither the Flags middleware nor a Gate has run for this request.
     */
    public static function of(ServerRequestInterface $request): RequestFlags
    {
        $flags = $request->getAttribute(Guard::ATTRIBUTE);
        if (!$flags instanceof RequestFlags) {
            throw new \LogicException('No feature flags on this request: call FeatureFlags::initialise($app) in middlewares/middlewares.php, or add a Gate to the route.');
        }

        return $flags;
    }

    /**
     * The request's flag Context, or null when the middleware has not run for it.
     */
    public static function context(ServerRequestInterface $request): ?Context
    {
        $context = $request->getAttribute(Guard::CONTEXT_ATTRIBUTE);

        return $context instanceof Context ? $context : null;
    }
}
