<?php

declare(strict_types=1);

namespace SecurityFlow\Web;

use SecurityFlow\Reporting\JsonReporter;

final class ReportController
{
    public function download(array $run, mixed $format): Response
    {
        $report = $run['report'];
        $name = 'stateaxiom-' . $run['id'];
        return match ($format) {
            'json' => new Response(
                (new JsonReporter())->encode($report),
                'application/json; charset=utf-8',
                headers: ['Content-Disposition' => "attachment; filename=$name.json"],
            ),
            'counterexamples' => new Response(
                (new JsonReporter())->encode([
                    'model' => $report['model'],
                    'status' => $report['status'],
                    'analysis_complete' => $report['analysis_complete'],
                    'http_replay_status' => $report['http_replay_status'],
                    'finding_count' => $report['finding_count'] ?? count($report['findings']),
                    'payload_note' => $report['payload_note'] ?? null,
                    'findings' => $report['findings'],
                ]),
                'application/json; charset=utf-8',
                headers: ['Content-Disposition' => "attachment; filename=$name-counterexamples.json"],
            ),
            'html' => new Response($this->html($run), headers: ['Content-Disposition' => "attachment; filename=$name.html"]),
            default => throw new HttpError(400, 'Unsupported report format.'),
        };
    }

    public function html(array $run): string
    {
        $report = $run['report'];
        $contents = Response::escape((new JsonReporter())->encode($report));
        $model = Response::escape($report['model']);
        $status = Response::escape($report['status']);
        $created = Response::escape($run['created_at']);
        $replay = Response::escape($report['http_replay_status']);
        $coverage = $report['analysis_complete'] ? 'Complete' : 'Incomplete';
        $findings = '';
        foreach ($report['findings'] as $finding) {
            $id = Response::escape($finding['id']);
            $explanation = Response::escape($finding['explanation']);
            $remediation = Response::escape($finding['remediation']);
            $findings .= "<li><h3>$id</h3><p>$explanation</p><p>$remediation</p></li>";
        }
        $overview = $findings === '' ? '<p>No findings in this run.</p>' : "<ul>$findings</ul>";
        return <<<HTML
            <!doctype html><html lang="en"><head><meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>StateAxiom report - $model</title></head><body><main>
            <h1>$model</h1><p>Model result <strong>$status</strong></p>
            <dl><dt>Run time (UTC)</dt><dd>$created</dd><dt>Coverage</dt><dd>$coverage</dd>
            <dt>HTTP replay</dt><dd>$replay</dd></dl>
            <h2>Findings</h2>$overview<h2>Analyzer report</h2><pre>$contents</pre>
            </main></body></html>
            HTML;
    }
}
