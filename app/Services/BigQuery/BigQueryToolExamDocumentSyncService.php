<?php

namespace App\Services\BigQuery;

use App\Models\ToolExam;
use App\Models\ToolExamDocument;
use App\Services\BigQuery\Concerns\DecodesLocaleJson;
use App\Services\BigQuery\Concerns\SyncsByUid;
use Illuminate\Support\Facades\DB;

/**
 * Syncs the `tools_exam_documents` BigQuery table (note the `tools_` prefix)
 * into `tool_exam_documents`: which documents each exam asks for and each
 * one's upload spec. Rows are matched by `uid` (TD_0001, ...), see
 * Concerns\SyncsByUid. Depends on `tool_exam_mapping` having been synced.
 *
 * Source columns:
 *   uid, mapping_uid (parent tool_exam, resolved by uid), tool_slug, exam_slug
 *   doc_type       photo | signature | left_thumb | right_thumb | handwritten_declaration
 *   sort_order     display order of the tile
 *   mode           upload | live_capture
 *   width_px, height_px, min_kb, max_kb   optional numbers
 *   format         default jpg
 *   is_required    bool
 *   verification   unverified | partly_verified | official_verified
 *   source_url, verified_on (date), note (internal)
 *
 * Unlike the other syncs this one keeps a document list in step with the
 * sheet: for every exam that appears in the sheet, a document that is no
 * longer listed is DEACTIVATED (is_active = false, never deleted) and one
 * that is listed again is re-activated. Exams absent from the sheet are left
 * untouched, so a partly uploaded sheet cannot wipe other exams' documents.
 */
class BigQueryToolExamDocumentSyncService
{
    use DecodesLocaleJson;
    use SyncsByUid;

    protected const QUERY = 'SELECT * FROM `clear-cutoff-435016.content.tools_exam_documents`';

    public function __construct(protected BigQueryClient $client) {}

