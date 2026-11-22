<?php

declare(strict_types=1);

use SecurityFlow\Tests\Fixtures\CorrectedWorkflow;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/fixtures/CorrectedWorkflow.php';

/** @return array{0: string, 1: mixed} */
function withFixtureSession(string $directory, ?string $id, callable $observe): array
{
    if (session_status() !== PHP_SESSION_NONE || @session_id($id ?? '') === false) {
        throw new RuntimeException('Could not initialize an isolated fixture session.');
    }
    if (!@session_start([
        'save_handler' => 'files',
        'save_path' => $directory,
        'use_cookies' => 0,
        'use_strict_mode' => 1,
        'use_trans_sid' => 0,
        'cache_limiter' => '',
        'gc_probability' => 0,
    ])) {
        throw new RuntimeException('Could not start the fixture session.');
    }

    try {
        $activeId = session_id();
        if ($activeId === '' || ($id !== null && $id !== $activeId)) {
            throw new RuntimeException('Could not reopen the intended fixture session.');
        }
        return [$activeId, $observe()];
    } finally {
        if (!@session_write_close()) {
            throw new RuntimeException('Could not persist the fixture session.');
        }
    }
}

function removeSessionDirectory(string $directory): bool
{
    if (is_link($directory)) {
        return false;
    }
    foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $file) {
        if ($file->isLink() || !$file->isFile() || !str_starts_with($file->getFilename(), 'sess_')) {
            return false;
        }
        if (!@unlink($file->getPathname())) {
            return false;
        }
    }
    return @rmdir($directory) && !is_dir($directory);
}

function controlResult(string $id, array $expected, array $observed): array
{
    return [
        'id' => $id,
        'status' => $expected === $observed ? 'PASS' : 'FAIL',
        'expected' => $expected,
        'observed' => $observed,
    ];
}

$report = [
    'fixture' => 'corrected-controls',
    'evidence_type' => 'PHP_CONTROL_ASSERTIONS',
    'http_replay_status' => 'NOT_RUN',
    'runtime_reproduced' => false,
    'checks' => [],
];

if (!extension_loaded('session')) {
    $report['status'] = 'SKIPPED';
    $report['reason'] = 'The PHP session extension is required for the corrected controls suite.';
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
    exit(0);
}

$checks = [
    'mfa-required' => [
        ['before_mfa' => null, 'exports_before_mfa' => 0, 'after_mfa' => 'synthetic-export-record', 'exports' => 1],
        static function (string $directory): array {
            $fixture = new CorrectedWorkflow();
            [$id, $before] = withFixtureSession($directory, null, static function () use ($fixture): array {
                $fixture->setAuthenticatedTestState();
                $before = $fixture->export();
                return ['before_mfa' => $before, 'exports_before_mfa' => $fixture->effects()['exports']];
            });
            [, $after] = withFixtureSession($directory, $id, static function () use ($fixture): ?string {
                $fixture->recordVerifiedTestFactor();
                return $fixture->export();
            });
            return $before + ['after_mfa' => $after, 'exports' => $fixture->effects()['exports']];
        },
    ],
    'session-revocation' => [
        ['before_revoke' => 'synthetic-export-record', 'after_revoke' => null, 'exports' => 1],
        static function (string $directory): array {
            $fixture = new CorrectedWorkflow();
            [$id, $before] = withFixtureSession($directory, null, static function () use ($fixture): ?string {
                $fixture->setAuthenticatedTestState();
                $fixture->recordVerifiedTestFactor();
                $export = $fixture->export();
                $fixture->revokeSession();
                return $export;
            });
            [, $after] = withFixtureSession($directory, $id, $fixture->export(...));
            return ['before_revoke' => $before, 'after_revoke' => $after, 'exports' => $fixture->effects()['exports']];
        },
    ],
    'permission-revocation' => [
        ['before_revoke' => true, 'after_revoke' => false, 'record_revision' => 1],
        static function (string $directory): array {
            $fixture = new CorrectedWorkflow();
            $fixture->setPermission(true);
            [$id, $before] = withFixtureSession($directory, null, static function () use ($fixture): bool {
                $fixture->setAuthenticatedTestState();
                return $fixture->updateProtectedRecord();
            });
            $fixture->setPermission(false);
            [, $after] = withFixtureSession($directory, $id, $fixture->updateProtectedRecord(...));
            return [
                'before_revoke' => $before,
                'after_revoke' => $after,
                'record_revision' => $fixture->effects()['record_revision'],
            ];
        },
    ],
    'token-single-use' => [
        ['wrong_token' => false, 'first_use' => true, 'second_use' => false, 'reset_revision' => 1],
        static function (string $directory): array {
            $fixture = new CorrectedWorkflow();
            [$id, $token] = withFixtureSession($directory, null, $fixture->issueTestToken(...));
            $wrongToken = ($token[0] === '0' ? '1' : '0') . substr($token, 1);
            [, $wrong] = withFixtureSession($directory, $id, static fn (): bool => $fixture->consumeTestToken($wrongToken));
            [, $first] = withFixtureSession($directory, $id, static fn (): bool => $fixture->consumeTestToken($token));
            [, $second] = withFixtureSession($directory, $id, static fn (): bool => $fixture->consumeTestToken($token));
            return [
                'wrong_token' => $wrong,
                'first_use' => $first,
                'second_use' => $second,
                'reset_revision' => $fixture->effects()['reset_revision'],
            ];
        },
    ],
    'session-isolation' => [
        ['distinct_sessions' => true, 'other_session' => null, 'original_session' => 'synthetic-export-record', 'exports' => 1],
        static function (string $directory): array {
            $fixture = new CorrectedWorkflow();
            [$originalId] = withFixtureSession($directory, null, static function () use ($fixture): void {
                $fixture->setAuthenticatedTestState();
                $fixture->recordVerifiedTestFactor();
            });
            [$otherId, $other] = withFixtureSession($directory, null, $fixture->export(...));
            [, $original] = withFixtureSession($directory, $originalId, $fixture->export(...));
            return [
                'distinct_sessions' => $originalId !== $otherId,
                'other_session' => $other,
                'original_session' => $original,
                'exports' => $fixture->effects()['exports'],
            ];
        },
    ],
];

$createdDirectory = null;
$hadError = false;
try {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'stateaxiom-controls-' . bin2hex(random_bytes(16));
    if (!@mkdir($directory, 0700)) {
        throw new RuntimeException('Could not create the fixture session store.');
    }
    $createdDirectory = $directory;

    foreach ($checks as $id => [$expected, $observe]) {
        try {
            $report['checks'][] = controlResult($id, $expected, $observe($directory));
        } catch (Throwable) {
            $hadError = true;
            $report['checks'][] = ['id' => $id, 'status' => 'ERROR', 'reason' => 'Could not collect fixture observations.'];
        }
    }
} catch (Throwable) {
    $hadError = true;
    $report['reason'] = 'Could not initialize the fixture session store.';
} finally {
    if ($createdDirectory !== null) {
        try {
            $removed = removeSessionDirectory($createdDirectory);
        } catch (Throwable) {
            $removed = false;
        }
        $report['checks'][] = controlResult('session-store-cleanup', ['removed' => true], ['removed' => $removed]);
    }
}

$failed = count(array_filter($report['checks'], static fn (array $check): bool => $check['status'] !== 'PASS'));
$report['status'] = $hadError ? 'ERROR' : ($failed === 0 ? 'PASS' : 'FAIL');
$report['passed'] = count($report['checks']) - $failed;
$report['failed'] = $failed;
echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
exit($hadError ? 3 : ($failed === 0 ? 0 : 1));
