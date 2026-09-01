<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ProjectController;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return ['ok' => true];
});

Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/user', [AuthController::class, 'user']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    Route::get('/projects', [ProjectController::class, 'index']);
    Route::post('/projects', [ProjectController::class, 'store']);
    Route::get('/projects/{project}', [ProjectController::class, 'show']);
    Route::put('/projects/{project}', [ProjectController::class, 'update']);
    Route::patch('/projects/{project}', [ProjectController::class, 'update']);
    Route::delete('/projects/{project}', [ProjectController::class, 'destroy']);
    Route::post('/projects/{project}/generate-script', [ProjectController::class, 'generateScript']);
    Route::post('/projects/{project}/generate-screenplay', [ProjectController::class, 'generateScreenplay']);
    Route::post('/projects/{project}/plan-episodes', [ProjectController::class, 'planEpisodes']);
    Route::post('/projects/{project}/generate-episode', [ProjectController::class, 'generateEpisode']);
    Route::post('/projects/{project}/generate-scenes', [ProjectController::class, 'generateScenes']);
    Route::post('/projects/{project}/generate-characters', [ProjectController::class, 'generateCharacters']);
    Route::post('/projects/{project}/generate-environments', [ProjectController::class, 'generateEnvironments']);
    Route::post('/projects/{project}/generate-shots', [ProjectController::class, 'generateShots']);
    Route::post('/projects/{project}/characters/{character}/generate-image', [ProjectController::class, 'generateCharacterImage']);
    Route::post('/projects/{project}/characters/{character}/generate-costume', [ProjectController::class, 'generateCharacterCostume']);
    Route::post('/projects/{project}/environments/{environment}/generate-image', [ProjectController::class, 'generateEnvironmentImage']);
    Route::post('/projects/{project}/shots/{shot}/generate-image', [ProjectController::class, 'generateShotImage']);
});
