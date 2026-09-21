<?php

namespace Tests\Feature;

use App\Models\Exam;
use App\Models\Tool;
use App\Models\ToolCategory;
use App\Models\ToolExam;
use App\Models\ToolExamData;
use App\Models\ToolExamDocument;
use App\Services\BigQuery\BigQueryClient;
use App\Services\BigQuery\BigQueryExamSyncService;
use App\Services\BigQuery\BigQueryToolCategorySyncService;
use App\Services\BigQuery\BigQueryToolExamContentSyncService;
use App\Services\BigQuery\BigQueryToolExamDocumentSyncService;
use App\Services\BigQuery\BigQueryToolExamSyncService;
use App\Services\BigQuery\BigQueryToolSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * In-memory SQLite, fake BigQuery source — never touches the real database.
 */
class UidSyncTest extends TestCase
{
    use RefreshDatabase;

    protected FakeBigQueryClient $bq;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bq = new FakeBigQueryClient;
        $this->app->instance(BigQueryClient::class, $this->bq);
        $this->bq->tables = $this->sheet();
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    protected function sheet(): array
    {
        return [
            'tools' => [
                ['uid' => 'T_0001', 'tool_slug' => 'resizer', 'tool_name_json' => '{"en":{"name":"Resizer"},"hi":{"name":"रीसाइज़र"}}', 'description_json' => '{"en":{"text":"Resize"}}', 'status' => 'active', 'sort_order' => '1'],
            ],
            'exams' => [
                ['uid' => 'E_0001', 'exam_slug' => 'htet', 'short_name' => 'HTET', 'full_name_json' => '{"en":{"name":"Haryana TET"},"hi":{"name":"हरियाणा"}}', 'conducting_body_json' => '{"en":{"name":"BSEH"}}'],
                ['uid' => 'E_0002', 'exam_slug' => 'ctet', 'short_name' => 'CTET', 'full_name_json' => '{"en":{"name":"Central TET"}}', 'conducting_body_json' => null],
            ],
            'tool_categories' => [
                ['uid' => 'TC_0001', 'tool_uid' => 'T_0001', 'tool_slug' => 'resizer', 'category_slug' => 'teaching-exams', 'label' => '{"en":{"name":"Teaching Exams"}}', 'icon' => null, 'description' => 'desc', 'sort_order' => '1', 'is_active' => 'TRUE'],
            ],
            'tool_exam_mapping' => [
                ['uid' => 'TE_0001', 'tool_uid' => 'T_0001', 'exam_uid' => 'E_0001', 'category_uid' => 'TC_0001', 'tool_slug' => 'resizer', 'exam_slug' => 'htet', 'category_slug' => 'teaching-exams', 'public_slug' => 'htet', 'is_active' => 'TRUE', 'sort_order' => '1', 'popular_rank' => '2'],
                ['uid' => 'TE_0002', 'tool_uid' => 'T_0001', 'exam_uid' => 'E_0002', 'category_uid' => 'TC_0001', 'tool_slug' => 'resizer', 'exam_slug' => 'ctet', 'category_slug' => 'teaching-exams', 'public_slug' => 'ctet', 'is_active' => 'TRUE', 'sort_order' => '2'],
            ],
            'tool_exam_documents' => [
                ['uid' => 'TD_0001', 'mapping_uid' => 'TE_0001', 'tool_slug' => 'resizer', 'exam_slug' => 'htet', 'doc_type' => 'photo', 'sort_order' => '1', 'mode' => 'upload', 'width_px' => '200', 'height_px' => '230', 'min_kb' => '20', 'max_kb' => '50', 'format' => 'jpg', 'is_required' => 'TRUE', 'verification' => 'unverified', 'source_url' => '', 'verified_on' => '', 'note' => 'baseline'],
                ['uid' => 'TD_0002', 'mapping_uid' => 'TE_0001', 'tool_slug' => 'resizer', 'exam_slug' => 'htet', 'doc_type' => 'signature', 'sort_order' => '2', 'mode' => 'upload', 'width_px' => '140', 'height_px' => '60', 'min_kb' => '10', 'max_kb' => '20', 'format' => 'jpg', 'is_required' => 'TRUE', 'verification' => 'unverified', 'source_url' => '', 'verified_on' => '', 'note' => ''],
                ['uid' => 'TD_0003', 'mapping_uid' => 'TE_0001', 'tool_slug' => 'resizer', 'exam_slug' => 'htet', 'doc_type' => 'left_thumb', 'sort_order' => '3', 'mode' => 'upload', 'width_px' => '240', 'height_px' => '240', 'min_kb' => '20', 'max_kb' => '50', 'format' => 'jpg', 'is_required' => 'TRUE', 'verification' => 'partly_verified', 'source_url' => 'https://example.gov/notice.pdf', 'verified_on' => '2026-09-21', 'note' => ''],
                ['uid' => 'TD_0004', 'mapping_uid' => 'TE_0002', 'tool_slug' => 'resizer', 'exam_slug' => 'ctet', 'doc_type' => 'photo', 'sort_order' => '1', 'mode' => 'live_capture', 'width_px' => '', 'height_px' => '', 'min_kb' => '', 'max_kb' => '', 'format' => 'jpg', 'is_required' => 'TRUE', 'verification' => 'unverified', 'source_url' => '', 'verified_on' => '', 'note' => ''],
            ],
            'tool_exam_content' => [
                ['uid' => 'TEC_0001', 'mapping_uid' => 'TE_0001', 'tool_slug' => 'resizer', 'exam_slug' => 'htet', 'seo_title_eng' => 'HTET tool', 'seo_description_eng' => 'd', 'seo_title_hi' => 'HTET hi', 'seo_description_hi' => null, 'seo_title_pa' => 'HTET pa', 'is_placeholder' => 'FALSE', 'data_json' => '{"photoSpec":{"widthPx":200}}'],
                ['uid' => 'TEC_0002', 'mapping_uid' => 'TE_0002', 'tool_slug' => 'resizer', 'exam_slug' => 'ctet', 'seo_title_eng' => 'CTET tool', 'is_placeholder' => 'TRUE', 'data_json' => '{}'],
            ],
        ];
    }

