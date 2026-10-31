<?php

declare(strict_types=1);

namespace Kipchak\Middleware\FeatureFlags\Tests\Unit\Support;

use Psr\Log\AbstractLogger;

final class RecordingLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $messages = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->messages[] = $level . ': ' . $message;
    }
}
