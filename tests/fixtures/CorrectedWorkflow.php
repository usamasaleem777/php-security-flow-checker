<?php

declare(strict_types=1);

namespace SecurityFlow\Tests\Fixtures;

use LogicException;

final class CorrectedWorkflow
{
    private bool $permissionGranted = false;
    private int $exports = 0;
    private int $recordRevision = 0;
    private int $resetRevision = 0;

    public function setAuthenticatedTestState(): void
    {
        $this->requireOpenSession();
        $_SESSION = ['authenticated' => true, 'active' => true, 'mfa_verified' => false];
    }

    public function recordVerifiedTestFactor(): void
    {
        if (!$this->isAuthenticated()) {
            throw new LogicException('Verified factor setup requires an active test identity.');
        }
        $_SESSION['mfa_verified'] = true;
    }

    public function revokeSession(): void
    {
        $this->requireOpenSession();
        $_SESSION = ['active' => false];
    }

    public function export(): ?string
    {
        if (!$this->isAuthenticated() || ($_SESSION['mfa_verified'] ?? false) !== true) {
            return null;
        }
        ++$this->exports;
        return 'synthetic-export-record';
    }

    public function setPermission(bool $granted): void
    {
        $this->permissionGranted = $granted;
    }

    public function updateProtectedRecord(): bool
    {
        if (!$this->isAuthenticated() || !$this->permissionGranted) {
            return false;
        }
        ++$this->recordRevision;
        return true;
    }

    public function issueTestToken(): string
    {
        $this->requireOpenSession();
        $token = bin2hex(random_bytes(32));
        $_SESSION['token_digest'] = hash('sha256', $token);
        return $token;
    }

    public function consumeTestToken(string $token): bool
    {
        $this->requireOpenSession();
        $digest = $_SESSION['token_digest'] ?? null;
        if (!is_string($digest) || strlen($token) !== 64 || !hash_equals($digest, hash('sha256', $token))) {
            return false;
        }
        unset($_SESSION['token_digest']);
        ++$this->resetRevision;
        return true;
    }

    /** @return array{exports: int, record_revision: int, reset_revision: int} */
    public function effects(): array
    {
        return [
            'exports' => $this->exports,
            'record_revision' => $this->recordRevision,
            'reset_revision' => $this->resetRevision,
        ];
    }

    private function isAuthenticated(): bool
    {
        return session_status() === PHP_SESSION_ACTIVE
            && ($_SESSION['authenticated'] ?? false) === true
            && ($_SESSION['active'] ?? false) === true;
    }

    private function requireOpenSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new LogicException('Fixture requires an open native PHP session.');
        }
    }
}
