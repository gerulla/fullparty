<?php

use App\Http\Controllers\ApiDocumentationController;
use App\Http\Middleware\ApiDocumentationSecurityHeaders;
use App\Http\Middleware\EnsureAccountNotBanned;
use Illuminate\Support\Facades\Route;

Route::domain(config('integration_api.docs_host'))->name('api-docs.')
    ->middleware(ApiDocumentationSecurityHeaders::class)
    ->withoutMiddleware(EnsureAccountNotBanned::class)->group(function () {
        Route::get('/', [ApiDocumentationController::class, 'index'])->name('index');
        Route::get('/openapi.json', [ApiDocumentationController::class, 'specification'])->name('specification');
    });