    protected function syncAll(string $mode = 'incremental', bool $dryRun = false): array
    {
        return [
            'exams' => app(BigQueryExamSyncService::class)->sync($mode, $dryRun),
            'tools' => app(BigQueryToolSyncService::class)->sync($mode, $dryRun),
            'tool_categories' => app(BigQueryToolCategorySyncService::class)->sync($mode, $dryRun),
            'tool_exam_mapping' => app(BigQueryToolExamSyncService::class)->sync($mode, $dryRun),
            'tool_exam_content' => app(BigQueryToolExamContentSyncService::class)->sync($mode, $dryRun),
        ];
    }

    public function test_first_sync_creates_rows_with_uid_and_hash(): void
    {
        $r = $this->syncAll();

        $this->assertSame(2, $r['exams']['stats']['created']);
        $this->assertSame(1, $r['tools']['stats']['created']);
        $this->assertSame(1, $r['tool_categories']['stats']['created']);
        $this->assertSame(2, $r['tool_exam_mapping']['stats']['created']);
        $this->assertSame(2, $r['tool_exam_content']['stats']['created']);
        $this->assertSame([], array_merge(...array_column($r, 'warnings')));

        $this->assertSame(2, Exam::count());
        $this->assertNotNull(Exam::where('uid', 'E_0001')->value('content_hash'));
        $this->assertSame(2, ToolExam::count());
        $this->assertSame(2, ToolExamData::count());

        $htet = ToolExam::where('uid', 'TE_0001')->first();
        $this->assertSame(Exam::where('uid', 'E_0001')->value('id'), $htet->exam_id);
        $this->assertSame(ToolCategory::where('uid', 'TC_0001')->value('id'), $htet->tool_category_id);
        $this->assertSame(['photoSpec' => ['widthPx' => 200]], $htet->data->data_json);

        // Punjabi flat columns ("pa") land as locale "pa"; "eng" as "en".
        $locales = $htet->translations()->pluck('locale')->sort()->values()->all();
        $this->assertSame(['en', 'hi', 'pa'], $locales);
    }

