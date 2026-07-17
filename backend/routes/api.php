<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\NoteController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider or bootstrap/app.php.
|
*/

Route::get('/health', function () {
    return response()->json(['status' => 'ok']);
});


Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Protected API Routes (Requires active Sanctum Token)
Route::middleware(['auth:sanctum', 'throttle:60,1'])->group(function () {
    // Session Terminating
    Route::post('/logout', [AuthController::class, 'logout']);

    // Notes CRUD endpoints
    Route::get('/notes', [NoteController::class, 'index']);
    Route::post('/notes', [NoteController::class, 'store']);
    Route::get('/notes/{id}', [NoteController::class, 'show']);
    Route::put('/notes/{id}', [NoteController::class, 'update']);
    Route::delete('/notes/{id}', [NoteController::class, 'destroy']);

    // AI Semantic Search Endpoint
    Route::get('/search', [NoteController::class, 'search']);

    // AI Summary Generation Endpoint
    Route::post('/notes/{id}/summary', [NoteController::class, 'generateSummary']);
});
