<?php

declare(strict_types=1);

use SecurityFlow\Models\TransitionSystem;
use SecurityFlow\Visualization\GraphSerializer;
use SecurityFlow\Web\DashboardController;
use SecurityFlow\Web\ModelCatalog;
use SecurityFlow\Web\ReportController;
use SecurityFlow\Web\Response;
use SecurityFlow\Web\RunStore;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../src/Web/bootstrap.php';

const SAMPLE = 'mfa-bypass-export-vulnerable';
const CORRECTED = 'mfa-bypass-export-corrected';

function expect(mixed $expected, mixed $observed): void
{
    if ($expected !== $observed) {
        throw new RuntimeException('Expected ' . var_export($expected, true) . ', observed ' . var_export($observed, true));
    }
}

function localRequest(string $method = 'GET'): array
{
    return ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => '127.0.0.1:8184', 'REQUEST_METHOD' => $method];
}

function payload(Response $response): array
{
    return json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
}

function verifyModel(DashboardController $app, array &$session, array $overrides = []): Response
{
    $app->dispatch(localRequest(), [], $session);
    return $app->dispatch(
        localRequest('POST') + ['HTTP_X_CSRF_TOKEN' => $session['dashboard_csrf'], 'CONTENT_TYPE' => 'application/json'],
        ['api' => 'run'],
        $session,
        json_encode($overrides + ['model' => SAMPLE, 'max_states' => 500, 'max_transitions' => 2000, 'max_depth' => 64]),
    );
}