    /**
     * @return array<string, mixed>
     */
    public function sync(string $mode = self::MODE_INCREMENTAL, bool $dryRun = false): array
    {
        $this->assertMode($mode);
        $this->resetState();

        $rows = $this->client->runQuery(self::QUERY);
        $this->requireUidColumn($rows, 'tools_exam_documents');

        $stats = $this->emptyStats();
        $entries = [];
        $presentUids = [];     // every uid in the sheet, even rows skipped below
        $scopeToolExamIds = []; // exams that appear in the sheet

        $toolExams = ToolExam::query()->get(['id', 'uid', 'tool_id', 'exam_id']);
        $toolExamIdsByUid = $toolExams->whereNotNull('uid')->pluck('id', 'uid');

        foreach ($rows as $i => $row) {
            $uid = $this->cleanString($row['uid'] ?? null);
            $label = "row {$i}".($uid ? " ({$uid})" : '');

            if (! $uid) {
                $this->warnings[] = "{$label}: missing uid — row skipped";
                $stats['skipped']++;

                continue;
            }

            $presentUids[$uid] = true;

            $toolExamId = $this->resolveRef(
                $toolExamIdsByUid,
                collect(),
                $this->cleanString($row['mapping_uid'] ?? null),
                null,
                'mapping',
                $label,
            );

            if ($toolExamId === null) {
                $stats['skipped']++;

                continue;
            }

            $scopeToolExamIds[$toolExamId] = true;

            $docType = strtolower($this->cleanString($row['doc_type'] ?? null) ?? '');
            $docMode = strtolower($this->cleanString($row['mode'] ?? null) ?? 'upload');
            $verification = strtolower($this->cleanString($row['verification'] ?? null) ?? 'unverified');

            if (! in_array($docType, ToolExamDocument::DOC_TYPES, true)) {
                $this->warnings[] = "{$label}: doc_type '{$docType}' is not one of ".implode(', ', ToolExamDocument::DOC_TYPES).' — row skipped';
                $stats['skipped']++;

                continue;
            }

            if (! in_array($docMode, ToolExamDocument::MODES, true)) {
                $this->warnings[] = "{$label}: mode '{$docMode}' must be upload or live_capture — row skipped";
                $stats['skipped']++;

                continue;
            }

            if (! in_array($verification, ToolExamDocument::VERIFICATIONS, true)) {
                $this->warnings[] = "{$label}: verification '{$verification}' must be one of ".implode(', ', ToolExamDocument::VERIFICATIONS).' — row skipped';
                $stats['skipped']++;

                continue;
            }

            $attrs = [
                'tool_exam_id' => $toolExamId,
                'doc_type' => $docType,
                'sort_order' => (int) ($row['sort_order'] ?? 0),
                'mode' => $docMode,
                'width_px' => $this->intOrNull($row['width_px'] ?? null),
                'height_px' => $this->intOrNull($row['height_px'] ?? null),
                'min_kb' => $this->intOrNull($row['min_kb'] ?? null),
                'max_kb' => $this->intOrNull($row['max_kb'] ?? null),
                'format' => strtolower($this->cleanString($row['format'] ?? null) ?? 'jpg'),
                'is_required' => $this->normalizeBool($row['is_required'] ?? null, true),
                'verification' => $verification,
                'source_url' => $this->cleanString($row['source_url'] ?? null),
                'verified_on' => $this->dateOrNull($row['verified_on'] ?? null),
                'note' => $this->cleanString($row['note'] ?? null),
            ];

            if ($attrs['min_kb'] !== null && $attrs['max_kb'] !== null && $attrs['min_kb'] > $attrs['max_kb']) {
                $this->warnings[] = "{$label}: min_kb {$attrs['min_kb']} is larger than max_kb {$attrs['max_kb']} — row skipped";
                $stats['skipped']++;

                continue;
            }

            $entries[] = [
                'uid' => $uid,
                'label' => $label,
                'key' => ($this->cleanString($row['exam_slug'] ?? null) ?? "exam#{$toolExamId}")."/{$docType}",
                'attrs' => $attrs + ['is_active' => true],
                'hash' => $this->hashOf(['uid' => $uid] + $attrs),
                'legacy' => ['tool_exam_id' => $toolExamId, 'doc_type' => $docType],
                'unique' => [['tool_exam_id' => $toolExamId, 'doc_type' => $docType]],
            ];
        }

        $todo = $this->plan(ToolExamDocument::class, $entries, $mode, $stats);

        // Keep the list in step with the sheet (only for exams in the sheet).
        $scopeIds = array_keys($scopeToolExamIds);
        $presentUidList = array_keys($presentUids);

        $toDeactivate = $scopeIds
            ? ToolExamDocument::query()->whereIn('tool_exam_id', $scopeIds)->where('is_active', true)
                ->where(fn ($q) => $q->whereNull('uid')->orWhereNotIn('uid', $presentUidList))->pluck('uid', 'id')
            : collect();
        $toReactivate = $presentUidList
            ? ToolExamDocument::query()->whereIn('uid', $presentUidList)->where('is_active', false)->pluck('uid', 'id')
            : collect();

        $result = function () use ($stats, $mode, $dryRun, $todo, $toDeactivate, $toReactivate): array {
            return $this->result($stats, $mode, $dryRun, $todo) + [
                'deactivated' => $toDeactivate->count(),
                'reactivated' => $toReactivate->count(),
            ];
        };

        if ($dryRun || (empty($todo) && $toDeactivate->isEmpty() && $toReactivate->isEmpty())) {
            return $result();
        }

        DB::transaction(function () use ($todo, $toDeactivate, $toReactivate) {
            $this->apply(ToolExamDocument::class, $todo);

            if ($toDeactivate->isNotEmpty()) {
                ToolExamDocument::query()->whereIn('id', $toDeactivate->keys())->update(['is_active' => false]);
            }

            if ($toReactivate->isNotEmpty()) {
                ToolExamDocument::query()->whereIn('id', $toReactivate->keys())->update(['is_active' => true]);
            }
        });

        return $result();
    }

    protected function intOrNull(mixed $value): ?int
    {
        $value = $this->cleanString($value);

        return $value !== null && is_numeric($value) && (int) $value >= 0 ? (int) $value : null;
    }

    protected function dateOrNull(mixed $value): ?string
    {
        $value = $this->cleanString($value);

        if ($value === null) {
            return null;
        }

        $time = strtotime($value);

        return $time === false ? null : date('Y-m-d', $time);
    }
}
