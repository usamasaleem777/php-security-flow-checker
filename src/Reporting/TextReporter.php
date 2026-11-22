<?php

declare(strict_types=1);

namespace SecurityFlow\Reporting;

final class TextReporter
{
    /** @return iterable<string> */
    public function lines(array $report, bool $verbose): iterable
    {
        if (isset($report['error'])) {
            yield 'ERROR — ' . $report['error'];
            return;
        }

        $model = $report['model'];
        $status = $report['status']->value;
        yield "$status — $model";
        yield sprintf(
            'Explored %d states and %d enabled transitions. Analysis complete %s.',
            $report['states_explored'],
            $report['transitions_explored'],
            $report['analysis_complete'] ? 'yes' : 'no',
        );
        if ($report['incomplete_reasons'] !== []) {
            yield 'Analysis limits hit — ' . implode(', ', $report['incomplete_reasons']);
        }
        foreach ($report['findings'] as $finding) {
            $trace = $finding['counterexample'];
            yield '';
            yield $finding['category'] . ' — ' . $finding['property_id'];
            yield $finding['explanation'];
            yield sprintf('Shortest trace of %d actions starts at %s.', $trace['length'], $trace['initial_state']);
            foreach ($trace['transitions'] as $index => $edge) {
                yield sprintf('  %d. %s → %s (%s)', $index + 1, $edge['from'], $edge['to'], $edge['action']);
                if ($verbose && get_object_vars($edge['changes']) !== []) {
                    yield '     Changes ' . json_encode($edge['changes'], JSON_THROW_ON_ERROR);
                }
            }
            if ($verbose) {
                yield 'Failed assertion ' . json_encode($finding['assertion'], JSON_THROW_ON_ERROR);
                yield 'Model evidence ' . json_encode($trace['violation'], JSON_THROW_ON_ERROR);
            }
            yield $finding['remediation'];
            yield 'Evidence comes from the supplied finite model.';
        }
    }
}
