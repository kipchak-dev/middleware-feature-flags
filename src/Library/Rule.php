<?php

declare(strict_types=1);

namespace Kipchak\Middleware\FeatureFlags\Library;

use Kipchak\Middleware\FeatureFlags\Config\InvalidConfiguration;
use Psr\Http\Message\ServerRequestInterface;

/**
 * A gate from config: requests whose path (and method) match must meet the requirement.
 *
 * The path is exact, or a pattern where * matches anything, slashes included: '/v2/checkout*' covers
 * '/v2/checkout' and everything under it.
 */
final readonly class Rule
{
    /**
     * @param list<string> $methods Upper case; empty for every method.
     */
    public function __construct(
        private string $pattern,
        private array $methods,
        public Requirement $requirement,
    ) {
    }

    public static function fromConfig(array $rule, int $index, int $defaultStatus, string $defaultOnError): self
    {
        $at = "gates[{$index}]";
        $path = $rule['path'] ?? null;
        if (!is_string($path) || !str_starts_with($path, '/')) {
            throw InvalidConfiguration::at("{$at}.path", "must be a path starting with '/', such as '/v2/checkout*'");
        }

        $methods = $rule['methods'] ?? [];
        if (!is_array($methods) || array_filter($methods, static fn (mixed $method): bool => !is_string($method) || $method === '') !== []) {
            throw InvalidConfiguration::at("{$at}.methods", 'must list HTTP methods');
        }

        $flag = $rule['flag'] ?? null;
        if (!is_string($flag) || $flag === '') {
            throw InvalidConfiguration::at("{$at}.flag", 'must be a flag name');
        }

        try {
            $requirement = new Requirement(
                $flag,
                (bool) ($rule['enabled'] ?? true),
                $rule['variants'] ?? [],
                $rule['status'] ?? $defaultStatus,
                $rule['on_error'] ?? $defaultOnError,
            );
        } catch (InvalidConfiguration $exception) {
            throw new InvalidConfiguration(preg_replace("/: '/", ": '{$at}.", $exception->getMessage(), 1), 0, $exception);
        } catch (\TypeError) {
            throw InvalidConfiguration::at($at, 'has a status, variants or on_error of the wrong type');
        }

        $pattern = '#^' . str_replace('\*', '.*', preg_quote($path, '#')) . '$#';

        return new self($pattern, array_map('strtoupper', $methods), $requirement);
    }

    public function matches(ServerRequestInterface $request): bool
    {
        if ($this->methods !== [] && !in_array(strtoupper($request->getMethod()), $this->methods, true)) {
            return false;
        }

        return preg_match($this->pattern, $request->getUri()->getPath()) === 1;
    }
}
