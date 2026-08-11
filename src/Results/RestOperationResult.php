<?php

declare(strict_types=1);

namespace Acceptora\Verification\Laravel\Results;

final class RestOperationResult extends StructuredResult
{
    /** @param array<string, mixed> $data */
    public function __construct(
        #[\SensitiveParameter] public readonly string $operationName,
        #[\SensitiveParameter] array $data,
        #[\SensitiveParameter] string $requestId,
        #[\SensitiveParameter] string $correlationId,
    ) {
        parent::__construct($data, $requestId, $correlationId);
    }
}
