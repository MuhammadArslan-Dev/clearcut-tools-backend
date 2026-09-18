<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Services\BigQuery\BigQueryExamSyncService;
use App\Services\BigQuery\BigQueryToolCategorySyncService;
use App\Services\BigQuery\BigQueryToolExamContentSyncService;
use App\Services\BigQuery\BigQueryToolExamSyncService;
use App\Services\BigQuery\BigQueryToolSyncService;
use RuntimeException;
use Throwable;

class BigQueryController extends Controller
{
    /**
     * GET /api/v1/bigquery/syncExam
     *
     * Pulls `clear-cutoff-435016.content.exams` and upserts it into this
     * app's exams/exam_translations tables — mirrors the main ClearCutOff
     * backend's /api/v1/bigquery/syncExam (App\Repositories\ExamRepository
     * ::syncExam), same trigger shape (plain GET, no body) for n8n/manual
     * use, but scoped to this app's own exams schema and BigQuery table.
     */
    public function syncExam(BigQueryExamSyncService $service)
    {
        return $this->runSync($service, 'Exam sync complete');
    }

    /**
     * GET /api/v1/bigquery/syncTools
     *
     * Pulls `clear-cutoff-435016.content.tools`. No dependency on the
     * other sync endpoints — safe to run any time.
     */
    public function syncTools(BigQueryToolSyncService $service)
    {
        return $this->runSync($service, 'Tool sync complete');
    }

    /**
     * GET /api/v1/bigquery/syncToolCategories
     *
     * Pulls `clear-cutoff-435016.content.tool_categories`. Requires
     * syncTools to have already run at least once (resolves tool_id by
     * tool_slug) — a row whose tool isn't found is skipped and reported
     * in `warnings`, not fatal to the rest of the sync.
     */
    public function syncToolCategories(BigQueryToolCategorySyncService $service)
    {
        return $this->runSync($service, 'Tool category sync complete');
    }

    /**
     * GET /api/v1/bigquery/syncToolExamMapping
     *
     * Pulls `clear-cutoff-435016.content.tool_exam_mapping`. Requires
     * syncTools, syncExam, and syncToolCategories to have already run.
     */
    public function syncToolExamMapping(BigQueryToolExamSyncService $service)
    {
        return $this->runSync($service, 'Tool exam mapping sync complete');
    }

    /**
     * GET /api/v1/bigquery/syncToolExamContent
     *
     * Pulls `clear-cutoff-435016.content.tool_exam_content`. Requires
     * syncToolExamMapping to have already run (resolves tool_exam_id by
     * tool_slug + exam_slug).
     */
    public function syncToolExamContent(BigQueryToolExamContentSyncService $service)
    {
        return $this->runSync($service, 'Tool exam content sync complete');
    }

    /**
     * GET /api/v1/bigquery/syncAll
     *
     * Runs all 5 content syncs in their required dependency order
     * (exams/tools have none; categories needs tools; mapping needs
     * tools+exams+categories; content needs mapping) — the single
     * endpoint to hit from n8n/cron for a full refresh instead of
     * calling each one in sequence yourself. One sync's exception
     * doesn't abort the rest; each step's own result (or error) is
     * reported under its own key.
     */
    public function syncAll(
        BigQueryExamSyncService $exams,
        BigQueryToolSyncService $tools,
        BigQueryToolCategorySyncService $toolCategories,
        BigQueryToolExamSyncService $toolExamMapping,
        BigQueryToolExamContentSyncService $toolExamContent,
    ) {
        $steps = [
            'exams' => $exams,
            'tools' => $tools,
            'tool_categories' => $toolCategories,
            'tool_exam_mapping' => $toolExamMapping,
            'tool_exam_content' => $toolExamContent,
        ];

        $results = [];

        foreach ($steps as $key => $service) {
            try {
                $results[$key] = $service->sync();
            } catch (Throwable $e) {
                $results[$key] = ['error' => $e->getMessage()];
            }
        }

        return ApiResponse::success($results, 'Full content sync complete');
    }

    private function runSync(object $service, string $successMessage)
    {
        try {
            $result = $service->sync();
        } catch (RuntimeException $e) {
            // Config/credentials problems — message is already written to
            // be read directly by whoever is setting this up.
            return ApiResponse::error($e->getMessage(), 500);
        } catch (Throwable $e) {
            return ApiResponse::error('Sync failed: '.$e->getMessage(), 500);
        }

        return ApiResponse::success($result, $successMessage);
    }
}
