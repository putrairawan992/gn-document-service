<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\DocumentController;

Route::group(['prefix' => 'v1'], function () {
    Route::middleware([\App\Http\Middleware\ValidateToken::class])->group(function () {
        Route::get('/list', [DocumentController::class, 'index']);
        Route::get('/detail/{id}', [DocumentController::class, 'show']);
        Route::post('/create', [DocumentController::class, 'store']);
        Route::put('/update/{id}', [DocumentController::class, 'update']);
        Route::delete('/delete/{id}', [DocumentController::class, 'destroy']);
    });
});
