<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProjectIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_list_prefers_the_stored_cover_over_scene_shots(): void
    {
        $user = User::factory()->create();
        $project = $this->projectFor($user, [
            'title' => 'Harbor Night',
            'cover_image_url' => '/storage/covers/generated-cover.jpg',
        ]);

        $scene = $project->scenes()->create([
            'scene_number' => 1,
            'order_index' => 0,
            'title' => 'Lamp room',
            'status' => 'completed',
        ]);

        $shot = $project->shots()->create([
            'scene_id' => $scene->id,
            'shot_number' => '1',
            'order_index' => 0,
            'title' => 'Wide of the lamp',
            'image_status' => 'completed',
        ]);

        $shot->images()->create([
            'version_number' => 1,
            'image_url' => '/storage/shots/lamp.jpg',
            'is_approved' => true,
            'status' => 'completed',
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/projects')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('projects.0.cover_image_url', '/storage/covers/generated-cover.jpg');
    }

    public function test_project_list_falls_back_to_a_scene_shot_when_no_cover_is_stored(): void
    {
        $user = User::factory()->create();
        $project = $this->projectFor($user, [
            'title' => 'Harbor Night',
            'cover_image_url' => null,
        ]);

        $scene = $project->scenes()->create([
            'scene_number' => 1,
            'order_index' => 0,
            'title' => 'Lamp room',
            'status' => 'completed',
        ]);

        $shot = $project->shots()->create([
            'scene_id' => $scene->id,
            'shot_number' => '1',
            'order_index' => 0,
            'title' => 'Wide of the lamp',
            'image_status' => 'completed',
        ]);

        $shot->images()->create([
            'version_number' => 1,
            'image_url' => '/storage/shots/lamp.jpg',
            'is_approved' => true,
            'status' => 'completed',
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/projects')
            ->assertOk()
            ->assertJsonPath('projects.0.cover_image_url', '/storage/shots/lamp.jpg');
    }

    public function test_project_list_falls_back_to_stored_cover_when_scenes_have_no_images(): void
    {
        $user = User::factory()->create();
        $this->projectFor($user, [
            'cover_image_url' => '/storage/covers/generated-cover.jpg',
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/projects')
            ->assertOk()
            ->assertJsonPath('projects.0.cover_image_url', '/storage/covers/generated-cover.jpg');
    }

    public function test_store_project_copies_cartoon_style_preview(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/projects', [
            'title' => 'Harbor Lights',
            'style' => 'Cartoon · 2D · Comic book',
            'style_prompt' => 'Strict visual lock: American comic-book illustration.',
            'style_meta' => [
                'family' => 'cartoon',
                'medium' => '2d',
                'variant' => 'comic_book',
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('project.style', 'Cartoon · 2D · Comic book')
            ->assertJsonPath('project.style_meta.variant', 'comic_book')
            ->assertJsonPath('project.style_prompt', 'Strict visual lock: American comic-book illustration.');

        $this->assertNotEmpty($response->json('project.style_reference_url'));
        $this->assertTrue(
            Storage::disk('public')->exists('projects/'.$response->json('project.id').'/style/reference.png')
        );
    }

    public function test_upload_replaces_project_style_reference(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $project = $this->projectFor($user, [
            'style' => 'Cartoon · 2D · Custom',
            'style_prompt' => 'Strict visual lock: 2D cartoon matching the attached style-reference image.',
            'style_meta' => [
                'family' => 'cartoon',
                'medium' => '2d',
                'variant' => 'custom',
            ],
        ]);

        Sanctum::actingAs($user);

        $file = UploadedFile::fake()->image('my-style.png', 64, 48);

        $this->post("/api/projects/{$project->id}/style-reference", [
            'image' => $file,
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('success', true);

        $project->refresh();
        $this->assertNotEmpty($project->style_reference_url);
        $this->assertTrue(
            Storage::disk('public')->exists("projects/{$project->id}/style/reference.png")
        );
    }

    private function projectFor(User $user, array $attributes = []): Project
    {
        return $user->projects()->create(array_merge([
            'title' => 'Test project',
            'style' => 'Cinematic Realistic',
            'story' => 'A man waits in a quiet kitchen until someone knocks on the door.',
            'current_step' => 'storyboard',
            'status' => 'draft',
        ], $attributes));
    }
}
