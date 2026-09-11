<?php

use App\Http\Controllers\Resources\ResourceCollectionController;
use App\Http\Controllers\Resources\ResourceImageController;
use App\Http\Controllers\Resources\ResourceLibraryController;
use App\Http\Controllers\Resources\ResourceMutationController;
use App\Http\Controllers\Resources\ResourceOrganizationController;
use Illuminate\Support\Facades\Route;

Route::prefix('content/resources')->name('groups.dashboard.resources.')->middleware('throttle:120,1')->group(function () {
    Route::put('/library', [ResourceLibraryController::class, 'update'])->name('library.update');
    Route::delete('/library/resources', [ResourceLibraryController::class, 'destroyResources'])->name('library.resources.destroy');
    Route::post('/collections', [ResourceCollectionController::class, 'store'])->name('collections.store');
    Route::post('/organization', ResourceOrganizationController::class)->name('organization');
    Route::put('/collections/{collection}', [ResourceCollectionController::class, 'update'])->name('collections.update');
    Route::delete('/collections/{collection}', [ResourceCollectionController::class, 'destroy'])->name('collections.destroy');
    Route::post('/collections/{collection}/reorder', [ResourceCollectionController::class, 'reorder'])->whereNumber('collection')->name('collections.reorder');
    Route::post('/images', [ResourceImageController::class, 'store'])->middleware('throttle:20,1')->name('images.store');
    Route::get('/images', [ResourceImageController::class, 'index'])->name('images.index');
    Route::put('/images/{image}', [ResourceImageController::class, 'update'])->whereUuid('image')->name('images.update');
    Route::delete('/images/{image}', [ResourceImageController::class, 'destroy'])->whereUuid('image')->name('images.destroy');
    Route::post('/', [ResourceMutationController::class, 'store'])->name('store');
    Route::delete('/{resource}', [ResourceMutationController::class, 'destroy'])->whereNumber('resource')->name('destroy');
    Route::get('/{resource}/revisions/{revisionId}', [ResourceMutationController::class, 'revision'])->whereNumber(['resource', 'revisionId'])->name('revisions.show');
    Route::post('/{resource}/{operation}', [ResourceMutationController::class, 'update'])->whereNumber('resource')->where('operation', 'acquire|heartbeat|release|autosave|save|publish|restore|archive|unarchive|unpublish|organize')->name('update');
});