$catalog = new ModelCatalog(__DIR__ . '/../examples');
$app = new DashboardController($catalog);
$tests = [
    'Dashboard and API routing' => static function () use ($app): void {
        $session = [];
        $dashboard = $app->dispatch(localRequest(), [], $session);
        expect(200, $dashboard->status);
        expect(true, str_contains($dashboard->body, 'id="graph-svg"'));
        expect(64, strlen($session['dashboard_csrf']));
        expect(true, str_contains($dashboard->body, $session['dashboard_csrf']));
        expect(404, $app->dispatch(localRequest(), ['api' => 'missing'], $session)->status);
        expect(405, $app->dispatch(localRequest('PUT'), ['api' => 'run'], $session)->status);
        expect(405, $app->dispatch(localRequest('POST'), ['api' => 'models'], $session)->status);
    },
    'Catalog lists approved vulnerable and corrected pairs plus Phase 1' => static function () use ($app): void {
        $session = [];
        $models = payload($app->dispatch(localRequest(), ['api' => 'models'], $session))['models'];
        expect(14, count($models));
        expect(7, count(array_unique(array_column($models, 'category'))));
        foreach ($models as $model) {
            expect(false, array_key_exists('file', $model));
            $entry = $app->dispatch(localRequest(), ['api' => 'model', 'id' => $model['id']], $session);
            expect(200, $entry->status);
            expect($model['id'], payload($entry)['meta']['id']);
        }
    },
    'Model inspection returns declarations without fabricated evaluation' => static function () use ($app): void {
        $session = [];
        $data = payload($app->dispatch(localRequest(), ['api' => 'model', 'id' => SAMPLE], $session));
        expect(['signed_out'], $data['model']['initial']);
        expect('boolean', $data['model']['domains']['mfa_verified']);
        expect(3, $data['model']['actions']['export']);
        expect(null, $data['graph']['reached_count']);
        expect('unassessed', $data['graph']['nodes'][0]['status']);
    },
    'Real verification distinguishes vulnerable and corrected models' => static function () use ($app): void {
        $session = [];
        $result = verifyModel($app, $session);
        expect(201, $result->status);
        $run = payload($result);
        expect('VIOLATED', $run['report']['status']);
        expect(3, $run['report']['states_explored']);
        expect('MFA_BYPASS', $run['report']['findings'][0]['category']);
        $corrected = payload(verifyModel($app, $session, ['model' => CORRECTED]));
        expect('VERIFIED', $corrected['report']['status']);
        expect([], $corrected['report']['findings']);
        expect($run['model']['properties'], $corrected['model']['properties']);
    },
    'All three exports are generated from stored analysis' => static function () use ($app): void {
        $session = [];
        $run = payload(verifyModel($app, $session));
        foreach (['json', 'html', 'counterexamples'] as $format) {
            $response = $app->dispatch(localRequest(), ['api' => 'report', 'id' => $run['id'], 'format' => $format], $session);
            expect(200, $response->status);
            expect(true, str_starts_with($response->headers['Content-Disposition'], 'attachment;'));
            if ($format === 'html') {
                expect(true, str_contains($response->body, 'NOT_RUN'));
                expect(true, str_contains($response->body, $run['report']['findings'][0]['id']));
            } else {
                expect($run['report']['findings'], payload($response)['findings']);
            }
        }
    },
    'Graph identifies exact witness among parallel transitions' => static function () use ($app): void {
        $session = [];
        $run = payload(verifyModel($app, $session));
        $finding = $run['report']['findings'][0];
        $path = $run['graph']['paths'][$finding['property_id']];
        $edges = array_column($run['graph']['edges'], null, 'id');
        expect(2, $finding['counterexample']['length']);
        expect(2, count($path));
        expect('signed_out', $edges[$path[0]]['from']);
        expect('begin_session', $edges[$path[0]]['action']);
        expect('export', $edges[$path[1]]['action']);
        expect(true, $edges[$path[1]]['succeeded']);
        expect('violating', $edges[$path[1]]['status']);
        $nodeDepths = array_column($run['graph']['nodes'], 'depth', 'id');
        expect(['signed_out' => 0, 'signed_in' => 1, 'verified' => 2], $nodeDepths);
    },
    'Neighborhoods preserve real guards and allow known states only' => static function () use ($app): void {
        $session = [];
        $run = payload(verifyModel($app, $session, ['model' => CORRECTED]));
        $data = $app->dispatch(localRequest(), ['api' => 'graph', 'id' => $run['id'], 'focus' => 'signed_in'], $session);
        expect(200, $data->status);
        $graph = payload($data);
        expect('signed_in', $graph['focus']);
        expect(1, count(array_filter($graph['edges'], static fn (array $edge): bool => $edge['status'] === 'disabled')));
        expect(404, $app->dispatch(localRequest(), ['api' => 'graph', 'id' => $run['id'], 'focus' => 'missing'], $session)->status);
    },
    'Mathematics consumes actual layers, base cases, preservation and operands' => static function () use ($app): void {
        $session = [];
        $report = payload(verifyModel($app, $session));
        $math = $report['mathematics'];
        expect(true, $math['fixed_point_reached']);
        expect([1, 1, 1], array_column($math['iterations'], 'new_states'));
        $evaluation = $math['properties'][0]['example'];
        expect(false, $evaluation['result']);
        expect(['expression' => 'mfa_verified', 'value' => false], $evaluation['operands'][1]);
        $legacy = payload(verifyModel($app, $session, ['model' => 'mfa-bypass-mfa-vulnerable']));
        $proof = $legacy['mathematics']['properties'][0]['induction'];
        expect(['checked' => 1, 'failed' => 0, 'total' => 1], $proof['base']);
        expect(1, $proof['preservation']['failed']);
    },
    'Missing, malformed and traversal model identifiers are rejected' => static function () use ($app): void {
        $session = [];
        foreach (['../README', 'mfa-bypass/export', '%2e%2e%2fREADME', ['id'], 'https://example.test/x'] as $id) {
            expect(400, $app->dispatch(localRequest(), ['api' => 'model', 'id' => $id], $session)->status);
        }
        expect(404, $app->dispatch(localRequest(), ['api' => 'model', 'id' => 'unknown-model'], $session)->status);
        expect(404, verifyModel($app, $session, ['model' => 'unknown-model'])->status);
        expect([], payload($app->dispatch(localRequest(), ['api' => 'runs'], $session))['runs']);
    },
    'Loopback and safe Host are required' => static function () use ($app): void {
        $session = [];
        $unsafeHost = array_replace(localRequest(), ['HTTP_HOST' => 'external.invalid']);
        $remoteAddress = array_replace(localRequest(), ['REMOTE_ADDR' => '192.0.2.2']);
        expect(403, $app->dispatch($unsafeHost, [], $session)->status);
        expect(403, $app->dispatch($remoteAddress, [], $session)->status);
    },
    'HTML report escapes markup in model and witness text' => static function () use ($app): void {
        $session = [];
        $run = payload(verifyModel($app, $session));
        $run['report']['model'] = '<b title="text">example</b>';
        $run['report']['findings'][0]['explanation'] = '<b>assertion</b>';
        $html = (new ReportController())->html($run);
        expect(false, str_contains($html, '<b>assertion</b>'));
        expect(true, str_contains($html, '&lt;b&gt;assertion&lt;/b&gt;'));
        expect(true, str_contains($html, '&quot;text&quot;'));
    },
    'CSRF, request limits, strict JSON and content type are enforced' => static function () use ($app): void {
        $session = [];
        $app->dispatch(localRequest(), [], $session);
        $post = localRequest('POST') + ['CONTENT_TYPE' => 'application/json'];
        expect(403, $app->dispatch($post, ['api' => 'run'], $session, '{}')->status);
        $post['HTTP_X_CSRF_TOKEN'] = $session['dashboard_csrf'];
        expect(413, $app->dispatch($post, ['api' => 'run'], $session, str_repeat('x', 4097))->status);
        expect(400, $app->dispatch($post, ['api' => 'run'], $session, '{"model":"a","model":"b"}')->status);
        expect(415, $app->dispatch(array_replace($post, ['CONTENT_TYPE' => 'text/plain']), ['api' => 'run'], $session, '{}')->status);
        expect(400, verifyModel($app, $session, ['max_transitions' => 2001])->status);
        expect(400, verifyModel($app, $session, ['max_states' => '5'])->status);
    },
    'Budgets report incomplete coverage and keep a witnessed violation' => static function () use ($app): void {
        $session = [];
        $limited = payload(verifyModel($app, $session, ['max_transitions' => 1]))['report'];
        expect('INCOMPLETE', $limited['status']);
        expect(['transition_limit'], $limited['incomplete_reasons']);
        expect(1, $limited['transitions_considered']);
        $limit = payload(verifyModel($app, $session, ['max_transitions' => 4]))['report'];
        expect('VIOLATED', $limit['status']);
        expect(false, $limit['analysis_complete']);
        expect(2, $limit['findings'][0]['counterexample']['length']);
        $exact = payload(verifyModel($app, $session, ['max_transitions' => 5]))['report'];
        expect(true, $exact['analysis_complete']);
        $stateLimit = payload(verifyModel($app, $session, ['max_states' => 1]))['report'];
        expect('INCOMPLETE', $stateLimit['status']);
        expect(['state_limit'], $stateLimit['incomplete_reasons']);
    },
    'Graph and run payload bounds preserve honest truncation and evidence' => static function () use ($app): void {
        $states = [];
        $edges = [];
        for ($i = 0; $i < 100; ++$i) {
            $states[] = ['id' => 's' . $i, 'facts' => (object) []];
            if ($i > 0) {
                $edges[] = ['from' => 's' . ($i - 1), 'to' => 's' . $i, 'action' => 'advance'];
            }
        }
        $model = TransitionSystem::fromJson(json_encode([
            'name' => 'graph-bound', 'facts' => [], 'initial' => ['s0'], 'states' => $states,
            'transitions' => $edges, 'invariant' => true,
        ], JSON_THROW_ON_ERROR));
        $graph = (new GraphSerializer())->view($model, focus: 's99');
        expect(true, $graph['truncated']);
        expect(100, $graph['state_count']);
        expect(GraphSerializer::MAX_NODES, count($graph['nodes']));
        expect(true, in_array('s99', array_column($graph['nodes'], 'id'), true));

        $session = [];
        $run = payload(verifyModel($app, $session));
        $run['report']['findings'][0]['counterexample']['violation']['pre']['large'] = str_repeat('x', RunStore::MAX_BYTES);
        $saved = (new RunStore($session))->save($run);
        expect('VIOLATED', $saved['report']['status']);
        expect(2, $saved['report']['findings'][0]['counterexample']['length']);
        expect(true, isset($saved['report']['payload_note']));
        expect(true, strlen(json_encode($saved)) < RunStore::MAX_BYTES);
    },
    'Empty history, session separation and runtime evidence remain accurate' => static function () use ($app): void {
        $session = [];
        $empty = payload($app->dispatch(localRequest(), ['api' => 'runs'], $session));
        expect([], $empty['runs']);
        expect(0, $empty['metrics']['runs']);
        $run = payload(verifyModel($app, $session));
        expect('NOT_RUN', $run['report']['http_replay_status']);
        expect(false, $run['report']['runtime_reproduced']);
        expect('MODEL_COUNTEREXAMPLE', $run['report']['findings'][0]['evidence_type']);
        $another = [];
        expect(404, $app->dispatch(localRequest(), ['api' => 'run', 'id' => $run['id']], $another)->status);
        for ($i = 0; $i < RunStore::MAX_RUNS; ++$i) {
            verifyModel($app, $session, ['model' => CORRECTED]);
        }
        $history = payload($app->dispatch(localRequest(), ['api' => 'runs'], $session));
        expect(RunStore::MAX_RUNS, $history['metrics']['runs']);
        expect(RunStore::MAX_RUNS, $history['metrics']['verified_properties']);
        expect(404, $app->dispatch(localRequest(), ['api' => 'run', 'id' => $run['id']], $session)->status);
    },
    'Archived fixture checks remain separate from model metrics and HTTP reproduction' => static function () use ($app): void {
        $session = [];
        $response = $app->dispatch(localRequest(), ['api' => 'fixture-evidence'], $session);
        expect(200, $response->status);
        $fixture = payload($response)['evidence'];
        expect('CORRECTED_FIXTURE_CHECK', $fixture['evidence_type']);
        expect('NOT_RUN', $fixture['http_replay_status']);
        expect('PASS', $fixture['status']);
        expect(6, $fixture['passed']);
        expect(0, $fixture['failed']);
        expect(0, payload($app->dispatch(localRequest(), ['api' => 'runs'], $session))['metrics']['runs']);
    },
];

$passed = 0;
foreach ($tests as $description => $check) {
    try {
        $check();
        echo "PASS $description", PHP_EOL;
        ++$passed;
    } catch (Throwable $error) {
        fwrite(STDERR, "FAIL $description" . PHP_EOL . $error->getMessage() . PHP_EOL);
    }
}
$total = count($tests);
echo "$passed/$total dashboard tests passed.", PHP_EOL;
exit((int) ($passed !== $total));
