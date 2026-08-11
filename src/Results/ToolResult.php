<?php

declare(strict_types=1);

namespace Acceptora\Verification\Laravel\Results;

class ToolResult extends StructuredResult
{
    /** @param array<string, mixed> $data */
    public function __construct(
        #[\SensitiveParameter] public readonly string $toolName,
        #[\SensitiveParameter] array $data,
        #[\SensitiveParameter] string $requestId,
        #[\SensitiveParameter] string $correlationId,
    ) {
        parent::__construct($data, $requestId, $correlationId);
    }
}
