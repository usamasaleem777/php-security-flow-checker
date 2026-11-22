<?php

declare(strict_types=1);

namespace SecurityFlow\Models;

use SecurityFlow\Mathematics\BooleanLogic;

final readonly class Action
{
    public function __construct(
        public string $from,
        public string $to,
        public string $action,
        public ?BooleanLogic $guard = null,
        public ?bool $succeeded = null,
    ) {
    }

    /** @return array{from: string, to: string, action: string} */
    public function describe(): array
    {
        return ['from' => $this->from, 'to' => $this->to, 'action' => $this->action];
    }
}
