<?php

declare(strict_types=1);

namespace SecurityFlow\Models;

final readonly class State
{
    /** @param array<string, bool> $facts */
    public function __construct(public string $id, public array $facts)
    {
    }
}
