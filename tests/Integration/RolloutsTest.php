<?php

declare(strict_types=1);

namespace Kipchak\Middleware\FeatureFlags\Tests\Integration;

use DI\Container;
use Kipchak\Driver\Config\Config;
use Kipchak\Driver\FeatureFlags\FeatureFlags as Driver;
use Kipchak\Driver\FeatureFlags\Library\FeatureFlagsClient;
use Kipchak\Driver\Http\Http;
use Kipchak\Identity\Identity;
use Kipchak\Middleware\FeatureFlags\Config\Settings;
use Kipchak\Middleware\FeatureFlags\FeatureFlags;
use Kipchak\Middleware\FeatureFlags\Handlers\Flags;
use Kipchak\Middleware\FeatureFlags\Handlers\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Against real Unleash, Flagsmith and GrowthBook servers. Start them first with tests/Integration/start-services.sh,
 * which creates the flags and writes the keys these tests read.
 */
final class RolloutsTest extends TestCase
{
    /** @var array<string, string> */
    private static array $keys = [];

    public static function setUpBeforeClass(): void
    {
        $file = __DIR__ . '/.keys.env';
        if (!is_file($file)) {
            self::markTestSkipped('Run tests/Integration/start-services.sh first.');
        }
        self::$keys = parse_ini_file($file, false, INI_SCANNER_RAW) ?: [];
    }

    public static function connections(): array
    {
        return [
            'unleash' => ['unleash', 1000],
            'flagsmith, local evaluation' => ['flagsmith-local', 1000],
            // Remote evaluation calls Flagsmith once for each new identity.
            'flagsmith, remote evaluation' => ['flagsmith-remote', 100],
            'growthbook' => ['growthbook', 1000],
        ];
    }

    public static function connectionNames(): array
    {
        return array_map(static fn (array $connection): array => [$connection[0]], self::connections());
    }

    /**
     * A new connection through the real driver, as a new FrankenPHP worker would have.
     */
    private static function worker(string $name): FeatureFlagsClient
    {
        $connections = [
            'unleash' => ['provider' => 'unleash', 'url' => self::$keys['UNLEASH_URL'], 'token' => self::$keys['UNLEASH_TOKEN'], 'metrics' => false, 'register' => false],
            'flagsmith-local' => ['provider' => 'flagsmith', 'url' => self::$keys['FLAGSMITH_URL'], 'environment_key' => self::$keys['FLAGSMITH_SERVER_KEY']],
            'flagsmith-remote' => ['provider' => 'flagsmith', 'url' => self::$keys['FLAGSMITH_URL'], 'environment_key' => self::$keys['FLAGSMITH_ENVIRONMENT_KEY']],
            'growthbook' => ['provider' => 'growthbook', 'url' => self::$keys['GROWTHBOOK_API_HOST'], 'client_key' => self::$keys['GROWTHBOOK_CLIENT_KEY']],
        ];

        $container = new Container();
        Config::initialise($container);
        $container->set('drivers.config', ['kipchak.feature-flags' => ['connections' => [$name => $connections[$name] + ['strict' => true]]]]);
        Http::initialise($container);
        Driver::initialise($container);

        return Driver::get($name);
    }

    private static function settings(int $consumers, array $config = []): Settings
    {
        $keys = [];
        for ($i = 0; $i < $consumers; $i++) {
            $keys["key-{$i}"] = "client-{$i}";
        }

        return Settings::fromArray($config + ['consumer' => [['source' => 'api_key']]], [], ['authorised_keys' => $keys, 'header' => 'apikey']);
    }

    private static function request(array $headers = [], ?array $claims = null): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'https://api.example.com/v2/beta', ['REMOTE_ADDR' => '203.0.113.7']);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $claims === null ? $request : $request->withAttribute(Identity::TOKEN_ATTRIBUTE, (object) $claims);
    }

    private static function route(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(FeatureFlags::of($request)->isEnabled('beta-api') ? 200 : 500);
            }
        };
    }

    /**
     * @return list<int> The consumers the gate let through.
     */
    private static function admitted(Gate $gate, int $consumers): array
    {
        $admitted = [];
        for ($i = 0; $i < $consumers; $i++) {
            $status = $gate->process(self::request(['apikey' => "key-{$i}"]), self::route())->getStatusCode();
            if ($status === 200) {
                $admitted[] = $i;
            } elseif ($status !== 404) {
                throw new \RuntimeException("Consumer {$i}: unexpected status {$status}");
            }
        }

        return $admitted;
    }

    #[DataProvider('connections')]
    public function testARolloutKeepsEachConsumerInOrOut(string $name, int $consumers): void
    {
        $settings = self::settings($consumers, ['connection' => $name]);
        $first = new Gate('beta-api', settings: $settings, client: self::worker($name), logger: new NullLogger());

        $admitted = self::admitted($first, $consumers);
        $share = count($admitted) / $consumers;
        $this->assertGreaterThan($consumers >= 1000 ? 0.22 : 0.15, $share, "A 30% rollout let {$share} through");
        $this->assertLessThan($consumers >= 1000 ? 0.38 : 0.45, $share, "A 30% rollout let {$share} through");

        $this->assertSame($admitted, self::admitted($first, $consumers), 'The same consumers on every request');

        $second = new Gate('beta-api', settings: $settings, client: self::worker($name), logger: new NullLogger());
        $this->assertSame($admitted, self::admitted($second, $consumers), 'And on every worker');
    }

    #[DataProvider('connectionNames')]
    public function testAttributesFromTheToken(string $name): void
    {
        $settings = self::settings(1, ['connection' => $name, 'attributes' => ['plan' => ['source' => 'token', 'claim' => 'plan']]]);
        $client = self::worker($name);
        $pass = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200);
            }
        };

        // Through the global middleware, as FeatureFlags::initialise() adds it, then a gate on the route.
        $flags = new Flags($settings, $client, new NullLogger());
        $gate = new Gate('pro-only', settings: $settings, client: $client, logger: new NullLogger());
        $chain = new class ($gate, $pass) implements RequestHandlerInterface {
            public function __construct(private Gate $gate, private RequestHandlerInterface $route)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->gate->process($request, $this->route);
            }
        };

        $this->assertSame(200, $flags->process(self::request(['apikey' => 'key-0'], ['plan' => 'pro']), $chain)->getStatusCode());
        $this->assertSame(404, $flags->process(self::request(['apikey' => 'key-0'], ['plan' => 'free']), $chain)->getStatusCode());
        $this->assertSame(404, $flags->process(self::request(['apikey' => 'key-0']), $chain)->getStatusCode());
    }
}
