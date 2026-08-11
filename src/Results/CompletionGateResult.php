<?php

declare(strict_types=1);

namespace Acceptora\Verification\Laravel\Results;

final class CompletionGateResult extends StructuredResult
{
    /** @param array<string, mixed> $data */
    public function __construct(#[\SensitiveParameter] array $data, #[\SensitiveParameter] string $requestId, #[\SensitiveParameter] string $correlationId)
    {
        self::assertRequired($data, [
            'outcome', 'feature_id', 'reason_code', 'reason', 'last_synchronized_digest',
            'last_synchronized_checklist_revision', 'recovery_instruction', 'correlation_id',
        ], $requestId, 'completion gate');
        self::requiredString($data, 'outcome', $requestId, 'completion gate');
        self::requiredString($data, 'reason_code', $requestId, 'completion gate');
        self::requiredString($data, 'reason', $requestId, 'completion gate');

        parent::__construct($data, $requestId, $correlationId);
    }

    public function outcome(): string
    {
        return $this->data['outcome'];
    }

    public function passed(): bool
    {
        return $this->outcome() === 'pass';
    }
}
