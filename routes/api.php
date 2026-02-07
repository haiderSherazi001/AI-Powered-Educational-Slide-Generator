<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SlideGeneratorController;

Route::post('/generate-slides', [SlideGeneratorController::class, 'generate']);

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
