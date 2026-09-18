<?php

namespace App\Jobs;

use App\Models\SyncRun;
use App\Services\BigQuery\BigQueryExamSyncService;
use App\Services\BigQuery\BigQueryToolCategorySyncService;
use App\Services\BigQuery\BigQueryToolExamContentSyncService;
use App\Services\BigQuery\BigQueryToolExamSyncService;
use App\Services\BigQuery\BigQueryToolSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs one or all of the BigQuery content syncs in the background so the
 * admin dashboard's "Sync"/"Sync All" buttons don't block on however
 * long the BigQuery round-trips take — the triggering request only
 * creates the SyncRun row and dispatches this job; the dashboard then
 * polls that row for live per-step progress (see SyncRun, routes/web.php).
 *
 * Steps always run in the same dependency order used by
 * BigQueryController::syncAll(), regardless of which `kind` was
 * requested — a single-step run just has one entry in STEP_ORDER.
 */
class RunBigQuerySyncJob implements ShouldQueue
{
    use Queueable;

    /**
     * key => [label, service class]. Order here is the required
     * dependency order (exams/tools have none; categories needs tools;
     * mapping needs tools+exams+categories; content needs mapping).
     */
    protected const STEP_ORDER = [
        'exams' => ['Exams', BigQueryExamSyncService::class],
        'tools' => ['Tools', BigQueryToolSyncService::class],
        'tool_categories' => ['Tool Categories', BigQueryToolCategorySyncService::class],
        'tool_exam_mapping' => ['Tool ↔ Exam Mapping', BigQueryToolExamSyncService::class],
        'tool_exam_content' => ['Tool Exam Content (SEO)', BigQueryToolExamContentSyncService::class],
    ];

    public function __construct(protected int $syncRunId) {}

    /**
     * Builds the initial `steps` skeleton for a run of the given kind —
     * called before dispatch so the admin UI has something to render
     * (all steps "pending") the instant the run is created, without
     * waiting for the job to even start.
     *
     * @return array<int, array{key: string, label: string, status: string}>
     */
    public static function stepsFor(string $kind): array
    {
        $keys = $kind === 'all' ? array_keys(self::STEP_ORDER) : [$kind];

        return array_map(
            fn (string $key) => ['key' => $key, 'label' => self::STEP_ORDER[$key][0], 'status' => 'pending'],
            $keys,
        );
    }

    public function handle(): void
    {
        $run = SyncRun::findOrFail($this->syncRunId);
        $run->update(['status' => 'running', 'started_at' => now()]);

        $keys = $run->kind === 'all' ? array_keys(self::STEP_ORDER) : [$run->kind];
        $anyFailed = false;

        foreach ($keys as $key) {
            [, $serviceClass] = self::STEP_ORDER[$key];

            $run->markStepRunning($key);

            try {
                $service = app($serviceClass);
                $result = $service->sync();
                $run->markStepDone($key, $result);
            } catch (Throwable $e) {
                $run->markStepFailed($key, $e->getMessage());
                $anyFailed = true;
            }
        }

        $run->update([
            'status' => $anyFailed ? 'failed' : 'completed',
            'completed_at' => now(),
        ]);
    }
}
