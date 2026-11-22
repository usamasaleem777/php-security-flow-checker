<?php

declare(strict_types=1);

namespace SecurityFlow\Security;

enum Outcome: string
{
    case VERIFIED = 'VERIFIED';
    case VIOLATED = 'VIOLATED';
    case INCOMPLETE = 'INCOMPLETE';
    case ERROR = 'ERROR';

    public function exitCode(): int
    {
        return match ($this) {
            self::VERIFIED => 0,
            self::VIOLATED => 1,
            self::INCOMPLETE => 2,
            self::ERROR => 3,
        };
    }
}
