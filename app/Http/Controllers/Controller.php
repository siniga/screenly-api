<?php

namespace App\Http\Controllers;

use App\Exceptions\GenerationFailedException;
use App\Models\Project;
use App\Services\SystemErrorLogger;
use Illuminate\Http\JsonResponse;

abstract class Controller
{
    protected function generationFailed(GenerationFailedException $exception, ?Project $project = null): JsonResponse
    {
        $message = app(SystemErrorLogger::class)->log($exception, [
            'project_id' => $project?->id,
            'source' => 'generation',
            'status' => $exception->status,
        ]);

        return response()->json([
            'success' => false,
            'message' => $message,
        ], $exception->status);
    }
}
