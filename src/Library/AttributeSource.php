<?php

declare(strict_types=1);

namespace Kipchak\Middleware\FeatureFlags\Library;

use Kipchak\Identity\Identity;
use Kipchak\Identity\InvalidConfiguration as InvalidIdentity;
use Kipchak\Identity\Resolver;
use Kipchak\Identity\ResolverChain;
use Kipchak\Middleware\FeatureFlags\Config\InvalidConfiguration;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Where one context attribute comes from: a fixed value, or the first of a list of sources that finds one.
 *
 * Sources are written as in config/kipchak.identity.php ('token', 'attribute', 'header', 'api_key', 'callable',
 * each with an optional 'map'), plus a 'cast'. Token claims and request attributes keep their type (a boolean
 * claim stays a boolean, a list of roles stays a list); the other sources find strings, which 'cast' converts.
 */
final readonly class AttributeSource
{
    public const array CASTS = ['string', 'int', 'float', 'bool', 'list'];

    /**
     * @param list<array{0: Resolver|array{attribute: string, claim: ?string, map: ?array<string, string>}, 1: ?string}> $sources
     *        Each a resolver or a request attribute to read, with its cast.
     */
    private function __construct(
        public string $name,
        private array $sources,
        private string|int|float|bool|null $value = null,
    ) {
    }

    /**
     * @param array<string, mixed>|null $apiKeyConfig The kipchak.auth.key config, for the api_key source.
     */
    public static function fromConfig(string $name, mixed $spec, ?array $apiKeyConfig): self
    {
        $path = "attributes.{$name}";
        if (is_scalar($spec)) {
            return new self($name, [], $spec);
        }
        if (!is_array($spec) || $spec === []) {
            throw InvalidConfiguration::at($path, 'must be a fixed value, a source, or a list of sources');
        }

        $sources = [];
        foreach (array_is_list($spec) ? $spec : [$spec] as $index => $source) {
            $at = "{$path}[{$index}]";
            if (!is_array($source)) {
                throw InvalidConfiguration::at($at, "must be an array with a 'source' key");
            }

            $cast = $source['cast'] ?? null;
            if ($cast !== null && !in_array($cast, self::CASTS, true)) {
                throw InvalidConfiguration::at("{$at}.cast", "must be one of '" . implode("', '", self::CASTS) . "'");
            }

            $map = $source['map'] ?? null;
            if ($map !== null && (!is_array($map) || $map === [] || array_filter($map, static fn (mixed $v, int|string $k): bool => !is_scalar($v) || $k === '', ARRAY_FILTER_USE_BOTH) !== [])) {
                throw InvalidConfiguration::at("{$at}.map", 'must map found values to values');
            }
            $sources[] = match ($source['source'] ?? null) {
                'token' => [['attribute' => Identity::TOKEN_ATTRIBUTE, 'claim' => self::string($source, 'claim', $at), 'map' => $map], $cast],
                'attribute' => [['attribute' => self::string($source, 'name', $at), 'claim' => isset($source['claim']) ? self::string($source, 'claim', $at) : null, 'map' => $map], $cast],
                // Header, API key and callable sources work exactly as they do for the consumer.
                default => [self::resolver($source, $at, $apiKeyConfig), $cast],
            };
        }

        return new self($name, $sources);
    }

    /**
     * @return scalar|list<scalar>|null Null when no source found a value, so the attribute is left out.
     */
    public function resolve(ServerRequestInterface $request): string|int|float|bool|array|null
    {
        if ($this->sources === []) {
            return $this->value;
        }

        foreach ($this->sources as [$source, $cast]) {
            $value = $source instanceof Resolver ? $source->resolve($request) : self::read($request, $source);
            if ($value !== null && $cast !== null) {
                $value = self::cast($value, $cast);
            }
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * A request attribute, or a claim in it (a dot-separated path into an array or object), kept as it is when it
     * is a scalar or a list of scalars.
     *
     * @param array{attribute: string, claim: ?string, map: ?array<string, string>} $source
     */
    private static function read(ServerRequestInterface $request, array $source): string|int|float|bool|array|null
    {
        $value = $request->getAttribute($source['attribute']);
        if ($source['claim'] !== null) {
            foreach (explode('.', $source['claim']) as $segment) {
                $value = match (true) {
                    is_array($value) => $value[$segment] ?? null,
                    is_object($value) => $value->{$segment} ?? null,
                    default => null,
                };
            }
        }

        if ($source['map'] !== null) {
            return is_string($value) || is_int($value) ? ($source['map'][$value] ?? null) : null;
        }
        if (is_scalar($value)) {
            return $value;
        }
        if (is_array($value) && array_is_list($value) && array_filter($value, static fn (mixed $item): bool => !is_scalar($item)) === []) {
            return $value;
        }

        return null;
    }

    /**
     * @return scalar|list<scalar>|null Null when the value cannot be read as that type.
     */
    private static function cast(string|int|float|bool|array $value, string $cast): string|int|float|bool|array|null
    {
        if (is_array($value)) {
            return $cast === 'list' ? $value : null;
        }

        return match ($cast) {
            'string' => is_bool($value) ? ($value ? 'true' : 'false') : (string) $value,
            'int' => filter_var($value, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE),
            'float' => filter_var($value, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE),
            'bool' => is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE),
            'list' => array_values(array_filter(array_map('trim', explode(',', (string) $value)), static fn (string $item): bool => $item !== '')),
        };
    }

    /**
     * A header, API key or callable source, built as Kipchak Identity builds the consumer's.
     */
    private static function resolver(array $source, string $at, ?array $apiKeyConfig): Resolver
    {
        try {
            return ResolverChain::fromArray($at, [array_diff_key($source, ['cast' => true])], $apiKeyConfig);
        } catch (InvalidIdentity $exception) {
            // The chain numbers its one source [0]; the source's place in this attribute's list is already in $at.
            throw new InvalidIdentity(str_replace("{$at}[0]", $at, $exception->getMessage()), 0, $exception);
        }
    }

    private static function string(array $source, string $key, string $at): string
    {
        $value = $source[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw InvalidConfiguration::at("{$at}.{$key}", 'must be a non-empty string');
        }

        return $value;
    }
}
