<?php

use App\Http\Controllers\Admin\QueueWorkerController;
use App\Http\Controllers\Admin\SyncRunController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Internal-use only — no auth, matching the /api/v1/bigquery/* endpoints
// the synchronous "Sync" buttons still call (see routes/api_v1.php). Add
// auth middleware here before exposing this beyond local/trusted access.
Route::get('/admin/bigquery-sync', function () {
    return view('admin.bigquery-sync');
})->name('admin.bigquery-sync');

// Background sync runs, polled by the dashboard's JS for live progress —
// see App\Jobs\RunBigQuerySyncJob and App\Models\SyncRun. Requires a
// queue worker running (`php artisan queue:work`), since QUEUE_CONNECTION
// is "database" here, not "sync".
Route::post('/admin/bigquery-sync/runs', [SyncRunController::class, 'start'])->name('admin.bigquery-sync.runs.start');
Route::get('/admin/bigquery-sync/runs/{syncRun}', [SyncRunController::class, 'show'])->name('admin.bigquery-sync.runs.show');

// Local-dev queue worker control — see QueueWorkerManager.
Route::get('/admin/bigquery-sync/worker', [QueueWorkerController::class, 'status'])->name('admin.bigquery-sync.worker.status');
Route::post('/admin/bigquery-sync/worker/start', [QueueWorkerController::class, 'start'])->name('admin.bigquery-sync.worker.start');
Route::post('/admin/bigquery-sync/worker/stop', [QueueWorkerController::class, 'stop'])->name('admin.bigquery-sync.worker.stop');
