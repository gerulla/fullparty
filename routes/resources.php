<?php

use App\Http\Controllers\Resources\PublicResourceController;
use App\Http\Controllers\Resources\PublicResourceReportController;
use App\Http\Controllers\Resources\ResourceImageController;
use Illuminate\Support\Facades\Route;

Route::domain(config('group_resources.public_host'))->name('public-resources.')->middleware('throttle:120,1')->group(function () {
    Route::post('/{group:slug}/reports', [PublicResourceReportController::class, 'store'])->middleware('throttle:reports.guest')->name('reports.store');
    Route::get('/resource-assets/{image:uuid}', [ResourceImageController::class, 'show'])->name('images.show');
    Route::get('/{group:slug}', [PublicResourceController::class, 'index'])->name('index');
    Route::get('/{group:slug}/collections/{collectionSlug}', [PublicResourceController::class, 'index'])->name('collections.show');
    Route::get('/{group:slug}/holsters/{holster}', [PublicResourceController::class, 'holster'])->whereNumber('holster')->name('holsters.show');
    Route::get('/{group:slug}/{slug}', [PublicResourceController::class, 'show'])->name('show');
    Route::get('/{group:slug}/{slug}/history', [PublicResourceController::class, 'history'])->name('history');
});

Route::get('/resource-assets/{image:uuid}', [ResourceImageController::class, 'show'])->middleware('throttle:120,1')->name('resource-images.show');
