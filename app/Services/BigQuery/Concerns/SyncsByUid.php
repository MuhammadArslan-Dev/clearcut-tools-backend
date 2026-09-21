<?php

namespace App\Services\BigQuery\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Shared machinery for the uid-keyed content syncs.
 *
 * Every synced sheet row carries a stable `uid` (T_0001, E_0001, ...). A row
 * is matched to its DB row by that uid, never by slug, so editing a slug or
 * name in the sheet updates the existing row instead of creating a second
 * one. Each DB row also stores a `content_hash` of its synced fields, so an
 * incremental run can skip rows that did not change; a full run ignores the
 * hash and rewrites everything.
 *
 * Still upsert-only: a row that disappears from the sheet is left alone in
 * the DB (use is_active/status in the sheet to switch something off).
 *
 * Transition rule: a DB row that has no uid yet (created before uids
 * existed) is adopted by matching its old natural key (slug, or the
 * parent+slug pair), and gets its uid stamped on.
 */
trait SyncsByUid
{
    public const MODE_INCREMENTAL = 'incremental';

    public const MODE_FULL = 'full';

    /** @var array<int, string> */
    protected array $warnings = [];

    /** @var array<int, string> */
    protected array $urlChanges = [];

    protected function resetState(): void
    {
        $this->warnings = [];
        $this->urlChanges = [];
    }

