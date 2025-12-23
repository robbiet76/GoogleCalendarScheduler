<?php
declare(strict_types=1);

/**
 * DiffPreviewer
 *
 * Read-only diff preview helper.
 *
 * Responsibilities:
 * - Load current scheduler state (read-only)
 * - Build desired schedule from calendar data (read-only)
 * - Compute differences (read-only)
 * - Return summary counts only (create/update/delete)
 *
 * IMPORTANT:
 * - No apply
 * - No config mutation
 * - No logging
 * - No side effects
 */
final class DiffPreviewer
{
    /**
     * Preview a diff between desired schedule and current scheduler state.
     *
     * @param array $config Loaded plugin configuration
     * @return array Summary counts: ['create' => int, 'update' => int, 'delete' => int]
     */
    public static function preview(array $config): array
    {
        // Intentionally inert in Step A.
        // This method will be implemented in Step C.
        return [
            'create' => 0,
            'update' => 0,
            'delete' => 0,
        ];
    }
}
