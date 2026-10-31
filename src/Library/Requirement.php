<?php

declare(strict_types=1);

namespace Kipchak\Middleware\FeatureFlags\Library;

use Kipchak\Driver\FeatureFlags\Exception\FeatureFlagException;
use Kipchak\Driver\FeatureFlags\Result\Flag;
use Kipchak\Middleware\FeatureFlags\Config\InvalidConfiguration;
use Kipchak\Middleware\FeatureFlags\Config\Settings;

/**
 * What a gate needs from a flag: on (or off, for a kill switch), and optionally one of some variants.
 */
final readonly class Requirement
{
    public const string PASS = 'pass';
    public const string DENY = 'deny';
    public const string UNAVAILABLE = 'unavailable';

    /**
     * @param list<string> $variants When not empty, the flag must also have given one of these variants.
     */
    public function __construct(
        public string $flag,
        public bool $enabled = true,
        public array $variants = [],
        public int $status = 404,
        public string $onError = Settings::ON_ERROR_CLOSED,
    ) {
        if ($flag === '') {
            throw InvalidConfiguration::at('flag', 'must be a flag name');
        }
        if (!in_array($status, Settings::DENY_STATUSES, true)) {
            throw InvalidConfiguration::at('status', 'must be 403 or 404');
        }
        if (!in_array($onError, Settings::ON_ERROR, true)) {
            throw InvalidConfiguration::at('on_error', "must be 'closed', 'open' or 'unavailable'");
        }
        if (array_filter($variants, static fn (mixed $variant): bool => !is_string($variant) || $variant === '') !== []) {
            throw InvalidConfiguration::at('variants', 'must list variant names');
        }
    }

    /**
     * @return array{0: string, 1: Flag|null} PASS, DENY or UNAVAILABLE, and the flag as evaluated (null when the
     *                                        strict connection threw).
     */
    public function check(RequestFlags $flags): array
    {
        try {
            $flag = $flags->evaluate($this->flag);
        } catch (FeatureFlagException) {
            return [$this->whenUnavailable(), null];
        }

        if ($flag->source === Flag::SOURCE_ERROR) {
            return [$this->whenUnavailable(), $flag];
        }

        // A flag the provider does not have is off.
        $passes = $flag->enabled === $this->enabled
            && ($this->variants === [] || in_array($flag->variant, $this->variants, true));

        return [$passes ? self::PASS : self::DENY, $flag];
    }

    private function whenUnavailable(): string
    {
        return match ($this->onError) {
            Settings::ON_ERROR_OPEN => self::PASS,
            Settings::ON_ERROR_UNAVAILABLE => self::UNAVAILABLE,
            default => self::DENY,
        };
    }
}