    public function test_second_incremental_sync_writes_nothing(): void
    {
        $this->syncAll();
        $r = $this->syncAll();

        foreach ($r as $step => $result) {
            $this->assertSame(0, $result['stats']['created'], "$step created");
            $this->assertSame(0, $result['stats']['updated'], "$step updated");
            $this->assertSame($step === 'tools' || $step === 'tool_categories' ? 1 : 2, $result['stats']['unchanged'], "$step unchanged");
        }
    }

    public function test_only_the_edited_row_is_synced(): void
    {
        $this->syncAll();
        $this->bq->tables['exams'][0]['short_name'] = 'HTET-2';

        $r = $this->syncAll();

        $this->assertSame(1, $r['exams']['stats']['updated']);
        $this->assertSame(1, $r['exams']['stats']['unchanged']);
        $this->assertSame('HTET-2', Exam::where('uid', 'E_0001')->first()->translations()->where('locale', 'en')->value('short_name'));
        // Downstream sheets untouched.
        $this->assertSame(0, $r['tool_exam_mapping']['stats']['updated']);
        $this->assertSame(0, $r['tool_exam_content']['stats']['updated']);
    }

    public function test_slug_rename_updates_the_same_row_instead_of_creating_one(): void
    {
        $this->syncAll();
        $idBefore = Exam::where('uid', 'E_0001')->value('id');

        // Rename the exam slug AND the redundant slug copies in the child
        // sheet is deliberately NOT done: children point at parents by uid.
        $this->bq->tables['exams'][0]['exam_slug'] = 'haryana-tet';

        $r = $this->syncAll();

        $this->assertSame(0, $r['exams']['stats']['created']);
        $this->assertSame(1, $r['exams']['stats']['updated']);
        $this->assertSame(2, Exam::count());
        $this->assertSame($idBefore, Exam::where('exam_slug', 'haryana-tet')->value('id'));
        $this->assertNull(Exam::where('exam_slug', 'htet')->first());

        // Mapping row TE_0001 still says exam_slug=htet, but resolves via exam_uid.
        $this->assertSame([], $r['tool_exam_mapping']['warnings']);
        $this->assertSame($idBefore, ToolExam::where('uid', 'TE_0001')->value('exam_id'));
    }

    public function test_full_mode_rewrites_every_row(): void
    {
        $this->syncAll();
        $r = $this->syncAll('full');

        $this->assertSame(2, $r['exams']['stats']['updated']);
        $this->assertSame(0, $r['exams']['stats']['unchanged']);
        $this->assertSame(2, $r['tool_exam_mapping']['stats']['updated']);
        $this->assertSame(2, $r['tool_exam_content']['stats']['updated']);
        $this->assertSame(2, Exam::count());
    }

    public function test_dry_run_reports_but_writes_nothing(): void
    {
        $r = $this->syncAll('incremental', true);

        $this->assertTrue($r['exams']['dry_run']);
        $this->assertSame(2, $r['exams']['stats']['created']);
        $this->assertSame(0, Exam::count());
        $this->assertSame(0, Tool::count());
    }

    public function test_pre_uid_rows_are_adopted_not_duplicated(): void
    {
        $legacy = Exam::create(['exam_slug' => 'htet']);
        $this->assertNull($legacy->uid);

        $r = $this->syncAll();

        $this->assertSame(1, $r['exams']['stats']['created']); // only ctet
        $this->assertSame(1, $r['exams']['stats']['updated']); // htet adopted
        $this->assertSame(2, Exam::count());
        $this->assertSame('E_0001', $legacy->fresh()->uid);
    }

