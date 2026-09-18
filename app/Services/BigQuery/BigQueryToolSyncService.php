<?php

namespace App\Services\BigQuery;

use App\Models\Tool;
use App\Models\ToolTranslation;
use App\Services\BigQuery\Concerns\DecodesLocaleJson;
use Illuminate\Support\Facades\DB;

/**
 * Syncs the `tools` BigQuery table into this app's `tools` +
 * `tool_translations` tables — the BigQuery counterpart of
 * GoogleSheets\SheetSyncService::syncTools(). Upsert-only by tool_slug,
 * mirroring BigQueryExamSyncService's conventions.
 *
 * Confirmed live schema (clear-cutoff-435016.content.tools):
 *   tool_slug     plain string, e.g. "resizer"
 *   tool_name     JSON string keyed by locale: {"en":{"name":"..."},"hi":{...}}
 *   description   JSON string keyed by locale: {"en":{"text":"..."},"hi":{...}}
 *   status        plain string, e.g. "active"
 *   sort_order    numeric
 *
 * One `ToolTranslation` row is written per locale found in `tool_name`.
 */
class BigQueryToolSyncService
{
    use DecodesLocaleJson;

    protected const QUERY = 'SELECT * FROM `clear-cutoff-435016.content.tools`';

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
                $slug = $row['tool_slug'] ?? null;

                if (! $slug) {
                    $this->warnings[] = "row {$i}: missing tool_slug — row columns: ".implode(', ', array_keys($row));
                    $stats['skipped']++;

                    continue;
                }

                $nameByLocale = $this->decodeLocaleMap($row['tool_name'] ?? null);
                $descriptionByLocale = $this->decodeLocaleMap($row['description'] ?? null);

                if (empty($nameByLocale)) {
                    $nameByLocale = ['en' => ['name' => $row['tool_name'] ?? $slug]];
                }

                $tool = Tool::updateOrCreate(
                    ['tool_slug' => $slug],
                    [
                        'status' => $row['status'] ?? 'active',
                        'sort_order' => (int) ($row['sort_order'] ?? 0),
                    ],
                );

                foreach ($nameByLocale as $locale => $nameEntry) {
                    $translation = ToolTranslation::updateOrCreate(
                        ['tool_id' => $tool->id, 'locale' => $locale],
                        [
                            'tool_name' => $nameEntry['name'] ?? $slug,
                            'description' => $descriptionByLocale[$locale]['text']
                                ?? $descriptionByLocale['en']['text']
                                ?? null,
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
