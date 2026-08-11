<?php

declare(strict_types=1);

namespace Acceptora\Verification\Laravel\Results;

use Acceptora\Verification\Laravel\Exceptions\InvalidResponseException;

final class HealthResult extends StructuredResult
{
    /** @param array<string, mixed> $data */
    public function __construct(#[\SensitiveParameter] array $data, #[\SensitiveParameter] string $requestId, #[\SensitiveParameter] string $correlationId)
    {
        self::assertRequired($data, ['status', 'ready', 'service', 'checked_at', 'components'], $requestId, 'readiness');

        if (! is_bool($data['ready']) || ! is_array($data['components'])) {
            throw new InvalidResponseException(
                message: 'The Acceptora readiness response has an invalid shape.',
                errorCode: 'INVALID_RESPONSE',
                requestId: $requestId,
            );
        }

        self::requiredString($data, 'status', $requestId, 'readiness');
        self::requiredString($data, 'service', $requestId, 'readiness');
        self::requiredString($data, 'checked_at', $requestId, 'readiness');

        parent::__construct($data, $requestId, $correlationId);
    }

    public function ready(): bool
    {
        return $this->data['ready'];
    }

    public function status(): string
    {
        return $this->data['status'];
    }

    /** @return array<string, mixed> */
    public function components(): array
    {
        return $this->data['components'];
    }
}
