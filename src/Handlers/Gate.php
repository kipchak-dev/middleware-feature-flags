<?php

declare(strict_types=1);

namespace Kipchak\Middleware\FeatureFlags\Handlers;

use Kipchak\Driver\Config\Config;
use Kipchak\Driver\FeatureFlags\Library\FeatureFlagsClient;
use Kipchak\Driver\Logger\Logger;
use Kipchak\Middleware\FeatureFlags\Config\Settings;
use Kipchak\Middleware\FeatureFlags\Library\Guard;
use Kipchak\Middleware\FeatureFlags\Library\Requirement;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * Lets a request through to a route or group only when a flag is on for its consumer:
 *
 *   $app->post('/v2/checkout', ...)->add(new Gate('new-checkout'));
 *   $group->add(new Gate('orders-frozen', enabled: false, status: 403));     // a kill switch
 *   $app->get('/v2/search', ...)->add(new Gate('search-engine', variants: ['vector']));
 *
 * Otherwise the request gets 404 (as if the route did not exist), or 403 with 'status' => 403. It uses the flags
 * the Flags middleware put on the request, or builds the context itself from kipchak.feature-flags.middleware.
 */
final class Gate implements MiddlewareInterface
{
    private readonly Requirement $requirement;
    private readonly Guard $guard;

    /**
     * @param bool $enabled Whether the flag must be on (true) or off (false, for a kill switch).
     * @param list<string> $variants When not empty, the request's consumer must also have one of these variants.
     * @param int|null $status 404 or 403 when the flag does not allow the request; deny_status by default.
     * @param string|null $onError 'closed', 'open' or 'unavailable' when the flag cannot be evaluated; on_error by default.
     * @param string|null $connection A connection in kipchak.feature-flags other than the configured one.
     */
    public function __construct(
        string $flag,
        bool $enabled = true,
        array $variants = [],
        ?int $status = null,
        ?string $onError = null,
        ?string $connection = null,
        ?Settings $settings = null,
        ?FeatureFlagsClient $client = null,
        ?LoggerInterface $logger = null,
    ) {
        if ($settings === null) {
            $config = Config::get(Settings::CONFIG_KEY);
            $settings = Settings::fromArray(
                $connection === null ? $config : ['connection' => $connection] + $config,
                Config::get('kipchak.identity'),
                Config::get('kipchak.auth.key') ?: null,
            );
        }

        $this->requirement = new Requirement($flag, $enabled, array_values($variants), $status ?? $settings->denyStatus, $onError ?? $settings->onError);
        $this->guard = new Guard($settings, $client, $logger ?? Logger::get());
    }

    public function __invoke(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $this->process($request, $handler);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        [$request, $flags] = $this->guard->attach($request);

        return $this->guard->check($this->requirement, $flags) ?? $handler->handle($request);
    }
}
