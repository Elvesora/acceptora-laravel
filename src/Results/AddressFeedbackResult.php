<?php

declare(strict_types=1);

namespace Acceptora\Verification\Laravel\Results;

final class AddressFeedbackResult extends StructuredResult
{
    /** @param array<string, mixed> $data */
    public function __construct(#[\SensitiveParameter] array $data, #[\SensitiveParameter] string $requestId, #[\SensitiveParameter] string $correlationId)
    {
        self::assertRequired($data, [
            'feature_id', 'source_digest', 'resolution_ids', 'thread_states', 'completion_gate',
            'idempotency_replayed', 'correlation_id',
        ], $requestId, 'address_feedback');
        self::requiredStringList($data, 'resolution_ids', $requestId, 'address_feedback');
        self::requiredBoolean($data, 'idempotency_replayed', $requestId, 'address_feedback');
        parent::__construct($data, $requestId, $correlationId);
    }

    /** @return list<string> */
    public function resolutionIds(): array
    {
        return $this->data['resolution_ids'];
    }
}
