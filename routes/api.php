<?php

use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\FeedbackController;
use App\Http\Controllers\Api\QueryController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'throttle:rag-api'])
    ->prefix('v1')
    ->group(function (): void {
        Route::get('documents', [DocumentController::class, 'index']);
        Route::post('documents', [DocumentController::class, 'store']);
        Route::get('documents/{id}', [DocumentController::class, 'show']);
        Route::delete('documents/{id}', [DocumentController::class, 'destroy']);

        Route::get('queries', [QueryController::class, 'index']);
        Route::post('queries', [QueryController::class, 'store']);
        Route::post('queries/{queryLogId}/feedback', [FeedbackController::class, 'store']);
    });
