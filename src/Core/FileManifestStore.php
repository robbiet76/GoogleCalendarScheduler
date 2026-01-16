<?php
declare(strict_types=1);

namespace GCS\Core;

use GCS\Core\Exception\ManifestInvariantViolation;

/**
 * FileManifestStore
 *
 * Concrete ManifestStore backed by filesystem persistence.
 *
 * Responsibilities:
 * - Sole mutation boundary for the Manifest
 * - Enforce identity invariants
 * - Provide atomic load/save semantics (later)
 *
 * NOTE:
 * This file intentionally contains NO persistence implementation yet.
 * Only invariant scaffolding is present.
 */
final class FileManifestStore implements ManifestStore
{
    /** @var array|null */
    private ?array $manifest = null;

    /**
     * {@inheritdoc}
     */
    public function load(): array
    {
        // Persistence not implemented yet
        $manifest = [];

        $this->assertManifestValid($manifest);

        $this->manifest = $manifest;

        return $this->manifest;
    }

    /**
     * {@inheritdoc}
     */
    public function save(array $manifest): void
    {
        $this->assertManifestValid($manifest);

        // Atomic persistence will be implemented later
        $this->manifest = $manifest;
    }

    /**
     * {@inheritdoc}
     */
    public function upsertEvent(array $event): void
    {
        $this->assertEventHasIdentity($event);
        $this->assertIdentityComplete($event);
        $this->assertIdentityHashValid($event);
        $this->assertSubEventIdentityRules($event);

        $identityHash = $this->extractIdentityHash($event);

        if ($this->manifest === null) {
            throw new ManifestInvariantViolation(
                'Manifest not loaded',
                ManifestInvariantViolation::IDENTITY_MISSING
            );
        }

        if (isset($this->manifest[$identityHash])) {
            $this->assertIdentityNotMutated(
                $this->manifest[$identityHash],
                $event
            );

            // Merge behavior intentionally undefined here
            $this->manifest[$identityHash] = $event;
            return;
        }

        $this->assertIdentityUnique($identityHash);

        $this->manifest[$identityHash] = $event;
    }

    /**
     * {@inheritdoc}
     */
    public function removeEvent(string $identityHash): void
    {
        if ($this->manifest === null || !isset($this->manifest[$identityHash])) {
            return;
        }

        unset($this->manifest[$identityHash]);
    }

    /**
     * {@inheritdoc}
     */
    public function snapshot(): array
    {
        if ($this->manifest === null) {
            throw new ManifestInvariantViolation(
                'Manifest not loaded',
                ManifestInvariantViolation::IDENTITY_MISSING
            );
        }

        return $this->manifest;
    }

    /**
     * {@inheritdoc}
     */
    public function validate(array $manifest): void
    {
        $this->assertManifestValid($manifest);
    }

    /**
     * {@inheritdoc}
     */
    public function snapshotBackup(): void
    {
        // Backup semantics intentionally deferred
    }

    /**
     * {@inheritdoc}
     */
    public function revert(): void
    {
        // Revert semantics intentionally deferred
    }

    /* -----------------------------------------------------------------
     * Invariant Assertions
     * ----------------------------------------------------------------- */

    private function assertManifestValid(array $manifest): void
    {
        $seen = [];

        foreach ($manifest as $event) {
            $this->assertEventHasIdentity($event);
            $this->assertIdentityComplete($event);
            $this->assertSubEventIdentityRules($event);

            $hash = $this->extractIdentityHash($event);
            $this->assertIdentityHashValid($event);

            if (isset($seen[$hash])) {
                throw new ManifestInvariantViolation(
                    'Duplicate identity detected',
                    ManifestInvariantViolation::IDENTITY_DUPLICATE
                );
            }

            $seen[$hash] = true;
        }
    }

    private function assertEventHasIdentity(array $event): void
    {
        if (!isset($event['identity'])) {
            throw new ManifestInvariantViolation(
                'Missing identity object',
                ManifestInvariantViolation::IDENTITY_MISSING
            );
        }
    }

    private function assertIdentityComplete(array $event): void
    {
        $required = ['type', 'target', 'time_window', 'date_pattern'];

        foreach ($required as $field) {
            if (!isset($event['identity'][$field])) {
                throw new ManifestInvariantViolation(
                    "Incomplete identity: missing {$field}",
                    ManifestInvariantViolation::IDENTITY_INCOMPLETE
                );
            }
        }
    }

    private function assertIdentityHashValid(array $event): void
    {
        if (!isset($event['identity_hash']) || !is_string($event['identity_hash']) || trim($event['identity_hash']) === '') {
            throw new ManifestInvariantViolation(
                'Invalid identity hash',
                ManifestInvariantViolation::IDENTITY_HASH_INVALID
            );
        }
    }

    private function assertIdentityNotMutated(array $existing, array $incoming): void
    {
        if ($existing['identity'] !== $incoming['identity']) {
            throw new ManifestInvariantViolation(
                'Identity mutation detected',
                ManifestInvariantViolation::IDENTITY_MUTATION
            );
        }
    }

    private function assertIdentityUnique(string $hash): void
    {
        if ($this->manifest !== null && isset($this->manifest[$hash])) {
            throw new ManifestInvariantViolation(
                'Identity already exists',
                ManifestInvariantViolation::IDENTITY_DUPLICATE
            );
        }
    }

    private function assertSubEventIdentityRules(array $event): void
    {
        if (!isset($event['subEvents'])) {
            return;
        }

        foreach ($event['subEvents'] as $subEvent) {
            if (isset($subEvent['identity'])) {
                throw new ManifestInvariantViolation(
                    'SubEvent may not define identity',
                    ManifestInvariantViolation::SUBEVENT_IDENTITY_ERROR
                );
            }
        }
    }

    private function extractIdentityHash(array $event): string
    {
        if (!isset($event['identity_hash'])) {
            throw new ManifestInvariantViolation(
                'Missing identity hash',
                ManifestInvariantViolation::IDENTITY_HASH_INVALID
            );
        }

        return (string)$event['identity_hash'];
    }
}