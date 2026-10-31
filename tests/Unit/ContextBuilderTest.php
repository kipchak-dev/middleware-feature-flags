<?php

declare(strict_types=1);

namespace Kipchak\Middleware\FeatureFlags\Tests\Unit;

use Kipchak\Identity\Identity;
use Kipchak\Middleware\FeatureFlags\Config\Settings;
use Kipchak\Middleware\FeatureFlags\Library\ContextBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

final class ContextBuilderTest extends TestCase
{
    private const array API_KEYS = ['authorised_keys' => ['key-acme' => 'acme', 'key-globex' => 'globex'], 'header' => 'apikey'];

    private static function request(array $headers = [], array $claims = [], string $ip = '203.0.113.7'): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'https://api.example.com/v1/orders', ['REMOTE_ADDR' => $ip]);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $claims === [] ? $request : $request->withAttribute(Identity::TOKEN_ATTRIBUTE, (object) $claims);
    }

    private static function builder(array $config, array $shared = []): ContextBuilder
    {
        return new ContextBuilder(Settings::fromArray($config, $shared, self::API_KEYS));
    }

    public function testTheConsumerIsTheUserId(): void
    {
        $builder = self::builder([], ['consumer' => [['source' => 'token', 'claim' => 'client_id'], ['source' => 'api_key']]]);

        $this->assertSame('acme', $builder->build(self::request(['apikey' => 'key-acme']))->userId, 'The API key\'s client name, never the key');
        $this->assertSame('mobile-app', $builder->build(self::request(['apikey' => 'key-acme'], ['client_id' => 'mobile-app']))->userId);
        $this->assertNull($builder->build(self::request(['apikey' => 'unknown-key']))->userId);
    }

    public function testThePackageCanNameItsOwnConsumer(): void
    {
        $builder = self::builder(['consumer' => [['source' => 'header', 'name' => 'x-customer']]], ['consumer' => [['source' => 'api_key']]]);

        $this->assertSame('cust-9', $builder->build(self::request(['apikey' => 'key-acme', 'x-customer' => 'cust-9']))->userId);
    }

    public function testAttributes(): void
    {
        $builder = self::builder([
            'attributes' => [
                'plan' => ['source' => 'token', 'claim' => 'plan'],
                'beta' => ['source' => 'token', 'claim' => 'features.beta'],
                'roles' => ['source' => 'token', 'claim' => 'roles'],
                'country' => [['source' => 'header', 'name' => 'cf-ipcountry'], ['source' => 'header', 'name' => 'x-country']],
                'app_version' => ['source' => 'header', 'name' => 'x-app-version', 'cast' => 'int'],
                'trial' => ['source' => 'header', 'name' => 'x-trial', 'cast' => 'bool'],
                'tags' => ['source' => 'header', 'name' => 'x-tags', 'cast' => 'list'],
                'tier' => ['source' => 'api_key', 'map' => ['acme' => 'enterprise']],
                'region' => 'eu-west',
                'missing' => ['source' => 'header', 'name' => 'x-nothing'],
            ],
        ]);

        $context = $builder->build(self::request(
            ['x-country' => 'GB', 'x-app-version' => '42', 'x-trial' => 'yes', 'x-tags' => 'wholesale, eu,', 'apikey' => 'key-acme'],
            ['plan' => 'pro', 'features' => ['beta' => true], 'roles' => ['admin', 'billing']],
        ));

        $this->assertSame([
            'plan' => 'pro',
            'beta' => true,
            'roles' => ['admin', 'billing'],
            'country' => 'GB',
            'app_version' => 42,
            'trial' => true,
            'tags' => ['wholesale', 'eu'],
            'tier' => 'enterprise',
            'region' => 'eu-west',
        ], $context->attributes, 'Claims keep their type; missing attributes are left out');
    }

    public function testAValueThatCannotBeCastIsLeftOut(): void
    {
        $builder = self::builder(['attributes' => ['app_version' => ['source' => 'header', 'name' => 'x-app-version', 'cast' => 'int']]]);

        $this->assertSame([], $builder->build(self::request(['x-app-version' => 'banana']))->attributes);
    }

    public function testTheTenantIsAnAttribute(): void
    {
        $shared = ['tenant' => [['source' => 'token', 'claim' => 'org_id']]];

        $this->assertSame(['tenant' => 'org-1'], self::builder([], $shared)->build(self::request(claims: ['org_id' => 'org-1']))->attributes);
        $this->assertSame(['organisation' => 'org-1'], self::builder(['tenant_attribute' => 'organisation'], $shared)->build(self::request(claims: ['org_id' => 'org-1']))->attributes);
        $this->assertSame([], self::builder(['tenant_attribute' => null], $shared)->build(self::request(claims: ['org_id' => 'org-1']))->attributes);
    }

    public function testIpAddressAndSession(): void
    {
        $context = self::builder(['session' => ['source' => 'header', 'name' => 'x-session']])->build(self::request(['x-session' => 's-1']));
        $this->assertSame('203.0.113.7', $context->ipAddress);
        $this->assertSame('s-1', $context->sessionId);

        $this->assertNull(self::builder(['ip_address' => null])->build(self::request())->ipAddress);
        $this->assertSame('198.51.100.1', self::builder(['ip_address' => [['source' => 'header', 'name' => 'x-real-ip']]])->build(self::request(['x-real-ip' => '198.51.100.1']))->ipAddress);
    }

    public function testAnonymousRequestsCanBeBucketedByAddress(): void
    {
        $none = self::builder([]);
        $byIp = self::builder(['anonymous' => 'ip', 'consumer' => [['source' => 'api_key']]]);

        $this->assertNull($none->build(self::request())->userId);

        $first = $byIp->build(self::request(ip: '203.0.113.7'))->userId;
        $this->assertMatchesRegularExpression('/^anonymous-[0-9a-f]{16}$/', $first);
        $this->assertSame($first, $byIp->build(self::request(ip: '203.0.113.7'))->userId, 'Sticky per address');
        $this->assertNotSame($first, $byIp->build(self::request(ip: '203.0.113.8'))->userId);
        $this->assertStringNotContainsString('203.0.113', $first);
        $this->assertSame('acme', $byIp->build(self::request(['apikey' => 'key-acme']))->userId, 'A consumer always wins');
    }
}
