<?php

declare(strict_types=1);

namespace Acceptora\Verification\Laravel\Results;

use Acceptora\Verification\Laravel\Exceptions\InvalidResponseException;
use JsonSerializable;

abstract class StructuredResult implements JsonSerializable
{
    /** @param array<string, mixed> $data */
    public function __construct(
        #[\SensitiveParameter] protected readonly array $data,
        #[\SensitiveParameter] protected readonly string $requestId,
        #[\SensitiveParameter] protected readonly string $correlationId,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function requestId(): string
    {
        return $this->requestId;
    }

    public function correlationId(): string
    {
        return $this->correlationId;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $keys
     */
    protected static function assertRequired(
        #[\SensitiveParameter] array $data,
        #[\SensitiveParameter] array $keys,
        #[\SensitiveParameter] string $requestId,
        #[\SensitiveParameter] string $label,
    ): void {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $data)) {
                throw new InvalidResponseException(
                    message: sprintf('The Acceptora %s response omitted required field "%s".', $label, $key),
                    errorCode: 'INVALID_RESPONSE',
                    requestId: $requestId,
                );
            }
        }
    }

    /** @param array<string, mixed> $data */
    protected static function requiredString(#[\SensitiveParameter] array $data, #[\SensitiveParameter] string $key, #[\SensitiveParameter] string $requestId, #[\SensitiveParameter] string $label): string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidResponseException(
                message: sprintf('The Acceptora %s response field "%s" must be a non-empty string.', $label, $key),
                errorCode: 'INVALID_RESPONSE',
                requestId: $requestId,
            );
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    protected static function requiredInteger(#[\SensitiveParameter] array $data, #[\SensitiveParameter] string $key, #[\SensitiveParameter] string $requestId, #[\SensitiveParameter] string $label): int
    {
        $value = $data[$key] ?? null;

        if (! is_int($value)) {
            throw new InvalidResponseException(
                message: sprintf('The Acceptora %s response field "%s" must be an integer.', $label, $key),
                errorCode: 'INVALID_RESPONSE',
                requestId: $requestId,
            );
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    protected static function requiredBoolean(#[\SensitiveParameter] array $data, #[\SensitiveParameter] string $key, #[\SensitiveParameter] string $requestId, #[\SensitiveParameter] string $label): bool
    {
        $value = $data[$key] ?? null;

        if (! is_bool($value)) {
            throw new InvalidResponseException(
                message: sprintf('The Acceptora %s response field "%s" must be a boolean.', $label, $key),
                errorCode: 'INVALID_RESPONSE',
                requestId: $requestId,
            );
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected static function requiredObject(#[\SensitiveParameter] array $data, #[\SensitiveParameter] string $key, #[\SensitiveParameter] string $requestId, #[\SensitiveParameter] string $label): array
    {
        $value = $data[$key] ?? null;

        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new InvalidResponseException(
                message: sprintf('The Acceptora %s response field "%s" must be an object.', $label, $key),
                errorCode: 'INVALID_RESPONSE',
                requestId: $requestId,
            );
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    protected static function requiredStringList(#[\SensitiveParameter] array $data, #[\SensitiveParameter] string $key, #[\SensitiveParameter] string $requestId, #[\SensitiveParameter] string $label): array
    {
        $value = $data[$key] ?? null;

        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidResponseException(
                message: sprintf('The Acceptora %s response field "%s" must be a list of strings.', $label, $key),
                errorCode: 'INVALID_RESPONSE',
                requestId: $requestId,
            );
        }

        foreach ($value as $item) {
            if (! is_string($item) || trim($item) === '') {
                throw new InvalidResponseException(
                    message: sprintf('The Acceptora %s response field "%s" must be a list of strings.', $label, $key),
                    errorCode: 'INVALID_RESPONSE',
                    requestId: $requestId,
                );
            }
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    protected static function requiredObjectList(#[\SensitiveParameter] array $data, #[\SensitiveParameter] string $key, #[\SensitiveParameter] string $requestId, #[\SensitiveParameter] string $label): array
    {
        $value = $data[$key] ?? null;

        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidResponseException(
                message: sprintf('The Acceptora %s response field "%s" must be a list of objects.', $label, $key),
                errorCode: 'INVALID_RESPONSE',
                requestId: $requestId,
            );
        }

        foreach ($value as $item) {
            if (! is_array($item) || $item === [] || array_is_list($item)) {
                throw new InvalidResponseException(
                    message: sprintf('The Acceptora %s response field "%s" must be a list of objects.', $label, $key),
                    errorCode: 'INVALID_RESPONSE',
                    requestId: $requestId,
                );
            }
        }

        return $value;
    }
}
