<?php

declare(strict_types=1);

namespace SecurityFlow\Web;

use InvalidArgumentException;
use SecurityFlow\Models\ModelJson;
use stdClass;

final class FixtureEvidence
{
    public function read(string $path): ?array
    {
        $json = @file_get_contents($path, length: 16_385);
        if ($json === false || strlen($json) > 16_384) {
            return null;
        }
        try {
            $archive = ModelJson::object(ModelJson::decode($json), ['php_version', 'report'], 'fixture evidence');
            $report = $archive['report'];
            if (
                !is_string($archive['php_version'])
                || !$report instanceof stdClass
                || ($report->evidence_type ?? null) !== 'PHP_CONTROL_ASSERTIONS'
                || ($report->fixture ?? null) !== 'corrected-controls'
                || ($report->http_replay_status ?? null) !== 'NOT_RUN'
                || ($report->runtime_reproduced ?? null) !== false
                || !is_array($report->checks ?? null)
                || $report->checks === []
            ) {
                return null;
            }
            $checks = [];
            $passed = 0;
            foreach ($report->checks as $check) {
                $item = ModelJson::object($check, ['id', 'status', 'expected', 'observed'], 'fixture check');
                $id = ModelJson::identifier($item['id'], 'check identifier');
                if (!in_array($item['status'], ['PASS', 'FAIL'], true) || isset($checks[$id])) {
                    return null;
                }
                $matches = json_encode($item['expected'], JSON_THROW_ON_ERROR) === json_encode($item['observed'], JSON_THROW_ON_ERROR);
                if (($item['status'] === 'PASS') !== $matches) {
                    return null;
                }
                $passed += (int) ($item['status'] === 'PASS');
                $checks[$id] = $item;
            }
            $failed = count($checks) - $passed;
            $status = $failed === 0 ? 'PASS' : 'FAIL';
            if (($report->passed ?? null) !== $passed || ($report->failed ?? null) !== $failed || ($report->status ?? null) !== $status) {
                return null;
            }
            return [
                'evidence_type' => 'CORRECTED_FIXTURE_CHECK',
                'status' => $status,
                'php_version' => $archive['php_version'],
                'passed' => $passed,
                'failed' => $failed,
                'checks' => array_values($checks),
                'http_replay_status' => 'NOT_RUN',
            ];
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
