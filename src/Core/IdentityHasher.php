<?php
declare(strict_types=1);

namespace GCS\Core;

/**
 * IdentityHasher
 *
 * Responsible for producing a stable, deterministic hash
 * representing the semantic identity of a Manifest Event.
 *
 * This hash is used for:
 * - Identity matching
 * - Manifest uniqueness
 * - Diff reconciliation
 *
 * IMPORTANT:
 * - Hash derivation is PURE and deterministic
 * - No IO, no persistence, no side effects
 * - Caller must supply a fully-normalized Identity object
 */

/**
 * ─────────────────────────────────────────────────────────────
 * Identity Canonicalization Rules (Authoritative)
 * ─────────────────────────────────────────────────────────────
 *
 * Identity hashing is based ONLY on semantic identity fields.
 * These rules define what MUST and MUST NOT influence identity.
 *
 * INCLUDED (identity-defining):
 * - type (playlist | command | sequence)
 * - target (playlist name, command name, sequence name)
 * - timing.days (normalized day mask)
 * - timing.start_time (symbolic or absolute, canonical form)
 * - timing.end_time   (symbolic or absolute, canonical form)
 *
 * CONDITIONALLY INCLUDED:
 * - date constraints ONLY if they alter semantic recurrence
 *   (e.g. DatePattern with non-zero specificity)
 *
 * EXCLUDED (non-identity, mutable, or execution-specific):
 * - stopType
 * - repeat
 * - enabled / status flags
 * - ordering / index / position
 * - provider UID
 * - hashes or derived IDs
 *
 * REQUIREMENTS:
 * - Identity input MUST already be normalized
 * - Field ordering MUST be stable before hashing
 * - Hash output MUST be deterministic across runs
 *
 * VIOLATIONS:
 * - Missing required fields → hard failure
 * - Non-canonical structures → hard failure
 */
interface IdentityHasher
{
    /**
     * Derive a stable identity hash from a canonical Identity object.
     *
     * @param array $identity Canonical identity structure
     *                        (already normalized, validated, and complete)
     *
     * @return string Non-empty, lowercase hex hash (SHA-256)
     *
     * @throws \InvalidArgumentException
     *         If identity is missing required fields
     *         or is not in canonical form
     */
    public function hashIdentity(array $identity): string;
}