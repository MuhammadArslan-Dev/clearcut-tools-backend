<?php

namespace App\Services\BigQuery\Concerns;

/**
 * Shared helpers for BigQuery `content.*` sync services — every synced
 * table encodes its per-locale text as a JSON STRING column shaped like
 * `{"en":{"name":"..."},"hi":{"name":"..."}}` (or `{"text":"..."}` for
 * descriptions), and encodes booleans inconsistently enough (native PHP
 * bool from some columns, "TRUE"/"FALSE" strings from others) that every
 * sync service needs the same normalization.
 */
trait DecodesLocaleJson
{
    /**
     * Decodes a `{"en":{"name":"..."},"hi":{"name":"..."}}`-shaped BigQuery
     * STRING column into its PHP array form. Returns [] for null/empty/
     * non-JSON values so callers can fall back gracefully instead of
     * crashing on a legacy unlocalized row.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function decodeLocaleMap(?string $value): array
    {
        if (! $value) {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Normalizes BigQuery's inconsistent boolean representations (native
     * bool, "TRUE"/"FALSE", "1"/"0") to a real PHP bool.
     */
    protected function normalizeBool(mixed $value, bool $default = false): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if ($value === null || $value === '') {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
