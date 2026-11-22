<?php

declare(strict_types=1);

namespace SecurityFlow\Models;

final readonly class Action
{
    public function __construct(
        public string $from,
        public string $to,
        public string $action,
    ) {
    }
}
