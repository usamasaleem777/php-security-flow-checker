<?php

declare(strict_types=1);

use SecurityFlow\Web\DashboardController;
use SecurityFlow\Web\ModelCatalog;
use SecurityFlow\Web\ReportController;
use SecurityFlow\Web\RequestValidator;
use SecurityFlow\Web\RunStore;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../src/Web/bootstrap.php';

function securityCheck(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

$catalog = new ModelCatalog(__DIR__ . '/../examples');
$controller = new DashboardController($catalog);
$server = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => '127.0.0.1:8080', 'REQUEST_METHOD' => 'GET'];
$modelId = 'mfa-bypass-export-vulnerable';
$tests = [
    'Each dashboard route accepts only its own scalar fields' => static function () use ($controller, $server): void {
        $session = [];
        foreach ([
            ['api' => 'models', 'surplus' => '1'],
            ['api' => 'models', 'id' => 'sample'],
            ['api' => 'report', 'id' => 'r-sample', 'format' => ['json']],
            ['api' => 'model', 'id' => ['sample']],
        ] as $query) {
            securityCheck($controller->dispatch($server, $query, $session)->status === 400, 'Unexpected query was accepted.');
        }
    },
    'Encoded duplicate query names and oversized queries are rejected' => static function () use ($controller, $server, $modelId): void {
        $session = [];
        $query = ['api' => 'model', 'id' => $modelId];
        foreach (['api=model&id=test&%69d=' . $modelId, 'api=model&%20id=' . $modelId] as $raw) {
            $response = $controller->dispatch($server + ['QUERY_STRING' => $raw], $query, $session);
            securityCheck($response->status === 400, 'Ambiguous raw query was accepted.');
        }
        $over = 'api=model&id=' . str_repeat('x', RequestValidator::MAX_QUERY);
        securityCheck(
            $controller->dispatch($server + ['QUERY_STRING' => $over], $query, $session)->status === 414,
            'Oversized query was accepted.',
        );
        $valid = 'api=model&id=' . $modelId;
        securityCheck($controller->dispatch($server + ['QUERY_STRING' => $valid], $query, $session)->status === 200, 'Valid query was rejected.');
    },
    'A supplied browser origin must match even with a valid CSRF token' => static function () use ($controller, $server, $modelId): void {
        $session = [];
        $controller->dispatch($server, [], $session);
        $post = array_replace($server, [
            'REQUEST_METHOD' => 'POST',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $session['dashboard_csrf'],
        ]);
        $body = json_encode(['model' => $modelId, 'max_states' => 20, 'max_transitions' => 20, 'max_depth' => 8], JSON_THROW_ON_ERROR);
        $query = ['api' => 'run'];
        foreach (['http://127.0.0.1:8081', 'http://localhost:8080', 'null'] as $origin) {
            $response = $controller->dispatch($post + ['HTTP_ORIGIN' => $origin], $query, $session, $body);
            securityCheck($response->status === 403, 'Foreign origin was accepted.');
        }
        securityCheck(!isset($session['dashboard_runs']), 'A rejected request created a report.');
        $response = $controller->dispatch($post + ['HTTP_ORIGIN' => 'http://127.0.0.1:8080'], $query, $session, $body);
        securityCheck($response->status === 201, 'Same-origin verification failed.');
        $defaultPort = array_replace($post, ['HTTP_HOST' => 'localhost:80', 'HTTP_ORIGIN' => 'http://localhost']);
        securityCheck($controller->dispatch($defaultPort, $query, $session, $body)->status === 201, 'Default port origin failed.');
    },
    'The web entrypoint refuses an already active PHP session' => static function (): ?string {
        if (!extension_loaded('session')) {
            return 'The PHP session extension is unavailable.';
        }
        $store = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'stateaxiom-session-' . bin2hex(random_bytes(12));
        securityCheck(mkdir($store, 0700), 'Could not create an isolated PHP session directory.');
        try {
            $program = <<<'PHP'
                $_SERVER = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'localhost', 'REQUEST_METHOD' => 'GET'];
                require $argv[1];
                exit(http_response_code() === 503 ? 0 : 1);
                PHP;
            $process = proc_open([
                PHP_BINARY, '-n', '-d', 'session.auto_start=1', '-d', "session.save_path=$store",
                '-r', $program, '--', __DIR__ . '/../public/index.php',
            ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => STDERR], $pipes, options: ['bypass_shell' => true]);
            securityCheck(is_resource($process), 'Could not start the PHP probe.');
            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            securityCheck(proc_close($process) === 0 && $output !== false, 'An already active session was accepted.');
            $error = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
            securityCheck(str_contains($error['error'] ?? '', 'session.auto_start'), 'Expected an actionable session configuration error.');
            return null;
        } finally {
            foreach (new FilesystemIterator($store, FilesystemIterator::SKIP_DOTS) as $entry) {
                if (!$entry->isLink() && $entry->isFile() && str_starts_with($entry->getFilename(), 'sess_')) {
                    unlink($entry->getPathname());
                }
            }
            rmdir($store);
        }
    },
    'Web exports bound indentation and preserve report fields' => static function (): void {
        $nested = 'text';
        for ($i = 0; $i < 64; ++$i) {
            $nested = [$nested];
        }
        $report = [
            'model' => 'large-fixture', 'status' => 'VIOLATED', 'analysis_complete' => true,
            'http_replay_status' => 'NOT_RUN', 'findings' => [], 'additional' => array_fill(0, 350, $nested),
        ];
        $run = ['report' => $report, 'id' => 'r-sample', 'created_at' => '2026-11-22T00:00:00+00:00'];
        $controller = new ReportController();
        $export = $controller->download($run, 'json');
        securityCheck(strlen($export->body) <= RunStore::MAX_BYTES, 'JSON output exceeded the history byte cap.');
        securityCheck(json_decode($export->body, true, flags: JSON_THROW_ON_ERROR) === $report, 'Export changed analyzer data.');
        securityCheck(strlen($controller->download($run, 'html')->body) < 2 * RunStore::MAX_BYTES, 'HTML formatting amplified the fixture.');
    },
    'Configured Apache access directives guard the repository subtree' => static function (): void {
        $root = file_get_contents(__DIR__ . '/../.htaccess');
        $public = file_get_contents(__DIR__ . '/../public/.htaccess');
        securityCheck($root !== false && preg_match('/^Require all denied$/m', str_replace("\r", '', $root)) === 1, 'Missing repository default deny.');
        securityCheck(
            $public !== false && preg_match('/^Require local$/m', str_replace("\r", '', $public)) === 1,
            'Missing Apache loopback exception for public.',
        );
    },
    'The canonical catalog rejects an alias within its approved directory' => static function () use ($modelId): ?string {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'stateaxiom-catalog-' . bin2hex(random_bytes(12));
        securityCheck(mkdir($base, 0700), 'Could not allocate test catalog.');
        $directory = $base . DIRECTORY_SEPARATOR . 'mfa-bypass';
        $path = $directory . DIRECTORY_SEPARATOR . 'export-vulnerable.json';
        $other = $directory . DIRECTORY_SEPARATOR . 'other.json';
        try {
            securityCheck(mkdir($directory, 0700), 'Could not allocate test family.');
            securityCheck(copy(__DIR__ . '/../examples/mfa-bypass/export-vulnerable.json', $other), 'Could not create test fixture.');
            if (!function_exists('symlink') || !@symlink($other, $path)) {
                return 'Filesystem does not allow creating a file symlink.';
            }
            try {
                (new ModelCatalog($base))->load($modelId);
            } catch (RuntimeException) {
                return null;
            }
            throw new RuntimeException('Catalog accepted an aliased approved file.');
        } finally {
            if (is_link($path)) {
                unlink($path);
            }
            if (is_file($other)) {
                unlink($other);
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
            rmdir($base);
        }
    },
];

$passed = $skipped = 0;
foreach ($tests as $name => $check) {
    try {
        $reason = $check();
        if ($reason !== null) {
            ++$skipped;
            echo "SKIP $name. $reason", PHP_EOL;
            continue;
        }
        ++$passed;
        echo "PASS $name", PHP_EOL;
    } catch (Throwable $error) {
        fwrite(STDERR, "FAIL $name" . PHP_EOL . $error->getMessage() . PHP_EOL);
    }
}
$total = count($tests);
echo "$passed/$total security groups passed, $skipped skipped.", PHP_EOL;
exit((int) ($passed + $skipped !== $total));
