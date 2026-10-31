<?php

declare(strict_types=1);

namespace Kipchak\Middleware\FeatureFlags\Config;

use Kipchak\Identity\Identity;
use Kipchak\Identity\InvalidConfiguration as InvalidIdentity;
use Kipchak\Identity\Resolver;
use Kipchak\Identity\ResolverChain;
use Kipchak\Middleware\FeatureFlags\Library\AttributeSource;
use Kipchak\Middleware\FeatureFlags\Library\RemoteAddress;
use Kipchak\Middleware\FeatureFlags\Library\Rule;

/**
 * The kipchak.feature-flags.middleware settings, validated once per worker.
 */
final readonly class Settings
{
    public const string CONFIG_KEY = 'kipchak.feature-flags.middleware';

    public const string ANONYMOUS_NONE = 'none';
    public const string ANONYMOUS_IP = 'ip';

    public const string ON_ERROR_CLOSED = 'closed';
    public const string ON_ERROR_OPEN = 'open';
    public const string ON_ERROR_UNAVAILABLE = 'unavailable';
    public const array ON_ERROR = [self::ON_ERROR_CLOSED, self::ON_ERROR_OPEN, self::ON_ERROR_UNAVAILABLE];

    public const array DENY_STATUSES = [403, 404];

    /**
     * @param list<AttributeSource> $attributes
     * @param list<string> $preload
     * @param list<Rule> $gates
     */
    public function __construct(
        public Identity $identity,
        public string $connection = 'default',
        public bool $enabled = true,
        public array $attributes = [],
        public ?string $tenantAttribute = 'tenant',
        public string $anonymous = self::ANONYMOUS_NONE,
        public ?Resolver $ipAddress = new RemoteAddress(),
        public ?Resolver $session = null,
        public array $preload = [],
        public int $denyStatus = 404,
        public string $onError = self::ON_ERROR_CLOSED,
        public array $gates = [],
    ) {
    }

    /**
     * @param array<string, mixed> $config The kipchak.feature-flags.middleware config.
     * @param array<string, mixed> $sharedIdentity The kipchak.identity config.
     * @param array<string, mixed>|null $apiKeyConfig The kipchak.auth.key config, for the api_key source.
     */
    public static function fromArray(array $config, array $sharedIdentity = [], ?array $apiKeyConfig = null): self
    {
        try {
            return self::build($config, $sharedIdentity, $apiKeyConfig);
        } catch (InvalidIdentity $exception) {
            $message = str_starts_with($exception->getMessage(), Identity::CONFIG_KEY) || str_starts_with($exception->getMessage(), self::CONFIG_KEY)
                ? $exception->getMessage()
                : self::CONFIG_KEY . ': ' . $exception->getMessage();

            throw new InvalidConfiguration($message, 0, $exception);
        }
    }

    private static function build(array $config, array $sharedIdentity, ?array $apiKeyConfig): self
    {
        $identity = Identity::merge($config, $sharedIdentity, $apiKeyConfig, self::CONFIG_KEY);

        $connection = $config['connection'] ?? 'default';
        if (!is_string($connection) || $connection === '') {
            throw InvalidConfiguration::at('connection', 'must name a connection in kipchak.feature-flags');
        }

        $attributes = $config['attributes'] ?? [];
        if (!is_array($attributes) || ($attributes !== [] && array_is_list($attributes))) {
            throw InvalidConfiguration::at('attributes', "must map attribute names to where they come from, such as ['plan' => ['source' => 'token', 'claim' => 'plan']]");
        }
        $sources = [];
        foreach ($attributes as $name => $spec) {
            $sources[] = AttributeSource::fromConfig((string) $name, $spec, $apiKeyConfig);
        }

        $tenantAttribute = array_key_exists('tenant_attribute', $config) ? $config['tenant_attribute'] : 'tenant';
        if ($tenantAttribute !== null && (!is_string($tenantAttribute) || $tenantAttribute === '')) {
            throw InvalidConfiguration::at('tenant_attribute', 'must be an attribute name, or null to leave the tenant out');
        }

        $anonymous = $config['anonymous'] ?? self::ANONYMOUS_NONE;
        if (!in_array($anonymous, [self::ANONYMOUS_NONE, self::ANONYMOUS_IP], true)) {
            throw InvalidConfiguration::at('anonymous', "must be 'none' or 'ip'");
        }

        $ipAddress = array_key_exists('ip_address', $config) ? $config['ip_address'] : 'remote_addr';
        $ipAddress = match (true) {
            $ipAddress === null => null,
            $ipAddress === 'remote_addr' => new RemoteAddress(),
            is_array($ipAddress) => ResolverChain::fromArray('ip_address', array_is_list($ipAddress) ? $ipAddress : [$ipAddress], $apiKeyConfig),
            default => throw InvalidConfiguration::at('ip_address', "must be 'remote_addr', null, or a list of sources"),
        };
        if ($anonymous === self::ANONYMOUS_IP && $ipAddress === null) {
            throw InvalidConfiguration::at('anonymous', "is 'ip', which needs an ip_address");
        }

        $session = $config['session'] ?? null;
        if ($session !== null) {
            if (!is_array($session)) {
                throw InvalidConfiguration::at('session', 'must be null or a list of sources');
            }
            $session = ResolverChain::fromArray('session', array_is_list($session) ? $session : [$session], $apiKeyConfig);
        }

        $preload = $config['preload'] ?? [];
        if (!is_array($preload) || !array_is_list($preload) || array_filter($preload, static fn (mixed $flag): bool => !is_string($flag) || $flag === '') !== []) {
            throw InvalidConfiguration::at('preload', 'must list flag names');
        }

        $denyStatus = $config['deny_status'] ?? 404;
        if (!in_array($denyStatus, self::DENY_STATUSES, true)) {
            throw InvalidConfiguration::at('deny_status', 'must be 403 or 404');
        }

        $onError = $config['on_error'] ?? self::ON_ERROR_CLOSED;
        if (!in_array($onError, self::ON_ERROR, true)) {
            throw InvalidConfiguration::at('on_error', "must be 'closed', 'open' or 'unavailable'");
        }

        $gates = $config['gates'] ?? [];
        if (!is_array($gates) || !array_is_list($gates)) {
            throw InvalidConfiguration::at('gates', "must be a list of gates, such as ['path' => '/v2/checkout*', 'flag' => 'new-checkout']");
        }
        $rules = [];
        foreach ($gates as $index => $gate) {
            if (!is_array($gate)) {
                throw InvalidConfiguration::at("gates[{$index}]", "must be an array with 'path' and 'flag'");
            }
            $rules[] = Rule::fromConfig($gate, $index, $denyStatus, $onError);
        }

        return new self(
            identity: $identity,
            connection: $connection,
            enabled: (bool) ($config['enabled'] ?? true),
            attributes: $sources,
            tenantAttribute: $tenantAttribute,
            anonymous: $anonymous,
            ipAddress: $ipAddress,
            session: $session,
            preload: $preload,
            denyStatus: $denyStatus,
            onError: $onError,
            gates: $rules,
        );
    }
}
