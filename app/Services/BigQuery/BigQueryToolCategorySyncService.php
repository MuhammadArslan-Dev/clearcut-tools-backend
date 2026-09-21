<?php

namespace App\Services\BigQuery;

use App\Models\Tool;
use App\Models\ToolCategory;
use App\Models\ToolCategoryTranslation;
use App\Services\BigQuery\Concerns\DecodesLocaleJson;
use App\Services\BigQuery\Concerns\SyncsByUid;
use Illuminate\Support\Facades\DB;

/**
 * Syncs the `tool_categories` BigQuery table into this app's
 * `tool_categories` + `tool_category_translations` tables — the BigQuery
 * counterpart of GoogleSheets\SheetSyncService::syncToolCategories().
 * Rows are matched by `uid` (TC_0001, ...), see Concerns\SyncsByUid.
 *
 * Depends on `tools` having been synced: the parent is resolved by
 * `tool_uid` (falling back to `tool_slug` only when tool_uid is blank).
 *
 * Source columns:
 *   uid, tool_uid, tool_slug, category_slug
 *   label         JSON string keyed by locale: {"en":{"name":"..."},"hi":{...}}
 *   icon          plain string (URL) or null — not localized
 *   description   plain string — NOT localized, written to every locale row
 *   sort_order    numeric
 *   is_active     bool / "TRUE"/"FALSE"
 *
 * The tools frontend derives a category's page URL from its English
 * `label` (not category_slug), so a changed English label is reported in
 * `url_changes`.
 */
class BigQueryToolCategorySyncService
{
    use DecodesLocaleJson;
    use SyncsByUid;

    protected const QUERY = 'SELECT * FROM `clear-cutoff-435016.content.tool_categories`';

    public function __construct(protected BigQueryClient $client) {}

    /**
     * @return array<string, mixed>
     */
    public function sync(string $mode = self::MODE_INCREMENTAL, bool $dryRun = false): array
    {
        $this->assertMode($mode);
        $this->resetState();

        $rows = $this->client->runQuery(self::QUERY);
        $this->requireUidColumn($rows, 'tool_categories');

        $stats = $this->emptyStats();
        $entries = [];

        $toolIdsByUid = Tool::query()->whereNotNull('uid')->pluck('id', 'uid');
        $toolIdsBySlug = Tool::query()->pluck('id', 'tool_slug');

        foreach ($rows as $i => $row) {
            $uid = $this->cleanString($row['uid'] ?? null);
            $categorySlug = $this->cleanString($row['category_slug'] ?? null);
            $label = "row {$i}".($uid ? " ({$uid})" : '');

            if (! $uid) {
                $this->warnings[] = "{$label}: missing uid — row skipped (category_slug '{$categorySlug}')";
                $stats['skipped']++;

                continue;
            }

            if (! $categorySlug) {
                $this->warnings[] = "{$label}: missing category_slug — row skipped";
                $stats['skipped']++;

                continue;
            }

            $toolId = $this->resolveRef(
                $toolIdsByUid,
                $toolIdsBySlug,
                $this->cleanString($row['tool_uid'] ?? null),
                $this->cleanString($row['tool_slug'] ?? null),
                'tool',
                $label,
            );

            if ($toolId === null) {
                $stats['skipped']++;

                continue;
            }

            $labelRaw = $this->pick($row, ['label', 'label_json']);
            $labelByLocale = $this->decodeLocaleMap($labelRaw === null ? null : (string) $labelRaw);

            if (empty($labelByLocale)) {
                $labelByLocale = ['en' => ['name' => $labelRaw ?? $categorySlug]];
            }

            $icon = $this->cleanString($row['icon'] ?? null);
            $sortOrder = (int) ($row['sort_order'] ?? 0);
            $isActive = $this->normalizeBool($row['is_active'] ?? null, true);
            $description = $this->cleanString($row['description'] ?? null);

            $entries[] = [
                'uid' => $uid,
                'label' => $label,
                'key' => $categorySlug,
                'attrs' => [
                    'tool_id' => $toolId,
                    'category_slug' => $categorySlug,
                    'icon' => $icon,
                    'sort_order' => $sortOrder,
                    'is_active' => $isActive,
                ],
                'hash' => $this->hashOf([
                    'uid' => $uid,
                    'tool_id' => $toolId,
                    'category_slug' => $categorySlug,
                    'icon' => $icon,
                    'sort_order' => $sortOrder,
                    'is_active' => $isActive,
                    'label' => $labelByLocale,
                    'description' => $description,
                ]),
                'legacy' => ['tool_id' => $toolId, 'category_slug' => $categorySlug],
                'unique' => [['tool_id' => $toolId, 'category_slug' => $categorySlug]],
                'labelByLocale' => $labelByLocale,
                'description' => $description,
            ];
        }

        $todo = $this->plan(ToolCategory::class, $entries, $mode, $stats);

        $this->reportLabelChanges($todo);

        if ($dryRun || empty($todo)) {
            return $this->result($stats, $mode, $dryRun, $todo);
        }

        DB::transaction(function () use ($todo) {
            $now = now();
            $idsByUid = $this->apply(ToolCategory::class, $todo);

            $translationRows = [];

            foreach ($todo as $item) {
                foreach ($item['labelByLocale'] as $locale => $labelEntry) {
                    $translationRows[] = [
                        'tool_category_id' => $idsByUid[$item['uid']],
                        'locale' => $locale,
                        'label' => $labelEntry['name'] ?? $item['key'],
                        'description' => $item['description'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            ToolCategoryTranslation::query()->upsert(
                $translationRows,
                ['tool_category_id', 'locale'],
                ['label', 'description', 'updated_at'],
            );
        });

        return $this->result($stats, $mode, $dryRun, $todo);
    }

    /**
     * The frontend slugifies the English label into the category page URL,
     * so an edited English label moves that page.
     *
     * @param  array<int, array<string, mixed>>  $todo
     */
    protected function reportLabelChanges(array $todo): void
    {
        $existingIds = collect($todo)
            ->filter(fn ($t) => $t['existing'] !== null)
            ->map(fn ($t) => $t['existing']->id)
            ->all();

        if (empty($existingIds)) {
            return;
        }

        $oldLabels = ToolCategoryTranslation::query()
            ->whereIn('tool_category_id', $existingIds)
            ->where('locale', 'en')
            ->pluck('label', 'tool_category_id');

        foreach ($todo as $item) {
            if ($item['existing'] === null) {
                continue;
            }

            $old = $oldLabels->get($item['existing']->id);
            $new = $item['labelByLocale']['en']['name'] ?? null;

            if ($old !== null && $new !== null && $old !== $new) {
                $this->urlChanges[] = "category {$item['uid']}: English label '{$old}' → '{$new}' — the category page URL changes";
            }
        }
    }
}
