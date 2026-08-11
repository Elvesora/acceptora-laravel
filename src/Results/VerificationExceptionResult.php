<?php

declare(strict_types=1);

namespace Acceptora\Verification\Laravel\Results;

final class VerificationExceptionResult extends StructuredResult
{
    /** @param array<string, mixed> $data */
    public function __construct(#[\SensitiveParameter] array $data, #[\SensitiveParameter] string $requestId, #[\SensitiveParameter] string $correlationId)
    {
        self::assertRequired($data, [
            'exception_id', 'feature_id', 'feature_url', 'source_digest', 'feature_status',
            'completion_gate', 'idempotency_replayed', 'correlation_id',
        ], $requestId, 'record_verification_exception');
        self::requiredString($data, 'exception_id', $requestId, 'record_verification_exception');
        parent::__construct($data, $requestId, $correlationId);
    }

    public function exceptionId(): string
    {
        return $this->data['exception_id'];
    }
}
