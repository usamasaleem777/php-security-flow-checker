<?php

declare(strict_types=1);

namespace SecurityFlow\Models;

use InvalidArgumentException;
use JsonException;
use stdClass;

final class ModelJson
{
    public static function object(mixed $value, array $fields, string $location, array $optional = []): array
    {
        if (!$value instanceof stdClass) {
            throw new InvalidArgumentException("Expected a JSON object in $location.");
        }

        $properties = get_object_vars($value);
        $keys = array_keys($properties);
        if (array_diff($fields, $keys) !== [] || array_diff($keys, $fields, $optional) !== []) {
            throw new InvalidArgumentException("Unexpected or missing fields in $location.");
        }
        return $properties;
    }

    public static function items(mixed $value, string $location): array
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException("Expected a JSON list in $location.");
        }
        return $value;
    }

    public static function identifier(mixed $value, string $location): string
    {
        if (!is_string($value) || preg_match('/\A[A-Za-z][A-Za-z0-9_-]{0,63}\z/', $value) !== 1) {
            throw new InvalidArgumentException("Invalid identifier in $location.");
        }
        return $value;
    }

    public static function decode(string $json): mixed
    {
        try {
            $model = json_decode($json, depth: 128, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Invalid model JSON: ' . $exception->getMessage(), previous: $exception);
        }

        // The decoded object loses earlier values for repeated member names.
        self::rejectDuplicateMembers($json);
        return $model;
    }

    private static function rejectDuplicateMembers(string $json): void
    {
        // Native decoding has already checked string escapes and balanced the containers.
        $objects = [];
        $offset = 0;
        $length = strlen($json);
        while ($offset < $length) {
            $offset += strcspn($json, '"{}', $offset);
            if ($offset === $length) {
                break;
            }

            $token = $json[$offset++];
            if ($token === '{') {
                $objects[] = [];
                continue;
            }
            if ($token === '}') {
                array_pop($objects);
                continue;
            }

            $start = $offset - 1;
            while (true) {
                $offset += strcspn($json, '"\\', $offset);
                if ($json[$offset++] === '"') {
                    break;
                }
                ++$offset;
            }
            $afterWhitespace = $offset + strspn($json, " \t\r\n", $offset);
            if (($json[$afterWhitespace] ?? null) !== ':') {
                continue;
            }

            $name = json_decode(substr($json, $start, $offset - $start), flags: JSON_THROW_ON_ERROR);
            $index = count($objects) - 1;
            if (isset($objects[$index][$name])) {
                throw new InvalidArgumentException('Duplicate object member in model JSON.');
            }
            $objects[$index][$name] = true;
        }
    }
}