    public function test_row_without_uid_is_skipped_with_a_warning(): void
    {
        $this->bq->tables['exams'][1]['uid'] = '';

        $r = $this->syncAll();

        $this->assertSame(1, $r['exams']['stats']['created']);
        $this->assertSame(1, $r['exams']['stats']['skipped']);
        $this->assertStringContainsString('missing uid', $r['exams']['warnings'][0]);
        $this->assertSame(1, Exam::count());
    }

    public function test_source_without_any_uid_fails_loudly(): void
    {
        foreach ($this->bq->tables['exams'] as &$row) {
            unset($row['uid']);
        }
        unset($row);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("has no 'uid' values");

        app(BigQueryExamSyncService::class)->sync();
    }

    public function test_slug_owned_by_another_uid_is_refused(): void
    {
        $this->syncAll();
        $this->bq->tables['exams'][1]['exam_slug'] = 'htet'; // E_0002 tries to take E_0001's slug

        $r = app(BigQueryExamSyncService::class)->sync();

        $this->assertSame(1, $r['stats']['skipped']);
        $this->assertStringContainsString("exam_slug='htet'", $r['warnings'][0]);
        $this->assertSame('ctet', Exam::where('uid', 'E_0002')->value('exam_slug'));
    }

    public function test_public_slug_and_category_label_changes_are_reported_as_url_changes(): void
    {
        $this->syncAll();
        $this->bq->tables['tool_exam_mapping'][0]['public_slug'] = 'haryana-tet';
        $this->bq->tables['tool_categories'][0]['label'] = '{"en":{"name":"Teacher Exams"}}';

        $r = $this->syncAll();

        $this->assertCount(1, $r['tool_exam_mapping']['url_changes']);
        $this->assertStringContainsString("'htet' → 'haryana-tet'", $r['tool_exam_mapping']['url_changes'][0]);
        $this->assertCount(1, $r['tool_categories']['url_changes']);
        $this->assertStringContainsString("'Teaching Exams' → 'Teacher Exams'", $r['tool_categories']['url_changes'][0]);
    }

    public function test_unknown_category_uid_saves_the_row_without_a_category(): void
    {
        $this->bq->tables['tool_exam_mapping'][0]['category_uid'] = 'TC_9999';

        $r = $this->syncAll();

        $this->assertSame(2, $r['tool_exam_mapping']['stats']['created']);
        $this->assertNull(ToolExam::where('uid', 'TE_0001')->value('tool_category_id'));
        $this->assertStringContainsString("category_uid 'TC_9999' not found", $r['tool_exam_mapping']['warnings'][0]);
    }

    public function test_inactive_rows_are_hidden_from_the_api_unless_asked_for(): void
    {
        $this->bq->tables['tool_exam_mapping'][1]['is_active'] = 'FALSE';
        $this->syncAll();

        $slugs = collect($this->getJson('/api/v1/tools/resizer/exams')->assertOk()->json('data'))->pluck('public_slug')->all();
        $this->assertSame(['htet'], $slugs);

        $this->getJson('/api/v1/tools/resizer/exams/ctet')->assertNotFound();
        $this->getJson('/api/v1/tools/resizer/exams/htet')->assertOk();

        $inactive = collect($this->getJson('/api/v1/tools/resizer/exams?filter[is_active]=0')->json('data'))->pluck('public_slug')->all();
        $this->assertSame(['ctet'], $inactive);
    }

