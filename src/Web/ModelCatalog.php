<?php

declare(strict_types=1);

namespace SecurityFlow\Web;

use RuntimeException;
use SecurityFlow\Models\TransitionSystem;

final readonly class ModelCatalog
{
    private const MAX_MODEL_BYTES = 98_304;
    private const FAMILIES = [
        ['mfa-bypass/export', 'MFA bypass', 'A sensitive export requires an authenticated session and verified MFA.'],
        ['authorization/permission', 'Authorization bypass', 'A protected update requires authentication and the current permission.'],
        ['session-revocation/session', 'Session revocation', 'A newly authorized export requires an active session.'],
        ['authorization/revocation', 'Permission revocation', 'A protected update must respect permission revocation.'],
        ['password-reset/replay', 'One-time operation replay', 'A single-use acceptance requires an unconsumed resource.'],
        ['approval-workflow/approval', 'Workflow precondition bypass', 'Publishing requires the modeled approval conditions.'],
        ['mfa-bypass/mfa', 'MFA state invariant', 'Protected access implies both authentication factors in the Phase 1 model.'],
    ];

    public function __construct(private string $directory)
    {
    }

    public function entries(): array
    {
        return array_values(array_map(
            static function (array $entry): array {
                unset($entry['file']);
                return $entry;
            },
            $this->index(),
        ));
    }

    /** @return array{meta: array, model: TransitionSystem, fingerprint: string} */
    public function load(string $id): array
    {
        $entry = $this->index()[$id] ?? throw new HttpError(404, 'The requested model is outside the approved catalog.');
        $root = realpath($this->directory);
        if ($root === false) {
            throw new RuntimeException('The catalog directory is unavailable.');
        }
        $expected = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $entry['file']);
        $path = realpath($expected);
        $samePath = $path !== false && (PHP_OS_FAMILY === 'Windows' ? strcasecmp($path, $expected) === 0 : $path === $expected);
        if (!$samePath || $path === false || !is_file($path)) {
            throw new RuntimeException('The catalog file is unavailable.');
        }
        $json = @file_get_contents($path, length: self::MAX_MODEL_BYTES + 1);
        if ($json === false || strlen($json) > self::MAX_MODEL_BYTES) {
            throw new RuntimeException('The catalog file could not be read within the web model size limit.');
        }
        $model = TransitionSystem::fromJson($json);
        unset($entry['file']);

        return [
            'meta' => $entry + ['name' => $model->name],
            'model' => $model,
            'fingerprint' => hash('sha256', $json),
        ];
    }

    private function index(): array
    {
        $catalog = [];
        foreach (self::FAMILIES as [$prefix, $category, $description]) {
            foreach (['vulnerable', 'corrected'] as $variant) {
                $id = str_replace('/', '-', $prefix) . '-' . $variant;
                $catalog[$id] = compact('id', 'category', 'description', 'variant') + ['file' => "$prefix-$variant.json"];
            }
        }
        return $catalog;
    }
}
