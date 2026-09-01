<?php

namespace App\Http\Controllers;

use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ProjectShareController extends Controller
{
    public function show(Request $request, Project $project): JsonResponse
    {
        $this->authorizeProject($request, $project);

        return response()->json($this->sharePayload($project));
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $this->authorizeProject($request, $project);

        if (! filled($project->share_token)) {
            $project->share_token = $this->freshShareToken();
            $project->save();
        }

        return response()->json($this->sharePayload($project));
    }

    public function destroy(Request $request, Project $project): JsonResponse
    {
        $this->authorizeProject($request, $project);

        $project->share_token = null;
        $project->save();

        return response()->json($this->sharePayload($project));
    }

    private function authorizeProject(Request $request, Project $project): void
    {
        abort_unless($project->user_id === $request->user()->id, 404);
    }

    private function freshShareToken(): string
    {
        do {
            $token = Str::lower(Str::random(40));
        } while (Project::query()->where('share_token', $token)->exists());

        return $token;
    }

    private function sharePayload(Project $project): array
    {
        $token = $project->share_token;

        return [
            'success' => true,
            'enabled' => filled($token),
            'share_token' => $token,
            'share_path' => filled($token) ? '/s/'.$token : null,
        ];
    }
}
