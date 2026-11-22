<?php

declare(strict_types=1);

namespace SecurityFlow\Web;

final readonly class Response
{
    public function __construct(
        public string $body,
        public string $type = 'text/html; charset=utf-8',
        public int $status = 200,
        public array $headers = [],
    ) {
    }

    public static function json(array $data, int $status = 200, array $headers = []): self
    {
        return new self(json_encode($data, JSON_THROW_ON_ERROR), 'application/json; charset=utf-8', $status, $headers);
    }

    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public function send(): void
    {
        http_response_code($this->status);
        header('Content-Type: ' . $this->type);
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header('Cross-Origin-Resource-Policy: same-origin');
        header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; " .
            "connect-src 'self'; img-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'none'");
        foreach ($this->headers as $name => $value) {
            header("$name: $value");
        }
        echo $this->body;
    }
}
