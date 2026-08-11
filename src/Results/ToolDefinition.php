<?php

declare(strict_types=1);

namespace Acceptora\Verification\Laravel\Results;

use Acceptora\Verification\Laravel\Exceptions\InvalidResponseException;
use JsonSerializable;

final class ToolDefinition implements JsonSerializable
{
    /**
     * @param  array<string, mixed>  $inputSchema
     * @param  array<string, mixed>  $outputSchema
     * @param  array<string, mixed>  $annotations
     */
    public function __construct(
        #[\SensitiveParameter] public readonly string $name,
        #[\SensitiveParameter] public readonly array $inputSchema,
        #[\SensitiveParameter] public readonly array $outputSchema,
        #[\SensitiveParameter] public readonly array $annotations,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,99}$/', $name) !== 1) {
            throw new InvalidResponseException(
                message: 'The Acceptora MCP server returned an invalid tool name.',
                errorCode: 'INVALID_RESPONSE',
            );
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(#[\SensitiveParameter] array $data, #[\SensitiveParameter] string $requestId): self
    {
        $name = $data['name'] ?? null;
        $input = $data['inputSchema'] ?? null;
        $output = $data['outputSchema'] ?? null;
        $annotations = $data['annotations'] ?? [];

        if (! is_string($name) || ! is_array($input) || ! is_array($output) || ! is_array($annotations)) {
            throw new InvalidResponseException(
                message: 'The Acceptora MCP server returned an invalid tool definition.',
                errorCode: 'INVALID_RESPONSE',
                requestId: $requestId,
            );
        }

        return new self($name, $input, $output, $annotations);
    }

    public function inputSchemaDigest(): string
    {
        return self::digest($this->inputSchema);
    }

    public function outputSchemaDigest(): string
    {
        return self::digest($this->outputSchema);
    }

    public function readOnly(): bool
    {
        return ($this->annotations['readOnlyHint'] ?? null) === true;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'name' => $this->name,
            'inputSchema' => $this->inputSchema,
            'outputSchema' => $this->outputSchema,
            'annotations' => $this->annotations,
        ];
    }

    /** @param array<string, mixed> $schema */
    private static function digest(#[\SensitiveParameter] array $schema): string
    {
        $normalized = self::sortRecursively($schema);
        $encoded = json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return 'sha256:'.hash('sha256', $encoded);
    }

    private static function sortRecursively(#[\SensitiveParameter] mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::sortRecursively(...), $value);
        }

        ksort($value);

        foreach ($value as $key => $child) {
            $value[$key] = self::sortRecursively($child);
        }

        return $value;
    }
}
