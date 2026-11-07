<?php
namespace axenox\GenAI\Common;

/**
 * JSON storage for AI payloads without UXON's numeric-string conversion.
 */
final class AiPayload
{
    public static function encode(array|object $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Only the root is converted to an array; nested JSON objects remain stdClass.
     */
    public static function decode(string $payload): array
    {
        $decoded = json_decode($payload, false, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded) && ! $decoded instanceof \stdClass) {
            throw new \UnexpectedValueException('AI payload must contain a JSON object or array.');
        }
        return (array) $decoded;
    }
}
