<?php

declare(strict_types=1);

namespace SecurityFlow\Reporting;

final class JsonReporter
{
    public function encode(array $report): string
    {
        return $this->encodeValue($report);
    }

    public function write(array $report): void
    {
        if (($report['findings'] ?? []) === []) {
            echo $this->encode($report), PHP_EOL;
            return;
        }

        // Findings can share a long trace. Emit them individually to bound serialization memory.
        $separator = '';
        echo "{\n";
        foreach ($report as $key => $value) {
            echo $separator, '    ', $this->encodeValue($key), ': ';
            if ($key === 'findings') {
                $this->writeFindings($value);
            } else {
                echo str_replace("\n", "\n    ", $this->encodeValue($value));
            }
            $separator = ",\n";
        }
        echo "\n}", PHP_EOL;
    }

    private function writeFindings(array $findings): void
    {
        $separator = "\n";
        echo '[';
        foreach ($findings as $finding) {
            echo $separator, '        ', str_replace("\n", "\n        ", $this->encodeValue($finding));
            $separator = ",\n";
        }
        echo "\n    ]";
    }

    private function encodeValue(mixed $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }
}
