<?php

namespace App\Jobs;

use App\Models\SyncRun;
use App\Services\BigQuery\BigQueryExamSyncService;
use App\Services\BigQuery\BigQueryToolCategorySyncService;
use App\Services\BigQuery\BigQueryToolExamContentSyncService;
use App\Services\BigQuery\BigQueryToolExamDocumentSyncService;
use App\Services\BigQuery\BigQueryToolExamSyncService;
use App\Services\BigQuery\BigQueryToolSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
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
     * Overrides the worker's `--tries=1` (see QueueWorkerManager) for this
     * job specifically. `--tries=1` is fine for a job that either runs or
     * doesn't, but WithoutOverlapping's release-and-retry (see
     * middleware() below) needs at least one real retry to work at all —
     * with tries=1, a job released because the lock was busy fails
     * permanently (MaxAttemptsExceededException) the instant it's
     * re-attempted, rather than getting the second try the whole point
     * of releasing was to provide. Found by hitting exactly this while
     * testing the WithoutOverlapping fix above.
     */
    public int $tries = 5;

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
        'tool_exam_documents' => ['Tool Exam Documents', BigQueryToolExamDocumentSyncService::class],
    ];

    public function __construct(protected int $syncRunId) {}

    /**
     * All steps write to (or read dependencies from) the same handful of
     * tables, so two syncs — from a genuine double-worker mistake, a
     * double-click, or a queued run overlapping a manual "Sync" click —
     * must never execute at once: two `Exam::updateOrCreate()` calls
     * racing on the same `exam_slug` inside two long-lived transactions
     * is exactly the deadlock this project hit in practice. The lock is
     * global (one key for every kind) rather than per-`kind` because the
     * dependency chain (categories needs tools, mapping needs tools +
     * exams + categories, ...) means even two *different* kinds running
     * concurrently can race on shared rows.
     *
     * `expireAfter` bounds the lock to the lifetime of one run so a
     * worker that dies mid-sync (crash, `taskkill`, deploy restart)
     * can't leave every future run permanently blocked — comfortably
     * above how long a full "Sync All" takes against a remote DB.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('bigquery-sync'))->expireAfter(1800)->releaseAfter(30),
        ];
    }

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
                $result = $service->sync($run->mode ?? 'incremental', (bool) $run->dry_run);
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
