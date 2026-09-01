<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProjectShareTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_and_read_a_share_link(): void
    {
        $user = User::factory()->create();
        $project = $this->projectFor($user);
        Sanctum::actingAs($user);

        $this->getJson("/api/projects/{$project->id}/share")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('enabled', false)
            ->assertJsonPath('share_token', null);

        $created = $this->postJson("/api/projects/{$project->id}/share")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('enabled', true);

        $token = $created->json('share_token');
        $this->assertNotEmpty($token);
        $this->assertSame('/s/'.$token, $created->json('share_path'));

        $again = $this->postJson("/api/projects/{$project->id}/share")
            ->assertOk()
            ->assertJsonPath('share_token', $token);

        $this->assertSame($token, $again->json('share_token'));
    }

    public function test_guest_can_view_a_shared_storyboard_without_signing_in(): void
    {
        $user = User::factory()->create();
        $project = $this->projectFor($user, [
            'title' => 'Harbor Night',
            'story' => 'A secret letter arrives at the lighthouse.',
            'screenplay' => 'INT. LIGHTHOUSE - NIGHT',
            'share_token' => 'public-share-token-harbor-night-123456',
        ]);

        $scene = $project->scenes()->create([
            'scene_number' => 1,
            'order_index' => 0,
            'title' => 'Lamp room',
            'location' => 'Lighthouse',
            'description' => 'The lamp is lit against the storm.',
            'status' => 'completed',
        ]);

        $shot = $project->shots()->create([
            'scene_id' => $scene->id,
            'shot_number' => '1',
            'order_index' => 0,
            'title' => 'Wide of the lamp',
            'description' => 'Extreme wide of the lighthouse cutting through dusk fog.',
            'action' => 'Camera holds as the lamp ignites.',
            'dialogue' => 'You still keep the light.',
            'prompt' => 'secret generation prompt',
            'image_status' => 'completed',
        ]);

        $shot->images()->create([
            'version_number' => 1,
            'image_url' => '/storage/shots/lamp.jpg',
            'prompt' => 'do not leak this prompt',
            'is_approved' => true,
            'status' => 'completed',
        ]);

        $response = $this->getJson('/api/public/storyboards/public-share-token-harbor-night-123456');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('storyboard.title', 'Harbor Night')
            ->assertJsonPath('storyboard.scenes.0.title', 'Lamp room')
            ->assertJsonPath('storyboard.shots.0.title', 'Wide of the lamp')
            ->assertJsonPath('storyboard.shots.0.description', 'Extreme wide of the lighthouse cutting through dusk fog.')
            ->assertJsonPath('storyboard.shots.0.action', 'Camera holds as the lamp ignites.')
            ->assertJsonPath('storyboard.shots.0.dialogue', 'You still keep the light.')
            ->assertJsonPath('storyboard.shots.0.image_url', '/storage/shots/lamp.jpg')
            ->assertJsonMissingPath('storyboard.story')
            ->assertJsonMissingPath('storyboard.screenplay')
            ->assertJsonMissingPath('storyboard.shots.0.prompt');

        $this->assertStringNotContainsString('secret generation prompt', $response->getContent());
        $this->assertStringNotContainsString('do not leak this prompt', $response->getContent());
        $this->assertStringNotContainsString('A secret letter arrives', $response->getContent());
    }

    public function test_unknown_or_revoked_share_token_is_not_found(): void
    {
        $this->getJson('/api/public/storyboards/does-not-exist')
            ->assertNotFound();

        $user = User::factory()->create();
        $project = $this->projectFor($user, [
            'share_token' => 'revoke-me-token-abcdefghijklmnopqrstuvwxyz',
        ]);

        $this->getJson('/api/public/storyboards/revoke-me-token-abcdefghijklmnopqrstuvwxyz')
            ->assertOk();

        Sanctum::actingAs($user);
        $this->deleteJson("/api/projects/{$project->id}/share")
            ->assertOk()
            ->assertJsonPath('enabled', false)
            ->assertJsonPath('share_token', null);

        $this->getJson('/api/public/storyboards/revoke-me-token-abcdefghijklmnopqrstuvwxyz')
            ->assertNotFound();
    }

    public function test_another_user_cannot_manage_share_for_a_project(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $project = $this->projectFor($owner);

        Sanctum::actingAs($other);

        $this->postJson("/api/projects/{$project->id}/share")->assertNotFound();
        $this->getJson("/api/projects/{$project->id}/share")->assertNotFound();
        $this->deleteJson("/api/projects/{$project->id}/share")->assertNotFound();
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
