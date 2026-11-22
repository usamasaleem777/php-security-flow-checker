<?php

declare(strict_types=1);

namespace SecurityFlow\Web;

final class RunStore
{
    public const MAX_RUNS = 12;
    public const MAX_BYTES = 262_144;

    private array $session;

    public function __construct(array &$session)
    {
        $this->session = &$session;
    }

    public function save(array $run): array
    {
        if (strlen(json_encode($run, JSON_THROW_ON_ERROR)) > self::MAX_BYTES) {
            $run = $this->limitPayload($run);
        }
        $runs = $this->session['dashboard_runs'] ?? [];
        $this->session['dashboard_runs'] = array_slice([$run['id'] => $run] + $runs, 0, self::MAX_RUNS, true);
        return $run;
    }

    public function get(string $id): array
    {
        return $this->session['dashboard_runs'][$id] ?? throw new HttpError(404, 'The report is not available in this session.');
    }

    public function summarize(): array
    {
        $runs = [];
        $metrics = [
            'runs' => 0,
            'verified_properties' => 0,
            'violated_properties' => 0,
            'incomplete' => 0,
            'errors' => 0,
            'categories' => [],
        ];
        foreach ($this->session['dashboard_runs'] ?? [] as $run) {
            $report = $run['report'];
            ++$metrics['runs'];
            $violated = $report['finding_count'] ?? count($report['findings']);
            $metrics['violated_properties'] += $violated;
            if ($report['analysis_complete']) {
                $metrics['verified_properties'] += ($run['property_count'] ?? count($run['model']['properties'])) - $violated;
            }
            $metrics['incomplete'] += (int) (!$report['analysis_complete'] && $report['status'] !== 'ERROR');
            $metrics['errors'] += (int) ($report['status'] === 'ERROR');
            foreach ($run['finding_categories'] ?? $this->categories($report['findings']) as $category => $count) {
                $metrics['categories'][$category] = ($metrics['categories'][$category] ?? 0) + $count;
            }
            $runs[] = [
                'id' => $run['id'],
                'created_at' => $run['created_at'],
                'model' => $report['model'],
                'status' => $report['status'],
                'findings' => $violated,
                'states' => $report['states_explored'] ?? null,
                'analysis_complete' => $report['analysis_complete'],
            ];
        }
        ksort($metrics['categories']);
        $metrics['categories'] = (object) $metrics['categories'];
        return ['runs' => $runs, 'metrics' => $metrics];
    }

    private function limitPayload(array $run): array
    {
        $report = $run['report'];
        $retained = array_intersect_key($run, array_flip(['id', 'created_at', 'catalog_id']));
        $retained['property_count'] = count($run['model']['properties'] ?? []);
        $report['payload_note'] = 'The web payload limit omitted the graph and mathematical detail. Use the CLI for full output.';
        $retained['report'] = $report;
        if (strlen(json_encode($retained, JSON_THROW_ON_ERROR)) <= self::MAX_BYTES) {
            return $retained;
        }

        $findings = $report['findings'];
        $report['finding_count'] = count($findings);
        $retained['finding_categories'] = $this->categories($findings);
        $report['findings'] = [];
        if ($findings !== []) {
            $finding = $findings[0];
            $finding['assertion'] = null;
            $trace = $finding['counterexample'];
            $trace['transitions'] = array_map(static fn (array $edge): array => [
                'from' => $edge['from'],
                'to' => $edge['to'],
                'action' => $edge['action'],
                'changes_omitted' => true,
            ], $trace['transitions']);
            unset($trace['violation']['facts'], $trace['violation']['pre'], $trace['violation']['post']);
            $finding['counterexample'] = $trace;
            $report['findings'][] = $finding;
        }
        $report['payload_note'] = 'The web payload limit retained only the first witness and omitted detailed facts. Use the CLI for full output.';
        $retained['report'] = $report;
        return $retained;
    }

    private function categories(array $findings): array
    {
        $counts = [];
        foreach ($findings as $finding) {
            $category = $finding['category'];
            $counts[$category] = ($counts[$category] ?? 0) + 1;
        }
        return $counts;
    }
}