    public function test_popular_rank_is_synced_and_exposed_by_the_api(): void
    {
        $this->bq->tables['tool_exam_mapping'][1]['popular_rank'] = 'abc'; // not a number -> not popular
        $this->syncAll();

        $this->assertSame(2, ToolExam::where('uid', 'TE_0001')->value('popular_rank'));
        $this->assertNull(ToolExam::where('uid', 'TE_0002')->value('popular_rank'));

        $list = collect($this->getJson('/api/v1/tools/resizer/exams')->assertOk()->json('data'))->keyBy('public_slug');
        $this->assertSame(2, $list['htet']['popular_rank']);
        $this->assertNull($list['ctet']['popular_rank']);

        // Changing only the rank is picked up by an incremental sync.
        $this->bq->tables['tool_exam_mapping'][0]['popular_rank'] = '1';
        $r = $this->syncAll();
        $this->assertSame(1, $r['tool_exam_mapping']['stats']['updated']);
        $this->assertSame(1, ToolExam::where('uid', 'TE_0001')->value('popular_rank'));
    }

    protected function syncDocs(string $mode = 'incremental', bool $dryRun = false): array
    {
        return app(BigQueryToolExamDocumentSyncService::class)->sync($mode, $dryRun);
    }

    /** @return array<int, array<string, mixed>> */
    protected function apiDocs(string $slug): array
    {
        return $this->getJson("/api/v1/tools/resizer/exams/{$slug}")->assertOk()->json('data.documents');
    }

    public function test_documents_are_synced_and_served_in_order(): void
    {
        $this->syncAll();
        $r = $this->syncDocs();

        $this->assertSame(4, $r['stats']['created']);
        $this->assertSame([], $r['warnings']);
        $this->assertSame(4, ToolExamDocument::count());

        $htet = $this->apiDocs('htet');
        $this->assertSame(['photo', 'signature', 'left_thumb'], array_column($htet, 'type'));
        $this->assertSame(['widthPx' => 200, 'heightPx' => 230, 'minSizeKB' => 20, 'maxSizeKB' => 50], [
            'widthPx' => $htet[0]['widthPx'], 'heightPx' => $htet[0]['heightPx'], 'minSizeKB' => $htet[0]['minSizeKB'], 'maxSizeKB' => $htet[0]['maxSizeKB'],
        ]);
        $this->assertSame('partly_verified', $htet[2]['verification']);
        $this->assertTrue($htet[0]['required']);

        $ctet = $this->apiDocs('ctet');
        $this->assertSame('live_capture', $ctet[0]['mode']);
        $this->assertNull($ctet[0]['widthPx']);
        $this->assertSame('2026-09-21', ToolExamDocument::where('uid', 'TD_0003')->first()->verified_on->toDateString());
    }

    public function test_documents_incremental_sync_only_touches_changed_rows(): void
    {
        $this->syncAll();
        $this->syncDocs();

        $again = $this->syncDocs();
        $this->assertSame(0, $again['stats']['updated'] + $again['stats']['created']);
        $this->assertSame(4, $again['stats']['unchanged']);
        $this->assertSame(0, $again['deactivated']);

        $this->bq->tables['tool_exam_documents'][1]['max_kb'] = '30';
        $r = $this->syncDocs();
        $this->assertSame(1, $r['stats']['updated']);
        $this->assertSame(3, $r['stats']['unchanged']);
        $this->assertSame(30, ToolExamDocument::where('uid', 'TD_0002')->value('max_kb'));
    }

    public function test_a_document_removed_from_the_sheet_is_deactivated_then_reactivated(): void
    {
        $this->syncAll();
        $this->syncDocs();

        $signatureRow = $this->bq->tables['tool_exam_documents'][1];
        unset($this->bq->tables['tool_exam_documents'][1]);
        $this->bq->tables['tool_exam_documents'] = array_values($this->bq->tables['tool_exam_documents']);

        $r = $this->syncDocs();
        $this->assertSame(1, $r['deactivated']);
        $this->assertSame(['photo', 'left_thumb'], array_column($this->apiDocs('htet'), 'type'));
        $this->assertFalse(ToolExamDocument::where('uid', 'TD_0002')->first()->is_active); // kept, not deleted

        // Put it back: same content, so it is "unchanged" but must come back to life.
        array_splice($this->bq->tables['tool_exam_documents'], 1, 0, [$signatureRow]);
        $r = $this->syncDocs();
        $this->assertSame(1, $r['reactivated']);
        $this->assertSame(0, $r['stats']['updated']);
        $this->assertSame(['photo', 'signature', 'left_thumb'], array_column($this->apiDocs('htet'), 'type'));
    }

