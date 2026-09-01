<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Scene;
use App\Models\Shot;
use App\Models\ShotImage;
use Illuminate\Http\JsonResponse;

class PublicStoryboardController extends Controller
{
    public function show(string $token): JsonResponse
    {
        $project = Project::query()
            ->where('share_token', $token)
            ->with([
                'scenes' => fn ($query) => $query->orderBy('order_index'),
                'shots' => fn ($query) => $query->orderBy('order_index'),
                'shots.scene',
                'shots.images',
            ])
            ->first();

        abort_unless($project, 404, 'This shared storyboard is no longer available.');

        return response()->json([
            'success' => true,
            'storyboard' => [
                'title' => $project->title,
                'cover_image_url' => $project->cover_image_url,
                'scenes' => $project->scenes
                    ->map(fn (Scene $scene) => $this->serializeScene($scene))
                    ->values()
                    ->all(),
                'shots' => $project->shots
                    ->map(fn (Shot $shot) => $this->serializeShot($shot))
                    ->values()
                    ->all(),
            ],
        ]);
    }

    private function serializeScene(Scene $scene): array
    {
        return [
            'id' => $scene->id,
            'scene_number' => $scene->scene_number,
            'order_index' => $scene->order_index,
            'title' => $scene->title,
            'location' => $scene->location,
            'time_of_day' => $scene->time_of_day,
            'description' => $scene->description,
            'mood' => $scene->mood,
        ];
    }

    private function serializeShot(Shot $shot): array
    {
        $images = $shot->relationLoaded('images')
            ? $shot->images
            : $shot->images()->get();
        $latest = $images
            ->filter(fn (ShotImage $image) => $image->status === 'completed' && filled($image->image_url))
            ->sortByDesc('version_number')
            ->first();

        return [
            'id' => $shot->id,
            'scene_id' => $shot->scene_id,
            'scene_number' => $shot->scene?->scene_number,
            'shot_number' => $shot->shot_number,
            'order_index' => $shot->order_index,
            'title' => $shot->title,
            'description' => $shot->description,
            'action' => $shot->action,
            'dialogue' => $shot->dialogue,
            'shot_size' => $shot->shot_size,
            'camera_angle' => $shot->camera_angle,
            'camera_movement' => $shot->camera_movement,
            'lighting' => $shot->lighting,
            'mood' => $shot->mood,
            'image_url' => $latest?->image_url,
            'image_status' => filled($latest?->image_url) ? 'completed' : $shot->image_status,
            'shot_images' => $images
                ->map(fn (ShotImage $image) => [
                    'id' => $image->id,
                    'version_number' => $image->version_number,
                    'image_url' => $image->image_url,
                    'thumbnail_url' => $image->thumbnail_url,
                    'is_approved' => $image->is_approved,
                    'status' => $image->status,
                ])
                ->values()
                ->all(),
        ];
    }
}
