<?php
declare(strict_types=1);

/**
 * FppEnvironment
 *
 * Typed interface to FPP runtime-exported environment data.
 *
 * This class is the ONLY place that reads runtime/fpp-env.json.
 *
 * Responsibilities:
 * - Load and validate schema
 * - Normalize values
 * - Provide safe accessors
 *
 * Non-goals:
 * - No semantic interpretation
 * - No sun / holiday logic
 * - No scheduler logic
 */
final class FppEnvironment
{
    private const SCHEMA_VERSION = 1;

    private bool $ok;
    private ?float $latitude;
    private ?float $longitude;
    private ?string $timezone;
    private ?string $error;

    private function __construct(
        bool $ok,
        ?float $latitude,
        ?float $longitude,
        ?string $timezone,
        ?string $error
    ) {
        $this->ok        = $ok;
        $this->latitude  = $latitude;
        $this->longitude = $longitude;
        $this->timezone  = $timezone;
        $this->error     = $error;
    }

    /**
     * Load FPP environment from runtime JSON.
     */
    public static function load(string $path, array &$warnings): self
    {
        if (!is_file($path)) {
            $warnings[] = 'FPP environment missing (fpp-env.json not found).';
            return self::invalid('missing environment file');
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            $warnings[] = 'Unable to read fpp-env.json.';
            return self::invalid('unreadable environment file');
        }

        $json = json_decode($raw, true);
        if (!is_array($json)) {
            $warnings[] = 'Invalid JSON in fpp-env.json.';
            return self::invalid('invalid JSON');
        }

        if (($json['schemaVersion'] ?? null) !== self::SCHEMA_VERSION) {
            $warnings[] = 'Unsupported FPP environment schemaVersion.';
            return self::invalid('unsupported schema version');
        }

        $ok = (bool)($json['ok'] ?? false);

        if (!$ok) {
            $warnings[] = $json['error'] ?? 'FPP environment reported failure.';
        }

        return new self(
            $ok,
            self::parseFloat($json['latitude'] ?? null),
            self::parseFloat($json['longitude'] ?? null),
            is_string($json['timezone'] ?? null) ? $json['timezone'] : null,
            is_string($json['error'] ?? null) ? $json['error'] : null
        );
    }

    private static function invalid(string $error): self
    {
        return new self(false, null, null, null, $error);
    }

    private static function parseFloat($v): ?float
    {
        return is_numeric($v) ? (float)$v : null;
    }

    /* ================= Accessors ================= */

    public function isOk(): bool
    {
        return $this->ok;
    }

    public function latitude(): ?float
    {
        return $this->latitude;
    }

    public function longitude(): ?float
    {
        return $this->longitude;
    }

    public function timezone(): ?string
    {
        return $this->timezone;
    }

    public function error(): ?string
    {
        return $this->error;
    }
}