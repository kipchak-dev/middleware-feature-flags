<?php

declare(strict_types=1);

namespace Kipchak\Middleware\FeatureFlags\Library;

use Kipchak\Core\Components\Http;
use Kipchak\Driver\FeatureFlags\FeatureFlags;
use Kipchak\Driver\FeatureFlags\Library\FeatureFlagsClient;
use Kipchak\Driver\FeatureFlags\Result\Flag;
use Kipchak\Middleware\FeatureFlags\Config\Settings;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Response;

/**
 * What the Flags and Gate middlewares share: the request's RequestFlags, and turning a requirement that is not met
 * into a response.
 *
 * One guard lives as long as its middleware, so for the whole worker; it keeps only which problems it has already
 * logged.
 */
final class Guard
{
    /** The request attribute holding the request's RequestFlags. */
    public const string ATTRIBUTE = 'kipchak.feature-flags';

    /** The request attribute holding the request's flag Context. */
    public const string CONTEXT_ATTRIBUTE = 'kipchak.feature-flags.context';

    private const int LOG_INTERVAL = 60;

    private readonly ContextBuilder $contexts;

    /** @var array<string, true> Gate flags already reported as unknown to the provider. */
    private array $reportedUnknown = [];

    private int $nextUnavailableLogAt = 0;

    public function __construct(
        private readonly Settings $settings,
        private ?FeatureFlagsClient $client,
        private readonly LoggerInterface $logger,
    ) {
        $this->contexts = new ContextBuilder($settings);
    }

    /**
     * The request with its RequestFlags attached: those an earlier middleware attached for the same connection, or
     * new ones with the request's context.
     *
     * @return array{0: ServerRequestInterface, 1: RequestFlags}
     */
    public function attach(ServerRequestInterface $request): array
    {
        $existing = $request->getAttribute(self::ATTRIBUTE);
        if ($existing instanceof RequestFlags && $existing->connection === $this->settings->connection) {
            return [$request, $existing];
        }

        $this->client ??= FeatureFlags::get($this->settings->connection);
        $context = $existing instanceof RequestFlags ? $existing->context() : $this->contexts->build($request);
        $flags = new RequestFlags($this->client, $context, $this->settings->connection);

        return [$request->withAttribute(self::ATTRIBUTE, $flags)->withAttribute(self::CONTEXT_ATTRIBUTE, $context), $flags];
    }

    /**
     * Null when the requirement is met, otherwise the response to send instead of running the route.
     */
    public function check(Requirement $requirement, RequestFlags $flags): ?ResponseInterface
    {
        [$outcome, $flag] = $requirement->check($flags);

        if ($flag?->source === Flag::SOURCE_UNKNOWN && !isset($this->reportedUnknown[$requirement->flag])) {
            $this->reportedUnknown[$requirement->flag] = true;
            $this->logger->warning(
                'Feature flag gate: "' . $requirement->flag . '" is not a flag in ' . $flags->client()->provider()
                . ' (connection "' . $flags->connection . '"), so it is treated as off.'
            );
        }
        if ($flag === null || $flag->source === Flag::SOURCE_ERROR) {
            $this->logUnavailable($requirement, $outcome);
        }

        return match ($outcome) {
            Requirement::PASS => null,
            Requirement::UNAVAILABLE => Http\Response::error(new Response(), [
                'error' => 'feature_flags_unavailable',
                'message' => 'This feature cannot be checked right now. Try again shortly.',
            ], 503)->withHeader('Retry-After', '5'),
            default => $requirement->status === 403
                ? Http\Response::error(new Response(), ['error' => 'feature_not_enabled', 'message' => 'This feature is not enabled for you.'], 403)
                : Http\Response::error(new Response(), ['error' => 'not_found', 'message' => 'Not found.'], 404),
        };
    }

    /**
     * The driver logs failures to reach the provider, except on a strict connection, where it throws instead; this
     * says what the gate did about it, at most once a minute.
     */
    private function logUnavailable(Requirement $requirement, string $outcome): void
    {
        $now = time();
        if ($now < $this->nextUnavailableLogAt) {
            return;
        }
        $this->nextUnavailableLogAt = $now + self::LOG_INTERVAL;

        $this->logger->warning('Feature flag gate: "' . $requirement->flag . '" could not be evaluated, so requests are '
            . match ($outcome) {
                Requirement::PASS => 'let through',
                Requirement::UNAVAILABLE => 'answered 503',
                default => 'denied',
            } . " (on_error '{$requirement->onError}').");
    }
}
