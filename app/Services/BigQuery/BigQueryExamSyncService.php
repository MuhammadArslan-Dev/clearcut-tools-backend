<?php

namespace App\Services\BigQuery;

use App\Models\Exam;
use App\Models\ExamTranslation;
use App\Services\BigQuery\Concerns\DecodesLocaleJson;
use App\Services\BigQuery\Concerns\SyncsByUid;
use Illuminate\Support\Facades\DB;

/**
 * Syncs the `exams` BigQuery table into this app's `exams` +
 * `exam_translations` tables — the BigQuery counterpart of
 * GoogleSheets\SheetSyncService::syncExams().
 *
 * Rows are matched by `uid` (E_0001, ...), not by exam_slug — see
 * Concerns\SyncsByUid for the incremental/full/dry-run behaviour.
 *
 * Source columns:
 *   uid              stable id, first column of the sheet
 *   exam_slug        plain string, e.g. "htet"
 *   short_name       plain string, e.g. "HTET" — not itself translated
 *   full_name        JSON string keyed by locale, e.g.
 *   (or full_name_json)  {"en":{"name":"..."},"hi":{"name":"..."}}
 *   conducting_body  same per-locale JSON shape as full_name, optional
 *   (or conducting_body_json)
 *
 * One `ExamTranslation` row is written per locale found in `full_name`.
 * Writes are batched (`upsert()`), because the DB is remote and a
 * per-row round trip made the old per-row sync take 10+ minutes.
 */
class BigQueryExamSyncService
{
    use DecodesLocaleJson;
    use SyncsByUid;

    protected const QUERY = 'SELECT * FROM `clear-cutoff-435016.content.exams`';

    public function __construct(protected BigQueryClient $client) {}

    /**
     * @return array<string, mixed>
     */
    public function sync(string $mode = self::MODE_INCREMENTAL, bool $dryRun = false): array
    {
        $this->assertMode($mode);
        $this->resetState();

        $rows = $this->client->runQuery(self::QUERY);
        $this->requireUidColumn($rows, 'exams');

        $stats = $this->emptyStats();
        $entries = [];

        foreach ($rows as $i => $row) {
            $uid = $this->cleanString($row['uid'] ?? null);
            $slug = $this->cleanString($row['exam_slug'] ?? null);
            $label = "row {$i}".($uid ? " ({$uid})" : '');

            if (! $uid) {
                $this->warnings[] = "{$label}: missing uid — row skipped (exam_slug '{$slug}')";
                $stats['skipped']++;

                continue;
            }

            if (! $slug) {
                $this->warnings[] = "{$label}: missing exam_slug — row skipped";
                $stats['skipped']++;

                continue;
            }

            $shortName = $this->cleanString($row['short_name'] ?? null) ?? $slug;
            $fullNameRaw = $this->pick($row, ['full_name', 'full_name_json']);
            $conductingRaw = $this->pick($row, ['conducting_body', 'conducting_body_json']);

            $fullNameByLocale = $this->decodeLocaleMap($fullNameRaw === null ? null : (string) $fullNameRaw);
            $conductingBodyByLocale = $this->decodeLocaleMap($conductingRaw === null ? null : (string) $conductingRaw);

            if (empty($fullNameByLocale)) {
                // full_name didn't decode into a locale map (missing, or a
                // plain unlocalized string) — fall back to a single "en"
                // row rather than skipping the exam entirely.
                $fullNameByLocale = ['en' => ['name' => $fullNameRaw ?? $shortName]];
            }

            $entries[] = [
                'uid' => $uid,
                'label' => $label,
                'key' => $slug,
                'attrs' => ['exam_slug' => $slug],
                'hash' => $this->hashOf([
                    'uid' => $uid,
                    'exam_slug' => $slug,
                    'short_name' => $shortName,
                    'full_name' => $fullNameByLocale,
                    'conducting_body' => $conductingBodyByLocale,
                ]),
                'legacy' => ['exam_slug' => $slug],
                'unique' => [['exam_slug' => $slug]],
                'shortName' => $shortName,
                'fullNameByLocale' => $fullNameByLocale,
                'conductingBodyByLocale' => $conductingBodyByLocale,
            ];
        }

        $todo = $this->plan(Exam::class, $entries, $mode, $stats);

        if ($dryRun || empty($todo)) {
            return $this->result($stats, $mode, $dryRun, $todo);
        }

        DB::transaction(function () use ($todo) {
            $now = now();
            $idsByUid = $this->apply(Exam::class, $todo);

            $translationRows = [];

            foreach ($todo as $item) {
                $examId = $idsByUid[$item['uid']];

                foreach ($item['fullNameByLocale'] as $locale => $fullNameEntry) {
                    $translationRows[] = [
                        'exam_id' => $examId,
                        'locale' => $locale,
                        'short_name' => $item['shortName'],
                        'full_name' => $fullNameEntry['name'] ?? $item['shortName'],
                        'conducting_body' => $item['conductingBodyByLocale'][$locale]['name']
                            ?? $item['conductingBodyByLocale']['en']['name']
                            ?? null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            ExamTranslation::query()->upsert(
                $translationRows,
                ['exam_id', 'locale'],
                ['short_name', 'full_name', 'conducting_body', 'updated_at'],
            );
        });

        return $this->result($stats, $mode, $dryRun, $todo);
    }
}
