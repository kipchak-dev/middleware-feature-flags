<?php

declare(strict_types=1);

namespace Kipchak\Middleware\FeatureFlags\Tests\Unit;

use Kipchak\Driver\FeatureFlags\Library\FeatureFlagsClient;
use Kipchak\Driver\FeatureFlags\Request\Context;
use Kipchak\Driver\FeatureFlags\Result\Flag;
use Kipchak\Middleware\FeatureFlags\Config\Settings;
use Kipchak\Middleware\FeatureFlags\FeatureFlags;
use Kipchak\Middleware\FeatureFlags\Handlers\Flags;
use Kipchak\Middleware\FeatureFlags\Handlers\Gate;
use Kipchak\Middleware\FeatureFlags\Library\RequestFlags;
use Kipchak\Middleware\FeatureFlags\Tests\Unit\Support\FakeProvider;
use Kipchak\Middleware\FeatureFlags\Tests\Unit\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class GateTest extends TestCase
{
    private FakeProvider $provider;
    private RecordingLogger $logger;

    /** @var list<ServerRequestInterface> Requests that reached the route. */
    private array $routed = [];

    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
        $this->routed = [];
        $this->provider = new FakeProvider([
            'on' => static fn (): bool => true,
            'off' => static fn (): bool => false,
            'pro-only' => static fn (Context $c): bool => ($c->attributes['plan'] ?? null) === 'pro',
            'acme-only' => static fn (Context $c): bool => $c->userId === 'acme',
            'search' => static fn (Context $c): Flag => new Flag('search', true, variant: $c->userId === 'acme' ? 'vector' : 'keyword'),
        ]);
    }

    private function settings(array $config = []): Settings
    {
        return Settings::fromArray($config + [
            'consumer' => [['source' => 'header', 'name' => 'x-client']],
            'attributes' => ['plan' => ['source' => 'header', 'name' => 'x-plan']],
        ]);
    }

    private function client(bool $strict = false): FeatureFlagsClient
    {
        return new FeatureFlagsClient($this->provider, $strict, $this->logger);
    }

    private function gate(string $flag, array $options = [], array $config = [], bool $strict = false): Gate
    {
        return new Gate($flag, ...$options, settings: $this->settings($config), client: $this->client($strict), logger: $this->logger);
    }

    private function flags(array $config = [], bool $strict = false): Flags
    {
        return new Flags($this->settings($config), $this->client($strict), $this->logger);
    }

    private static function request(string $method = 'GET', string $path = '/v2/checkout', array $headers = []): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, 'https://api.example.com' . $path, ['REMOTE_ADDR' => '203.0.113.7']);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request;
    }

    /**
     * Runs the request through the middlewares, outermost first, to a route that records what it received.
     */
    private function dispatch(ServerRequestInterface $request, MiddlewareInterface ...$middlewares): ResponseInterface
    {
        $handler = new class ($this->routed) implements RequestHandlerInterface {
            public function __construct(private array &$routed)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->routed[] = $request;
                $response = new Response(200);
                $response->getBody()->write('route');

                return $response;
            }
        };

        foreach (array_reverse($middlewares) as $middleware) {
            $handler = new class ($middleware, $handler) implements RequestHandlerInterface {
                public function __construct(private MiddlewareInterface $middleware, private RequestHandlerInterface $next)
                {
                }

                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return $this->middleware->process($request, $this->next);
                }
            };
        }

        return $handler->handle($request);
    }

    private static function error(ResponseInterface $response): ?string
    {
        return json_decode((string) $response->getBody(), true)['data']['error'] ?? null;
    }

    /**
     * @return array{0: int, 1: string}
     */
    private static function envelopeStatus(ResponseInterface $response): array
    {
        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)['status']];
    }

    public function testRefusalsCarryTheStatusesMiddlewareErrorUses(): void
    {
        $this->assertSame([404, 'RESOURCE_NOT_FOUND'], self::envelopeStatus($this->dispatch(self::request(), $this->gate('off'))), 'The status a route that does not exist gets');
        $this->assertSame([403, 'INSUFFICIENT_PRIVILEGES'], self::envelopeStatus($this->dispatch(self::request(), $this->gate('off', ['status' => 403]))));
        $this->provider->down = true;
        $this->assertSame([503, 'SERVER_ERROR'], self::envelopeStatus($this->dispatch(self::request(), $this->gate('on', config: ['on_error' => 'unavailable']))));

        // Control: what 1.4 and earlier wrote, through Response::json().
        $label = static fn (int $code): string => json_decode((string) \Kipchak\Core\Components\Http\Response::json(new \Slim\Psr7\Response(), '', $code)->getBody(), true)['status'];
        $this->assertSame(['NOT FOUND', 'FORBIDDEN', 'SERVICE UNAVAILABLE'], [$label(404), $label(403), $label(503)]);
    }

    public function testAFlagThatIsOnLetsTheRequestThrough(): void
    {
        $response = $this->dispatch(self::request(headers: ['x-client' => 'acme']), $this->gate('acme-only'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(1, $this->routed);
        $flags = FeatureFlags::of($this->routed[0]);
        $this->assertInstanceOf(RequestFlags::class, $flags);
        $this->assertSame('acme', FeatureFlags::context($this->routed[0])?->userId);
    }

    public function testAFlagThatIsOffAnswers404(): void
    {
        $response = $this->dispatch(self::request(headers: ['x-client' => 'globex']), $this->gate('acme-only'));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('not_found', self::error($response), 'As if the route did not exist');
        $this->assertSame([], $this->routed);
    }

    public function testOr403(): void
    {
        $response = $this->dispatch(self::request(), $this->gate('off', ['status' => 403]));
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('feature_not_enabled', self::error($response));

        $this->assertSame(403, $this->dispatch(self::request(), $this->gate('off', config: ['deny_status' => 403]))->getStatusCode(), 'deny_status is the default');
    }

    public function testAttributesReachTheProvider(): void
    {
        $this->assertSame(200, $this->dispatch(self::request(headers: ['x-plan' => 'pro']), $this->gate('pro-only'))->getStatusCode());
        $this->assertSame(404, $this->dispatch(self::request(headers: ['x-plan' => 'free']), $this->gate('pro-only'))->getStatusCode());
    }

    public function testAKillSwitch(): void
    {
        $this->assertSame(200, $this->dispatch(self::request(), $this->gate('off', ['enabled' => false]))->getStatusCode());
        $this->assertSame(404, $this->dispatch(self::request(), $this->gate('on', ['enabled' => false]))->getStatusCode());
    }

    public function testVariants(): void
    {
        $gate = $this->gate('search', ['variants' => ['vector']]);

        $this->assertSame(200, $this->dispatch(self::request(headers: ['x-client' => 'acme']), $gate)->getStatusCode());
        $this->assertSame(404, $this->dispatch(self::request(headers: ['x-client' => 'globex']), $gate)->getStatusCode());
    }

    public function testAnUnknownFlagIsOffAndReportedOnce(): void
    {
        $gate = $this->gate('no-such-flag');

        $this->assertSame(404, $this->dispatch(self::request(), $gate)->getStatusCode());
        $this->assertSame(404, $this->dispatch(self::request(), $gate)->getStatusCode());
        $this->assertCount(1, array_filter($this->logger->messages, static fn (string $m): bool => str_contains($m, '"no-such-flag" is not a flag in fake')));
        $this->assertSame(200, $this->dispatch(self::request(), $this->gate('no-such-flag', ['enabled' => false]))->getStatusCode(), 'An unknown kill switch is off');
    }

    public function testWhenTheProviderIsDown(): void
    {
        $this->provider->down = true;

        $this->assertSame(404, $this->dispatch(self::request(), $this->gate('on'))->getStatusCode(), "on_error 'closed' denies");
        $this->assertSame(200, $this->dispatch(self::request(), $this->gate('on', ['onError' => 'open']))->getStatusCode());

        $unavailable = $this->dispatch(self::request(), $this->gate('on', config: ['on_error' => 'unavailable']));
        $this->assertSame(503, $unavailable->getStatusCode());
        $this->assertSame('5', $unavailable->getHeaderLine('Retry-After'));
        $this->assertSame('feature_flags_unavailable', self::error($unavailable));
        $this->assertNotEmpty(array_filter($this->logger->messages, static fn (string $m): bool => str_contains($m, 'could not be evaluated, so requests are denied')));
    }

    public function testAStrictConnectionThatThrows(): void
    {
        $this->provider->down = true;

        $this->assertSame(404, $this->dispatch(self::request(), $this->gate('on', strict: true))->getStatusCode());
        $this->assertSame(200, $this->dispatch(self::request(), $this->gate('on', ['onError' => 'open'], strict: true))->getStatusCode());
        $this->assertSame(503, $this->dispatch(self::request(), $this->gate('on', ['onError' => 'unavailable'], strict: true))->getStatusCode());
    }

    public function testEachFlagIsEvaluatedOncePerRequest(): void
    {
        // A rollout that is not sticky: a coin toss on every evaluation.
        $tosses = 0;
        $this->provider = new FakeProvider(['coin' => static function () use (&$tosses): bool {
            return ++$tosses % 2 === 1;
        }]);

        $response = $this->dispatch(self::request(), $this->flags(['preload' => ['coin']]), $this->gate('coin'), $this->gate('coin'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(1, $this->provider->evaluations, 'The global middleware, both gates and the controller share one answer');
        $this->assertTrue(FeatureFlags::of($this->routed[0])->isEnabled('coin'));
        $this->assertCount(1, $this->provider->evaluations);
    }

    public function testAGateOnAnotherConnectionKeepsTheContext(): void
    {
        $other = new FakeProvider(['acme-only' => static fn (Context $c): bool => $c->userId === 'acme']);
        $gate = new Gate('acme-only', settings: $this->settings(['connection' => 'experiments']), client: new FeatureFlagsClient($other), logger: $this->logger);

        $response = $this->dispatch(self::request(headers: ['x-client' => 'acme']), $this->flags(), $gate);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('experiments', FeatureFlags::of($this->routed[0])->connection);
        $this->assertSame([], $this->provider->evaluations);
        $this->assertCount(1, $other->evaluations);
    }

    public function testRequestFlags(): void
    {
        $this->provider = new FakeProvider([
            'limit' => static fn (): Flag => new Flag('limit', true, 250),
            'search' => static fn (): Flag => new Flag('search', true, variant: 'vector'),
        ]);
        $this->dispatch(self::request(), $this->flags());
        $flags = FeatureFlags::of($this->routed[0]);

        $this->assertSame(250, $flags->value('limit', 100));
        $this->assertSame(100, $flags->value('unknown', 100));
        $this->assertTrue($flags->isEnabled('unknown', true), 'The default for an unknown flag');
        $this->assertSame('vector', $flags->variant('search'));
        $this->assertSame(['limit', 'unknown', 'search'], array_keys($flags->evaluated()));
    }

    public function testFlagsWithoutTheMiddlewareAreExplained(): void
    {
        $this->expectException(\LogicException::class);
        FeatureFlags::of(self::request());
    }

    public function testGatesInConfig(): void
    {
        $flags = $this->flags(['gates' => [
            ['path' => '/v2/checkout*', 'flag' => 'acme-only'],
            ['path' => '/v1/orders', 'methods' => ['post', 'PUT'], 'flag' => 'on', 'enabled' => false, 'status' => 403],
        ]]);

        $this->assertSame(404, $this->dispatch(self::request(path: '/v2/checkout'), $flags)->getStatusCode());
        $this->assertSame(404, $this->dispatch(self::request(path: '/v2/checkout/42/items'), $flags)->getStatusCode(), '* covers everything under it');
        $this->assertSame(200, $this->dispatch(self::request(path: '/v2/checkout', headers: ['x-client' => 'acme']), $flags)->getStatusCode());
        $this->assertSame(200, $this->dispatch(self::request(path: '/v2/checkouts-report'), $this->flags(['gates' => [['path' => '/v2/checkout', 'flag' => 'off']]]))->getStatusCode(), 'Without * the path is exact');

        $this->assertSame(403, $this->dispatch(self::request('POST', '/v1/orders'), $flags)->getStatusCode(), 'A kill switch on writes');
        $this->assertSame(200, $this->dispatch(self::request('GET', '/v1/orders'), $flags)->getStatusCode(), 'Reads are not gated');
        $this->assertSame(200, $this->dispatch(self::request('POST', '/v1/orders/9'), $flags)->getStatusCode());
    }

    public function testPreloadedFlagsAreOnTheRequest(): void
    {
        $this->dispatch(self::request(headers: ['x-client' => 'acme']), $this->flags(['preload' => ['acme-only', 'off']]));

        $evaluated = FeatureFlags::of($this->routed[0])->evaluated();
        $this->assertTrue($evaluated['acme-only']->enabled);
        $this->assertFalse($evaluated['off']->enabled);
    }

    public function testAStrictConnectionDoesNotFailPreloading(): void
    {
        $this->provider->down = true;

        $this->assertSame(200, $this->dispatch(self::request(), $this->flags(['preload' => ['on']], strict: true))->getStatusCode());
        $this->assertSame([], FeatureFlags::of($this->routed[0])->evaluated());
    }

    public function testNoFlagIsEvaluatedUnlessAsked(): void
    {
        $this->dispatch(self::request(), $this->flags(['gates' => [['path' => '/elsewhere', 'flag' => 'on']]]));

        $this->assertSame([], $this->provider->evaluations);
    }
}