    public function test_exams_absent_from_the_documents_sheet_keep_their_documents(): void
    {
        $this->syncAll();
        $this->syncDocs();

        // The sheet now only mentions htet; ctet's documents must stay active.
        $this->bq->tables['tool_exam_documents'] = array_slice($this->bq->tables['tool_exam_documents'], 0, 3);
        $r = $this->syncDocs();

        $this->assertSame(0, $r['deactivated']);
        $this->assertCount(1, $this->apiDocs('ctet'));
    }

    public function test_invalid_document_rows_are_skipped_and_do_not_deactivate_the_existing_row(): void
    {
        $this->syncAll();
        $this->syncDocs();

        $this->bq->tables['tool_exam_documents'][1]['doc_type'] = 'selfie';                    // unknown type
        $this->bq->tables['tool_exam_documents'][2]['min_kb'] = '90';                          // min > max (50)
        $this->bq->tables['tool_exam_documents'][3]['mode'] = 'camera';                         // unknown mode
        $r = $this->syncDocs();

        $this->assertSame(3, $r['stats']['skipped']);
        $this->assertStringContainsString("doc_type 'selfie'", implode(' | ', $r['warnings']));
        $this->assertStringContainsString('min_kb 90 is larger than max_kb 50', implode(' | ', $r['warnings']));
        $this->assertStringContainsString("mode 'camera'", implode(' | ', $r['warnings']));
        $this->assertSame(0, $r['deactivated']); // their uids are still in the sheet
        $this->assertSame(4, ToolExamDocument::where('is_active', true)->count());
    }

    public function test_the_same_document_type_twice_for_one_exam_is_refused(): void
    {
        $this->syncAll();
        $dup = $this->bq->tables['tool_exam_documents'][0];
        $dup['uid'] = 'TD_0099';
        $this->bq->tables['tool_exam_documents'][] = $dup;

        $r = $this->syncDocs();

        $this->assertSame(1, $r['stats']['skipped']);
        $this->assertSame(4, $r['stats']['created']);
        $this->assertStringContainsString('used by another uid in the same source', $r['warnings'][0]);
    }

    public function test_documents_dry_run_writes_nothing_and_unknown_mapping_is_skipped(): void
    {
        $this->syncAll();
        $this->bq->tables['tool_exam_documents'][3]['mapping_uid'] = 'TE_9999';

        $r = $this->syncDocs('incremental', true);

        $this->assertTrue($r['dry_run']);
        $this->assertSame(3, $r['stats']['created']);
        $this->assertSame(1, $r['stats']['skipped']);
        $this->assertStringContainsString("mapping_uid 'TE_9999' not found", $r['warnings'][0]);
        $this->assertSame(0, ToolExamDocument::count());
    }

    public function test_invalid_mode_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);

        app(BigQueryExamSyncService::class)->sync('everything');
    }
}

class FakeBigQueryClient extends BigQueryClient
{
    /** @var array<string, array<int, array<string, mixed>>> */
    public array $tables = [];

    public function __construct() {}

    public function runQuery(string $query, bool $useCache = false): array
    {
        preg_match('/content\.(\w+)`/', $query, $m);

        // BigQuery names these two tables tools_exam_*; the fixtures use the sheet's tool_exam_* names.
        return $this->tables[str_replace('tools_exam_', 'tool_exam_', $m[1])] ?? [];
    }
}
