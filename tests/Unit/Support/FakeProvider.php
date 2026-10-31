<?php

declare(strict_types=1);

namespace Kipchak\Middleware\FeatureFlags\Tests\Unit\Support;

use Kipchak\Driver\FeatureFlags\Library\Provider\Provider;
use Kipchak\Driver\FeatureFlags\Request\Context;
use Kipchak\Driver\FeatureFlags\Result\Flag;
use RuntimeException;

/**
 * Flags defined as closures of the context, with every evaluation recorded.
 */
final class FakeProvider implements Provider
{
    /** @var list<array{0: string, 1: Context}> */
    public array $evaluations = [];

    public bool $down = false;

    /**
     * @param array<string, \Closure(Context): Flag|bool> $flags
     */
    public function __construct(private array $flags = [])
    {
    }

    public function name(): string
    {
        return 'fake';
    }

    public function isEnabled(string $flag, Context $context, bool $default): bool
    {
        $result = $this->evaluate($flag, $context);

        return $result->isKnown() ? $result->enabled : $default;
    }

    public function evaluate(string $flag, Context $context): Flag
    {
        $this->evaluations[] = [$flag, $context];
        if ($this->down) {
            throw new RuntimeException('connection refused');
        }
        if (!isset($this->flags[$flag])) {
            return Flag::unknown($flag);
        }

        $result = ($this->flags[$flag])($context);

        return $result instanceof Flag ? $result : new Flag($flag, $result);
    }

    public function refresh(): void
    {
    }
}
