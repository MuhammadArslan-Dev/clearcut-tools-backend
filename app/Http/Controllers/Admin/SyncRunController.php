<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\RunBigQuerySyncJob;
use App\Models\SyncRun;
use Illuminate\Http\Request;

/**
 * Backs the BigQuery Content Sync admin dashboard's background-run
 * flow: start() creates a SyncRun and dispatches the job, show() is
 * polled by the dashboard's JS to render live per-step progress. Kept
 * separate from Api\V1\BigQueryController, whose endpoints stay
 * synchronous for n8n/cron use where blocking on the request is fine.
 */
class SyncRunController extends Controller
{
    protected const VALID_KINDS = ['all', 'exams', 'tools', 'tool_categories', 'tool_exam_mapping', 'tool_exam_content', 'tool_exam_documents'];

    public function start(Request $request)
    {
        $kind = $request->string('kind')->toString();

        if (! in_array($kind, self::VALID_KINDS, true)) {
            return response()->json(['message' => 'Invalid kind.'], 422);
        }

        $mode = $request->input('mode', 'incremental');

        if (! in_array($mode, ['incremental', 'full'], true)) {
            return response()->json(['message' => "Invalid mode — use 'incremental' or 'full'."], 422);
        }

        $run = SyncRun::create([
            'kind' => $kind,
            'mode' => $mode,
            'dry_run' => $request->boolean('dry_run'),
            'status' => 'pending',
            'steps' => RunBigQuerySyncJob::stepsFor($kind),
        ]);

        RunBigQuerySyncJob::dispatch($run->id);

        return response()->json($run);
    }

    public function show(SyncRun $syncRun)
    {
        return response()->json($syncRun);
    }
}