    /** @return array{created: int, updated: int, unchanged: int, skipped: int} */
    protected function emptyStats(): array
    {
        return ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0];
    }

    protected function assertMode(string $mode): void
    {
        if (! in_array($mode, [self::MODE_INCREMENTAL, self::MODE_FULL], true)) {
            throw new RuntimeException("Unknown sync mode '{$mode}' — use 'incremental' or 'full'.");
        }
    }

    protected function cleanString(mixed $value): ?string
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * First non-blank value among the given column names. The sheet's JSON
     * columns are named with a `_json` suffix (tool_name_json) in some
     * exports and without it (tool_name) in others; both are accepted.
     *
     * @param  array<int, string>  $keys
     */
    protected function pick(array $row, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '') {
                return $row[$key];
            }
        }

        return null;
    }

    /**
     * Refuses to run against a source that has no uid values at all — that
     * means the sheet/table was never updated to the uid version, and
     * silently skipping every row would look like a successful empty sync.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    protected function requireUidColumn(array $rows, string $source): void
    {
        foreach ($rows as $row) {
            if ($this->cleanString($row['uid'] ?? null) !== null) {
                return;
            }
        }

        if ($rows) {
            throw new RuntimeException(
                "Source '{$source}' has no 'uid' values. Upload the uid version of the sheet (first column 'uid') before syncing.",
            );
        }
    }

    /** @param  array<string, mixed>  $fields */
    protected function hashOf(array $fields): string
    {
        ksort($fields);

        return hash('sha256', json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Decides, per source entry, whether it is created, updated, adopted,
     * unchanged or skipped, and updates $stats. Reads only; writes nothing.
     *
     * Entry shape:
     *   uid     string   the sheet's uid
     *   label   string   human label for warnings
     *   key     string   short name for the change report (a slug)
     *   attrs   array    base-table column => value written on create/update
     *   hash    string   content hash of the row
     *   legacy  array    column => value identifying a pre-uid DB row to adopt
     *   unique  array    list of column=>value sets that must not belong to
     *                    a different uid (the table's unique constraints)
     *
     * @param  class-string<Model>  $model
     * @param  array<int, array<string, mixed>>  $entries
     * @param  array{created: int, updated: int, unchanged: int, skipped: int}  $stats
     * @return array<int, array<string, mixed>> entries to write, with 'action' (create|update|adopt) and 'existing'
     */
    protected function plan(string $model, array $entries, string $mode, array &$stats): array
    {
        $existing = $model::query()->get();
        $byUid = $existing->filter(fn ($r) => $r->uid !== null)->keyBy('uid');

        $todo = [];
        $seenUids = [];
        $seenUnique = [];

        foreach ($entries as $entry) {
            $uid = $entry['uid'];

            if (isset($seenUids[$uid])) {
                $this->warnings[] = "{$entry['label']}: uid '{$uid}' appears more than once in the source — this duplicate row skipped";
                $stats['skipped']++;

                continue;
            }

            $seenUids[$uid] = true;

            $batchConflict = null;

            foreach ($entry['unique'] as $where) {
                $sig = json_encode($where);

                if (isset($seenUnique[$sig]) && $seenUnique[$sig] !== $uid) {
                    $batchConflict = $this->describe($where);
                    break;
                }
            }

            if ($batchConflict) {
                $this->warnings[] = "{$entry['label']}: {$batchConflict} is used by another uid in the same source — row skipped";
                $stats['skipped']++;

                continue;
            }

            $row = $byUid->get($uid);
            $legacy = null;

            if (! $row && $entry['legacy']) {
                $legacy = $existing->first(fn ($r) => $r->uid === null && $this->matches($r, $entry['legacy']));
            }

            $target = $row ?? $legacy;

            $conflict = $this->findConflict($existing, $entry['unique'], $uid, $target?->id);

            if ($conflict) {
                $this->warnings[] = "{$entry['label']}: {$conflict} already belongs to a different uid in the database — row skipped";
                $stats['skipped']++;

                continue;
            }

            foreach ($entry['unique'] as $where) {
                $seenUnique[json_encode($where)] = $uid;
            }

            if ($row) {
                if ($mode !== self::MODE_FULL && $row->content_hash === $entry['hash']) {
                    $stats['unchanged']++;

                    continue;
                }

                $todo[] = $entry + ['action' => 'update', 'existing' => $row];
                $stats['updated']++;

                continue;
            }

            if ($legacy) {
                $todo[] = $entry + ['action' => 'adopt', 'existing' => $legacy];
                $stats['updated']++;

                continue;
            }

            $todo[] = $entry + ['action' => 'create', 'existing' => null];
            $stats['created']++;
        }

        return $todo;
    }

    /**
     * Writes the planned base-table rows (adopt first, then one upsert keyed
     * on uid) and returns uid => id for them.
     *
     * @param  class-string<Model>  $model
     * @param  array<int, array<string, mixed>>  $todo
     * @return Collection<string, int>
     */
    protected function apply(string $model, array $todo): Collection
    {
        if (empty($todo)) {
            return collect();
        }

        $now = now();

        foreach ($todo as $item) {
            if ($item['action'] === 'adopt') {
                $model::query()->whereKey($item['existing']->id)->update(['uid' => $item['uid']]);
            }
        }

        $rows = array_map(fn ($item) => [
            ...$item['attrs'],
            'uid' => $item['uid'],
            'content_hash' => $item['hash'],
            'created_at' => $now,
            'updated_at' => $now,
        ], $todo);

        $model::query()->upsert(
            $rows,
            ['uid'],
            [...array_keys($todo[0]['attrs']), 'content_hash', 'updated_at'],
        );

        return $model::query()->whereIn('uid', array_column($todo, 'uid'))->pluck('id', 'uid');
    }

    /**
     * Resolves a parent row's id. The parent's uid wins; the slug is only a
     * fallback for rows whose parent-uid cell is still blank. A uid that is
     * given but unknown is an error (stale sheet), never silently re-resolved
     * by slug.
     *
     * @param  Collection<string, int>  $byUid
     * @param  Collection<string, int>  $bySlug
     */
    protected function resolveRef(
        Collection $byUid,
        Collection $bySlug,
        ?string $uid,
        ?string $slug,
        string $what,
        string $label,
        bool $required = true,
    ): ?int {
        $outcome = $required ? ', row skipped' : ', saved without it';

        if ($uid !== null) {
            $id = $byUid->get($uid);

            if ($id === null) {
                $this->warnings[] = "{$label}: {$what}_uid '{$uid}' not found — sync the {$what} sheet first{$outcome}";
            }

            return $id;
        }

        if ($slug !== null) {
            $id = $bySlug->get($slug);

            if ($id === null) {
                $this->warnings[] = "{$label}: {$what}_slug '{$slug}' not found — sync the {$what} sheet first{$outcome}";
            } else {
                $this->warnings[] = "{$label}: {$what}_uid is blank — resolved by {$what}_slug '{$slug}'; fill in {$what}_uid";
            }

            return $id;
        }

        if ($required) {
            $this->warnings[] = "{$label}: missing {$what}_uid — row skipped";
        }

        return null;
    }

    /**
     * @param  Collection<int, Model>  $existing
     * @param  array<int, array<string, mixed>>  $uniques
     */
    protected function findConflict(Collection $existing, array $uniques, string $uid, ?int $excludeId): ?string
    {
        foreach ($uniques as $where) {
            $hit = $existing->first(fn ($r) => $r->uid !== $uid
                && $r->id !== $excludeId
                && $this->matches($r, $where));

            if ($hit) {
                return $this->describe($where);
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $where */
    protected function matches(Model $row, array $where): bool
    {
        foreach ($where as $column => $value) {
            if ((string) $row->{$column} !== (string) $value) {
                return false;
            }
        }

        return true;
    }

    /** @param  array<string, mixed>  $where */
    protected function describe(array $where): string
    {
        return collect($where)->map(fn ($v, $k) => "{$k}='{$v}'")->implode(', ');
    }

    /**
     * @param  array{created: int, updated: int, unchanged: int, skipped: int}  $stats
     * @param  array<int, array<string, mixed>>  $todo
     * @return array<string, mixed>
     */
    protected function result(array $stats, string $mode, bool $dryRun, array $todo): array
    {
        return [
            'stats' => $stats,
            'mode' => $mode,
            'dry_run' => $dryRun,
            'changed' => array_map(
                fn ($t) => ['uid' => $t['uid'], 'key' => $t['key'], 'action' => $t['action'] === 'create' ? 'created' : 'updated'],
                array_slice($todo, 0, 500),
            ),
            'url_changes' => $this->urlChanges,
            'warnings' => $this->warnings,
        ];
    }
}
