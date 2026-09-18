<?php

use App\Http\Controllers\Api\V1\BigQueryController;
use App\Http\Controllers\Api\V1\ToolCategoryController;
use App\Http\Controllers\Api\V1\ToolController;
use App\Http\Controllers\Api\V1\ToolExamController;
use Illuminate\Support\Facades\Route;

Route::prefix('tools')->group(function () {
    Route::get('/', [ToolController::class, 'index'])->name('tools.index');
    Route::get('/{tool:tool_slug}', [ToolController::class, 'show'])->name('tools.show');
    Route::get('/{tool:tool_slug}/categories', [ToolCategoryController::class, 'index'])->name('tools.categories.index');
    Route::get('/{tool:tool_slug}/exams', [ToolExamController::class, 'index'])->name('tools.exams.index');
    Route::get('/{tool:tool_slug}/exams/{publicSlug}', [ToolExamController::class, 'show'])->name('tools.exams.show');
});

Route::prefix('bigquery')->controller(BigQueryController::class)->group(function () {
    Route::get('/syncExam', 'syncExam')->name('bigquery.syncExam');
    Route::get('/syncTools', 'syncTools')->name('bigquery.syncTools');
    Route::get('/syncToolCategories', 'syncToolCategories')->name('bigquery.syncToolCategories');
    Route::get('/syncToolExamMapping', 'syncToolExamMapping')->name('bigquery.syncToolExamMapping');
    Route::get('/syncToolExamContent', 'syncToolExamContent')->name('bigquery.syncToolExamContent');
    Route::get('/syncAll', 'syncAll')->name('bigquery.syncAll');
});
