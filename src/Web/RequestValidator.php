<?php

declare(strict_types=1);

namespace SecurityFlow\Web;

use InvalidArgumentException;
use SecurityFlow\Models\ModelJson;

final class RequestValidator
{
    public const MAX_BODY = 4096;
    public const MAX_QUERY = 2048;
    public const MAX_STATES = 500;
    public const MAX_TRANSITIONS = 2000;
    public const MAX_DEPTH = 64;

    public static function local(array $server): void
    {
        if (
            !in_array($server['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1', '::ffff:127.0.0.1'], true)
            || preg_match('/\A(?:localhost|127\.0\.0\.1|\[::1\])(?::[0-9]{1,5})?\z/i', $server['HTTP_HOST'] ?? '') !== 1
        ) {
            throw new HttpError(403, 'The dashboard accepts loopback requests only.');
        }
    }

    public static function method(array $server, string $allowed): void
    {
        if (($server['REQUEST_METHOD'] ?? '') !== $allowed) {
            throw new HttpError(405, 'Method not allowed for this endpoint.', ['Allow' => $allowed]);
        }
    }

    public static function querySize(array $server): void
    {
        if (strlen($server['QUERY_STRING'] ?? '') > self::MAX_QUERY) {
            throw new HttpError(414, 'The query string exceeds 2 KiB.');
        }
    }

    public static function query(array $server, array $query, array $fields): void
    {
        self::querySize($server);
        $allowed = ['api', ...$fields];
        foreach ($query as $name => $value) {
            if (!in_array($name, $allowed, true) || !is_string($value)) {
                throw new HttpError(400, 'Invalid query parameters for this endpoint.');
            }
        }
        $raw = $server['QUERY_STRING'] ?? '';
        if ($raw === '') {
            return;
        }

        // PHP normalizes names and discards earlier values for repeated keys.
        $seen = [];
        foreach (explode('&', $raw) as $pair) {
            $parts = explode('=', $pair, 2);
            $name = urldecode($parts[0]);
            if (count($parts) !== 2 || !in_array($name, $allowed, true) || isset($seen[$name])) {
                throw new HttpError(400, 'Invalid or repeated query parameter.');
            }
            $seen[$name] = true;
        }
        if (array_diff_key($seen, $query) !== [] || array_diff_key($query, $seen) !== []) {
            throw new HttpError(400, 'The query could not be decoded consistently.');
        }
    }

    public static function id(mixed $value, string $label): string
    {
        try {
            return ModelJson::identifier($value, $label);
        } catch (InvalidArgumentException) {
            throw new HttpError(400, "Invalid $label.");
        }
    }

    public static function run(array $server, string $body, string $token): array
    {
        $origin = $server['HTTP_ORIGIN'] ?? null;
        if ($origin !== null) {
            $tls = ($server['HTTPS'] ?? '') === 'on';
            $host = strtolower($server['HTTP_HOST'] ?? '');
            $defaultPort = $tls ? ':443' : ':80';
            if (str_ends_with($host, $defaultPort)) {
                $host = substr($host, 0, -strlen($defaultPort));
            }
            $sameOrigin = ($tls ? 'https://' : 'http://') . $host;
            if ($origin !== $sameOrigin) {
                throw new HttpError(403, 'Verification requests must originate from the dashboard.');
            }
        }
        if (!hash_equals($token, $server['HTTP_X_CSRF_TOKEN'] ?? '')) {
            throw new HttpError(403, 'The request token is invalid. Reload the dashboard and try again.');
        }
        if (strlen($body) > self::MAX_BODY || (int) ($server['CONTENT_LENGTH'] ?? 0) > self::MAX_BODY) {
            throw new HttpError(413, 'The request body exceeds 4 KiB.');
        }
        if (strtolower(trim(explode(';', $server['CONTENT_TYPE'] ?? '', 2)[0])) !== 'application/json') {
            throw new HttpError(415, 'Verification requires an application/json request.');
        }
        try {
            $data = ModelJson::object(
                ModelJson::decode($body),
                ['model', 'max_states', 'max_transitions', 'max_depth'],
                'verification request',
            );
        } catch (InvalidArgumentException $error) {
            throw new HttpError(400, $error->getMessage());
        }
        $data['model'] = self::id($data['model'], 'model identifier');
        foreach (['max_states' => self::MAX_STATES, 'max_transitions' => self::MAX_TRANSITIONS, 'max_depth' => self::MAX_DEPTH] as $key => $max) {
            $min = $key === 'max_depth' ? 0 : 1;
            if (!is_int($data[$key]) || $data[$key] < $min || $data[$key] > $max) {
                throw new HttpError(400, "$key must be an integer from $min to $max.");
            }
        }
        return $data;
    }
}
