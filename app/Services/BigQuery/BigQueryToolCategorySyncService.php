<?php

namespace App\Services\BigQuery;

use App\Models\Tool;
use App\Models\ToolCategory;
use App\Models\ToolCategoryTranslation;
use App\Services\BigQuery\Concerns\DecodesLocaleJson;
use Illuminate\Support\Facades\DB;

/**
 * Syncs the `tool_categories` BigQuery table into this app's
 * `tool_categories` + `tool_category_translations` tables — the BigQuery
 * counterpart of GoogleSheets\SheetSyncService::syncToolCategories().
 * Depends on `tools` having already been synced (resolves tool_id by
 * tool_slug; a row whose tool isn't found yet is skipped and warned
 * about, not fatal to the rest of the sync).
 *
 * Confirmed live schema (clear-cutoff-435016.content.tool_categories):
 *   tool_slug       plain string, e.g. "resizer"
 *   category_slug   plain string, e.g. "teaching-exams-tet-tgt-pgt"
 *   label           JSON string keyed by locale: {"en":{"name":"..."},"hi":{...}}
 *   icon            plain string (URL) or null — not localized
 *   description     plain string — NOT localized here (unlike `tools`'
 *                   description), so the same value is written to every
 *                   locale's translation row
 *   sort_order      numeric
 *   is_active       bool / "TRUE"/"FALSE"
 *
 * One `ToolCategoryTranslation` row is written per locale found in `label`.
 */
class BigQueryToolCategorySyncService
{
    use DecodesLocaleJson;

    protected const QUERY = 'SELECT * FROM `clear-cutoff-435016.content.tool_categories`';

    /** @var array<int, string> */
    protected array $warnings = [];

    public function __construct(protected BigQueryClient $client) {}

    /**
     * @return array{stats: array{created: int, updated: int, skipped: int}, warnings: array<int, string>}
     */
    public function sync(): array
    {
        $this->warnings = [];
        $rows = $this->client->runQuery(self::QUERY);
        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0];

        DB::transaction(function () use ($rows, &$stats) {
            foreach ($rows as $i => $row) {
                $toolSlug = $row['tool_slug'] ?? null;
                $categorySlug = $row['category_slug'] ?? null;

                if (! $toolSlug || ! $categorySlug) {
                    $this->warnings[] = "row {$i}: missing tool_slug or category_slug";
                    $stats['skipped']++;

                    continue;
                }

                $tool = Tool::where('tool_slug', $toolSlug)->first();

                if (! $tool) {
                    $this->warnings[] = "row {$i}: tool_slug '{$toolSlug}' not found — sync tools first, row skipped";
                    $stats['skipped']++;

                    continue;
                }

                $labelByLocale = $this->decodeLocaleMap($row['label'] ?? null);

                if (empty($labelByLocale)) {
                    $labelByLocale = ['en' => ['name' => $row['label'] ?? $categorySlug]];
                }

                $category = ToolCategory::updateOrCreate(
                    ['tool_id' => $tool->id, 'category_slug' => $categorySlug],
                    [
                        'icon' => $row['icon'] ?? null,
                        'sort_order' => (int) ($row['sort_order'] ?? 0),
                        'is_active' => $this->normalizeBool($row['is_active'] ?? null, true),
                    ],
                );

                $description = $row['description'] ?? null;

                foreach ($labelByLocale as $locale => $labelEntry) {
                    $translation = ToolCategoryTranslation::updateOrCreate(
                        ['tool_category_id' => $category->id, 'locale' => $locale],
                        [
                            'label' => $labelEntry['name'] ?? $categorySlug,
                            'description' => $description,
                        ],
                    );

                    $stats[$translation->wasRecentlyCreated ? 'created' : 'updated']++;
                }
            }
        });

        return [
            'stats' => $stats,
            'warnings' => $this->warnings,
        ];
    }
}
