<?php

use App\Http\Controllers\AdminReportController;
use App\Http\Controllers\ContentReportController;
use App\Http\Controllers\ReportFeedbackController;
use App\Services\Moderation\ReportTargetRegistry;
use Illuminate\Support\Facades\Route;

Route::post('/reports', [ContentReportController::class, 'store'])->middleware('throttle:reports.submit')->name('reports.store');
Route::get('/reports/new/{type}/{id}', [ContentReportController::class, 'create'])
    ->whereIn('type', ReportTargetRegistry::TYPES)->whereNumber('id')->name('reports.create');
Route::get('/reports/feedback/pending', [ReportFeedbackController::class, 'pending'])->name('reports.feedback.pending');
Route::post('/reports/feedback/{recipient}/acknowledge', [ReportFeedbackController::class, 'acknowledge'])
    ->whereNumber('recipient')->name('reports.feedback.acknowledge');

Route::prefix('admin/reports')->middleware('admin')->name('admin.reports.')->group(function () {
    Route::get('/', [AdminReportController::class, 'index'])->name('index');
    Route::get('/evidence/{report}', [AdminReportController::class, 'evidence'])->whereNumber('report')->name('evidence');
    Route::get('/{case}/assets/{image}', [AdminReportController::class, 'asset'])->whereNumber('case')->whereUuid('image')->name('asset');
    Route::get('/{case}', [AdminReportController::class, 'show'])->whereNumber('case')->name('show');
    Route::post('/{case}/actions', [AdminReportController::class, 'action'])->whereNumber('case')->middleware('throttle:admin.write')->name('action');
    Route::post('/{case}/feedback', [AdminReportController::class, 'feedback'])->whereNumber('case')->middleware('throttle:admin.write')->name('feedback');
});
