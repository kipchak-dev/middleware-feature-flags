<?php

declare(strict_types=1);

namespace Kipchak\Middleware\FeatureFlags\Config;

use InvalidArgumentException;

final class InvalidConfiguration extends InvalidArgumentException
{
    public static function at(string $key, string $problem): self
    {
        return new self("kipchak.feature-flags.middleware: '{$key}' {$problem}.");
    }
}
