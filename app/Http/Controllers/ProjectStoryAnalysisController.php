<?php

namespace App\Http\Controllers;

use App\Exceptions\GenerationFailedException;
use App\Http\Requests\AnalyzeStoryRequest;
use App\Models\Project;
use App\Models\ProjectStoryAnalysis;
use App\Services\ProjectStoryAnalysisService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectStoryAnalysisController extends Controller
{
    public function show(Request $request, Project $project, ProjectStoryAnalysisService $analyses): JsonResponse
    {
        $this->authorizeProject($request, $project);

        $current = $analyses->currentFor($project);
        $latest = $analyses->latestFor($project);
        $row = $current ?? $latest;

        return response()->json([
            'success' => true,
            'current' => $current !== null,
            'story_hash' => trim((string) ($project->story ?? '')) === ''
                ? null
                : ProjectStoryAnalysisService::hash((string) $project->story),
            'story_analysis' => $row ? $this->serialize($row, $current?->id === $row->id) : null,
        ]);
    }

    public function store(
        AnalyzeStoryRequest $request,
        Project $project,
        ProjectStoryAnalysisService $analyses,
    ): JsonResponse {
        $this->authorizeProject($request, $project);
        set_time_limit(180);

        $data = $request->validated();
        if (array_key_exists('story', $data) && is_string($data['story']) && trim($data['story']) !== '') {
            $project->story = $data['story'];
            $project->save();
        }

        if (trim((string) ($project->story ?? '')) === '') {
            return response()->json([
                'success' => false,
                'message' => 'Story is required before analysis.',
            ], 422);
        }

        $hash = ProjectStoryAnalysisService::hash((string) ($project->story ?? ''));
        $existing = $analyses->currentFor($project);
        $force = $request->boolean('force');

        try {
            $row = $analyses->analyze($project, $force);
        } catch (GenerationFailedException $exception) {
            return $this->generationFailed($exception, $project);
        }

        return response()->json([
            'success' => true,
            'reused' => $existing !== null && ! $force && $existing->id === $row->id,
            'current' => $row->isCompleted() && $row->matchesStoryHash($hash),
            'story_analysis' => $this->serialize($row, true),
        ], $existing !== null && ! $force && $existing->id === $row->id ? 200 : 201);
    }

    private function authorizeProject(Request $request, Project $project): void
    {
        abort_unless($project->user_id === $request->user()->id, 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(ProjectStoryAnalysis $row, bool $current): array
    {
        return [
            'id' => $row->id,
            'project_id' => $row->project_id,
            'story_hash' => $row->story_hash,
            'story_version' => $row->story_version,
            'status' => $row->status,
            'current' => $current && $row->isCompleted(),
            'model' => $row->model,
            'error_message' => $row->error_message,
            'analysis' => $row->isCompleted() ? $row->analysis : null,
            'created_at' => optional($row->created_at)?->toIso8601String(),
            'updated_at' => optional($row->updated_at)?->toIso8601String(),
        ];
    }
}
