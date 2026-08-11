<?php

declare(strict_types=1);

namespace Acceptora\Verification\Laravel\Exceptions;

use JsonSerializable;
use RuntimeException;
use Throwable;

class VerificationException extends RuntimeException implements JsonSerializable
{
    /**
     * @param  array<string, mixed>  $details
     * @param  array<string, mixed>  $responseBody
     */
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly ?int $statusCode = null,
        public readonly bool $retryable = false,
        public readonly ?int $retryAfter = null,
        public readonly ?string $correlationId = null,
        public readonly ?string $requestId = null,
        public readonly ?string $recoveryInstruction = null,
        public readonly array $details = [],
        public readonly array $responseBody = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode ?? 0, $previous);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'error_code' => $this->errorCode,
            'message' => $this->getMessage(),
            'status_code' => $this->statusCode,
            'retryable' => $this->retryable,
            'retry_after' => $this->retryAfter,
            'correlation_id' => $this->correlationId,
            'request_id' => $this->requestId,
            'recovery_instruction' => $this->recoveryInstruction,
            'details' => $this->details,
        ];
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        return [
            'message' => $this->getMessage(),
            'errorCode' => $this->errorCode,
            'statusCode' => $this->statusCode,
            'retryable' => $this->retryable,
            'retryAfter' => $this->retryAfter,
            'correlationId' => $this->correlationId,
            'requestId' => $this->requestId,
            'recoveryInstruction' => $this->recoveryInstruction,
            'details' => $this->details,
            'responseBody' => $this->responseBody,
        ];
    }
}
