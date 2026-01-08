<?php
declare(strict_types=1);

/**
 * FppEnvironment
 *
 * Runtime environment exported by FPP (via gcs-export).
 *
 * Responsibilities:
 * - Load runtime/fpp-env.json
 * - Validate schema and structure
 * - Provide typed accessors for environment values
 *
 * NON-GOALS:
 * - No scheduler logic
 * - No calendar logic
 * - No DateTime creation
 * - No fallback computation
 *
 * This class reflects *runtime state*, not policy.
 */
final class FppEnvironment
{
    public const SCHEMA_VERSION = 1;

    /** @var bool */
    private bool $ok = false;

    /** @var ?float */
    private ?float $latitude = null;

    /** @var ?float */
    private ?float $longitude = null;

    /** @var ?string */
    private ?string $timezone = null;

    /** @var ?string */
    private ?string $error = null;

    /**
     * Raw decoded environment payload.
     *
     * Always initialized to avoid typed-property access errors.
     *
     * @var array<string,mixed>
     */
    private array $raw = [];

    private function __construct(
        bool $ok,
        ?float $latitude,
        ?float $longitude,
        ?string $timezone,
        ?string $error,
        array $raw
    ) {
        $this->ok        = $ok;
        $this->latitude  = $latitude;
        $this->longitude = $longitude;
        $this->timezone  = $timezone;
        $this->error     = $error;
        $this->raw       = $raw;
    }

    /* =====================================================================
     * Factory
     * ===================================================================== */

    public static function loadFromFile(string $path, array &$warnings): self
    {
        if (!is_file($path)) {
            $warnings[] = "FPP environment file not found: {$path}";
            return self::invalid('Missing fpp-env.json');
        }

        $rawJson = file_get_contents($path);
        if ($rawJson === false) {
            $warnings[] = "Unable to read FPP environment file: {$path}";
            return self::invalid('Unreadable fpp-env.json');
        }

        $decoded = json_decode($rawJson, true);
        if (!is_array($decoded)) {
            $warnings[] = 'Invalid JSON in FPP environment file.';
            return self::invalid('Invalid JSON');
        }

        if (($decoded['schemaVersion'] ?? null) !== self::SCHEMA_VERSION) {
            $warnings[] = 'Unsupported FPP environment schema version.';
        }

        $ok = (bool)($decoded['ok'] ?? false);

        $lat = is_numeric($decoded['latitude'] ?? null)
            ? (float)$decoded['latitude']
            : null;

        $lon = is_numeric($decoded['longitude'] ?? null)
            ? (float)$decoded['longitude']
            : null;

        $tz = is_string($decoded['timezone'] ?? null)
            ? $decoded['timezone']
            : null;

        $error = is_string($decoded['error'] ?? null)
            ? $decoded['error']
            : null;

        if (!$ok && $error) {
            $warnings[] = "FPP environment error: {$error}";
        }

        return new self(
            $ok,
            $lat,
            $lon,
            $tz,
            $error,
            $decoded
        );
    }

    private static function invalid(string $error): self
    {
        return new self(false, null, null, null, $error, []);
    }

    /* =====================================================================
     * Accessors
     * ===================================================================== */

    public function isOk(): bool
    {
        return $this->ok;
    }

    public function getLatitude(): ?float
    {
        return $this->latitude;
    }

    public function getLongitude(): ?float
    {
        return $this->longitude;
    }

    public function getTimezone(): ?string
    {
        return $this->timezone;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    /**
     * Raw environment payload (debug / diagnostics only).
     *
     * @return array<string,mixed>
     */
    public function getRaw(): array
    {
        return $this->raw;
    }

    /**
     * Export a safe subset for API responses or debugging.
     */
    public function toArray(): array
    {
        return [
            'ok'        => $this->ok,
            'latitude'  => $this->latitude,
            'longitude' => $this->longitude,
            'timezone'  => $this->timezone,
            'error'     => $this->error,
        ];
    }
}