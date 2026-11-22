<?php

declare(strict_types=1);

namespace SecurityFlow\Reporting;

final class JsonReporter
{
    public function encode(array $report): string
    {
        return json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }
}
