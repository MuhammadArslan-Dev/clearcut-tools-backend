<?php

namespace App\Services\BigQuery;

use App\Models\Tool;
use App\Models\ToolTranslation;
use App\Services\BigQuery\Concerns\DecodesLocaleJson;
use App\Services\BigQuery\Concerns\SyncsByUid;
use Illuminate\Support\Facades\DB;

/**
 * Syncs the `tools` BigQuery table into this app's `tools` +
 * `tool_translations` tables — the BigQuery counterpart of
 * GoogleSheets\SheetSyncService::syncTools(). Rows are matched by `uid`
 * (T_0001, ...), see Concerns\SyncsByUid.
 *
 * Source columns:
 *   uid           stable id
 *   tool_slug     plain string, e.g. "resizer"
 *   tool_name     JSON string keyed by locale: {"en":{"name":"..."},"hi":{...}}
 *   (or tool_name_json)
 *   description   JSON string keyed by locale: {"en":{"text":"..."},"hi":{...}}
 *   (or description_json)
 *   status        plain string, e.g. "active"
 *   sort_order    numeric
 *
 * One `ToolTranslation` row is written per locale found in `tool_name`.
 */
class BigQueryToolSyncService
{
    use DecodesLocaleJson;
    use SyncsByUid;

    protected const QUERY = 'SELECT * FROM `clear-cutoff-435016.content.tools`';

    public function __construct(protected BigQueryClient $client) {}

    /**
     * @return array<string, mixed>
     */
    public function sync(string $mode = self::MODE_INCREMENTAL, bool $dryRun = false): array
    {
        $this->assertMode($mode);
        $this->resetState();

        $rows = $this->client->runQuery(self::QUERY);
        $this->requireUidColumn($rows, 'tools');

        $stats = $this->emptyStats();
        $entries = [];

        foreach ($rows as $i => $row) {
            $uid = $this->cleanString($row['uid'] ?? null);
            $slug = $this->cleanString($row['tool_slug'] ?? null);
            $label = "row {$i}".($uid ? " ({$uid})" : '');

            if (! $uid) {
                $this->warnings[] = "{$label}: missing uid — row skipped (tool_slug '{$slug}')";
                $stats['skipped']++;

                continue;
            }

            if (! $slug) {
                $this->warnings[] = "{$label}: missing tool_slug — row skipped";
                $stats['skipped']++;

                continue;
            }

            $nameRaw = $this->pick($row, ['tool_name', 'tool_name_json']);
            $descriptionRaw = $this->pick($row, ['description', 'description_json']);

            $nameByLocale = $this->decodeLocaleMap($nameRaw === null ? null : (string) $nameRaw);
            $descriptionByLocale = $this->decodeLocaleMap($descriptionRaw === null ? null : (string) $descriptionRaw);

            if (empty($nameByLocale)) {
                $nameByLocale = ['en' => ['name' => $nameRaw ?? $slug]];
            }

            $status = $this->cleanString($row['status'] ?? null) ?? 'active';
            $sortOrder = (int) ($row['sort_order'] ?? 0);

            $entries[] = [
                'uid' => $uid,
                'label' => $label,
                'key' => $slug,
                'attrs' => ['tool_slug' => $slug, 'status' => $status, 'sort_order' => $sortOrder],
                'hash' => $this->hashOf([
                    'uid' => $uid,
                    'tool_slug' => $slug,
                    'status' => $status,
                    'sort_order' => $sortOrder,
                    'tool_name' => $nameByLocale,
                    'description' => $descriptionByLocale,
                ]),
                'legacy' => ['tool_slug' => $slug],
                'unique' => [['tool_slug' => $slug]],
                'nameByLocale' => $nameByLocale,
                'descriptionByLocale' => $descriptionByLocale,
            ];
        }

        $todo = $this->plan(Tool::class, $entries, $mode, $stats);

        if ($dryRun || empty($todo)) {
            return $this->result($stats, $mode, $dryRun, $todo);
        }

        DB::transaction(function () use ($todo) {
            $now = now();
            $idsByUid = $this->apply(Tool::class, $todo);

            $translationRows = [];

            foreach ($todo as $item) {
                foreach ($item['nameByLocale'] as $locale => $nameEntry) {
                    $translationRows[] = [
                        'tool_id' => $idsByUid[$item['uid']],
                        'locale' => $locale,
                        'tool_name' => $nameEntry['name'] ?? $item['key'],
                        'description' => $item['descriptionByLocale'][$locale]['text']
                            ?? $item['descriptionByLocale']['en']['text']
                            ?? null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            ToolTranslation::query()->upsert(
                $translationRows,
                ['tool_id', 'locale'],
                ['tool_name', 'description', 'updated_at'],
            );
        });

        return $this->result($stats, $mode, $dryRun, $todo);
    }
}
