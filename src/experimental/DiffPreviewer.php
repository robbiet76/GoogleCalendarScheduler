<?php
declare(strict_types=1);

/**
 * DiffPreviewer
 *
 * Read-only diff preview helper.
 *
 * IMPORTANT:
 * - Inert placeholder
 * - No execution
 * - No side effects
 */
final class DiffPreviewer
{
    public static function preview(array $config): array
    {
        return [
            'create' => 0,
            'update' => 0,
            'delete' => 0,
        ];
    }
}
