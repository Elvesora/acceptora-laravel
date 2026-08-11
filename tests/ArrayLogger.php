<?php

declare(strict_types=1);

namespace Acceptora\Verification\Laravel\Tests;

use LogicException;
use Psr\Log\AbstractLogger;
use Stringable;

final class ArrayLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /**
     * @param  string|Stringable  $message
     * @param  array<string, mixed>  $context
     */
    public function log($level, $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    /** @return array{level: string, message: string, context: array<string, mixed>} */
    public function last(): array
    {
        if ($this->records === []) {
            throw new LogicException('No log records were captured.');
        }

        return $this->records[array_key_last($this->records)];
    }
}
