<?php

declare(strict_types=1);

namespace SecurityFlow\Web;

use RuntimeException;

final class HttpError extends RuntimeException
{
    public function __construct(public readonly int $status, string $message, public readonly array $headers = [])
    {
        parent::__construct($message);
    }
}
