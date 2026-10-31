<?php

declare(strict_types=1);

namespace Kipchak\Middleware\FeatureFlags\Tests\Unit;

use Kipchak\Middleware\FeatureFlags\Config\InvalidConfiguration;
use Kipchak\Middleware\FeatureFlags\Config\Settings;
use Kipchak\Middleware\FeatureFlags\Library\RemoteAddress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase
{
    public function testDefaults(): void
    {
        $settings = Settings::fromArray([]);

        $this->assertSame('default', $settings->connection);
        $this->assertTrue($settings->enabled);
        $this->assertSame('tenant', $settings->tenantAttribute);
        $this->assertSame('none', $settings->anonymous);
        $this->assertInstanceOf(RemoteAddress::class, $settings->ipAddress);
        $this->assertNull($settings->session);
        $this->assertSame(404, $settings->denyStatus);
        $this->assertSame('closed', $settings->onError);
        $this->assertSame([], $settings->gates);
    }

    public static function invalid(): iterable
    {
        yield 'connection' => [['connection' => ''], "'connection'"];
        yield 'attributes as a list' => [['attributes' => [['source' => 'header', 'name' => 'x-plan']]], "'attributes'"];
        yield 'attribute source' => [['attributes' => ['plan' => ['source' => 'nowhere']]], 'attributes.plan[0].source'];
        yield 'attribute claim' => [['attributes' => ['plan' => ['source' => 'token']]], "'attributes.plan[0].claim'"];
        yield 'attribute cast' => [['attributes' => ['plan' => ['source' => 'header', 'name' => 'x', 'cast' => 'date']]], "'attributes.plan[0].cast'"];
        yield 'attribute map' => [['attributes' => ['plan' => ['source' => 'token', 'claim' => 'p', 'map' => []]]], "'attributes.plan[0].map'"];
        yield 'empty attribute' => [['attributes' => ['plan' => []]], "'attributes.plan'"];
        yield 'tenant attribute' => [['tenant_attribute' => ''], "'tenant_attribute'"];
        yield 'anonymous' => [['anonymous' => 'random'], "'anonymous'"];
        yield 'anonymous without address' => [['anonymous' => 'ip', 'ip_address' => null], "'anonymous' is 'ip'"];
        yield 'ip address' => [['ip_address' => 'x-forwarded-for'], "'ip_address'"];
        yield 'session' => [['session' => 'x-session'], "'session'"];
        yield 'preload' => [['preload' => 'new-checkout'], "'preload'"];
        yield 'deny status' => [['deny_status' => 410], "'deny_status'"];
        yield 'on error' => [['on_error' => 'retry'], "'on_error'"];
        yield 'gates' => [['gates' => ['path' => '/v2', 'flag' => 'x']], "'gates'"];
        yield 'gate path' => [['gates' => [['path' => 'v2/*', 'flag' => 'x']]], "'gates[0].path'"];
        yield 'gate flag' => [['gates' => [['path' => '/v2/*']]], "'gates[0].flag'"];
        yield 'gate methods' => [['gates' => [['path' => '/v2/*', 'flag' => 'x', 'methods' => 'POST']]], "'gates[0].methods'"];
        yield 'gate status' => [['gates' => [['path' => '/v2/*', 'flag' => 'x', 'status' => 500]]], "'gates[0].status'"];
        yield 'gate on error' => [['gates' => [['path' => '/v2/*', 'flag' => 'x', 'on_error' => 'maybe']]], "'gates[0].on_error'"];
        yield 'gate variants' => [['gates' => [['path' => '/v2/*', 'flag' => 'x', 'variants' => 'blue']]], "'gates[0]'"];
        yield 'consumer' => [['consumer' => [['source' => 'nowhere']]], 'kipchak.feature-flags.middleware: consumer[0].source'];
    }

    #[DataProvider('invalid')]
    public function testRejectsInvalidConfig(array $config, string $expected): void
    {
        try {
            Settings::fromArray($config);
            $this->fail('Accepted invalid config');
        } catch (InvalidConfiguration $exception) {
            $this->assertStringContainsString($expected, $exception->getMessage());
            $this->assertStringStartsWith('kipchak.', $exception->getMessage(), 'Names the config file');
        }
    }

    public function testSharedIdentityErrorsNameTheSharedFile(): void
    {
        $this->expectExceptionMessage('kipchak.identity: consumer[0].source');
        Settings::fromArray([], ['consumer' => [['source' => 'nowhere']]]);
    }
}
