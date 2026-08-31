<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Services\SystemErrorLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProjectGenerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_generate_script_saves_gemini_text_on_the_project(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse("INT. KITCHEN - NIGHT\n\nA man waits.")),
        ]);

        $user = User::factory()->create();
        $project = $this->projectFor($user, [
            'story' => 'A man waits in a quiet kitchen until someone knocks on the door.',
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/projects/{$project->id}/generate-script", [
            'style' => 'Cinematic Realistic',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('script', "INT. KITCHEN - NIGHT\n\nA man waits.")
            ->assertJsonPath('project.current_step', 'script');

        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'script' => "INT. KITCHEN - NIGHT\n\nA man waits.",
            'current_step' => 'script',
        ]);
    }

    public function test_generate_script_returns_quota_error_from_gemini(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'error' => ['message' => 'Resource exhausted'],
            ], 429),
        ]);

        $user = User::factory()->create();
        $project = $this->projectFor($user);

        Sanctum::actingAs($user);

        $this->postJson("/api/projects/{$project->id}/generate-script")
            ->assertStatus(429)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', SystemErrorLogger::BUSY)
            ->assertJsonMissing([
                'message' => 'Gemini API quota is exhausted. Check billing in Google AI Studio, then try again.',
            ]);

        $this->assertDatabaseHas('system_error_logs', [
            'project_id' => $project->id,
            'message' => 'Gemini API quota is exhausted. Check billing in Google AI Studio, then try again.',
            'user_message' => SystemErrorLogger::BUSY,
        ]);
    }

    public function test_generate_screenplay_requires_a_story(): void
    {
        $user = User::factory()->create();
        $project = $this->projectFor($user, [
            'story' => 'Too short',
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/projects/{$project->id}/generate-screenplay")
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_generate_screenplay_saves_gemini_text_on_the_project(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse("FADE IN\n\nINT. KITCHEN - NIGHT\n\nA MAN waits.")),
        ]);

        $user = User::factory()->create();
        $project = $this->projectFor($user);

        Sanctum::actingAs($user);

        $this->postJson("/api/projects/{$project->id}/generate-screenplay")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('project.current_step', 'screenplay');

        $project->refresh();
        $this->assertStringContainsString('FADE IN', (string) $project->screenplay);
        $this->assertSame('screenplay', $project->current_step);
    }

    public function test_generate_screenplay_rejects_a_story_that_is_too_long(): void
    {
        $user = User::factory()->create();
        $project = $this->projectFor($user, [
            'story' => implode(' ', array_fill(0, 2001, 'word')),
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/projects/{$project->id}/generate-screenplay")
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'story_too_long');
    }

    public function test_plan_episodes_saves_gemini_rows(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse(json_encode([
                'episodes' => [
                    ['episode_number' => 1, 'title' => 'The Wait', 'summary' => 'A man waits.'],
                    ['episode_number' => 2, 'title' => 'The Knock', 'summary' => 'Someone knocks.'],
                ],
            ], JSON_THROW_ON_ERROR))),
        ]);

        $user = User::factory()->create();
        $project = $this->projectFor($user, [
            'story' => implode(' ', array_fill(0, 2001, 'word')),
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/projects/{$project->id}/plan-episodes")
            ->assertOk()
            ->assertJsonPath('episodes.0.title', 'The Wait')
            ->assertJsonPath('episodes.1.title', 'The Knock');

        $this->assertDatabaseCount('episodes', 2);
    }

    public function test_generate_scenes_requires_a_screenplay(): void
    {
        $user = User::factory()->create();
        $project = $this->projectFor($user, [
            'screenplay' => 'Too short',
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/projects/{$project->id}/generate-scenes")
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_generate_scenes_saves_gemini_rows_on_the_project(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse(json_encode([
                'scenes' => [
                    [
                        'title' => 'Kitchen wait',
                        'location' => 'KITCHEN',
                        'time_of_day' => 'NIGHT',
                        'description' => 'A man waits until someone knocks.',
                        'mood' => 'Tense',
                    ],
                    [
                        'title' => 'The door',
                        'location' => 'HALLWAY',
                        'time_of_day' => 'NIGHT',
                        'description' => 'He opens the door.',
                        'mood' => 'Uneasy',
                    ],
                ],
            ], JSON_THROW_ON_ERROR))),
        ]);

        $user = User::factory()->create();
        $project = $this->projectFor($user, [
            'screenplay' => "FADE IN\n\nINT. KITCHEN - NIGHT\n\nA MAN waits. A knock. He opens the door.",
            'current_step' => 'screenplay',
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/projects/{$project->id}/generate-scenes");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('project.current_step', 'sceneboard')
            ->assertJsonPath('scenes.0.title', 'Kitchen wait')
            ->assertJsonPath('scenes.1.location', 'HALLWAY');

        $this->assertDatabaseHas('scenes', [
            'project_id' => $project->id,
            'scene_number' => 1,
            'title' => 'Kitchen wait',
        ]);
        $this->assertDatabaseCount('scenes', 2);
    }

    public function test_generate_scenes_skips_gemini_when_scenes_already_exist(): void
    {
        Http::fake();

        $user = User::factory()->create();
        $project = $this->projectFor($user, [
            'screenplay' => "FADE IN\n\nINT. KITCHEN - NIGHT\n\nA MAN waits until someone knocks on the door.",
            'current_step' => 'screenplay',
        ]);
        $project->scenes()->create([
            'scene_number' => 1,
            'order_index' => 0,
            'title' => 'Existing scene',
            'status' => 'completed',
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/projects/{$project->id}/generate-scenes")
            ->assertOk()
            ->assertJsonPath('scenes.0.title', 'Existing scene');

        Http::assertNothingSent();
        $this->assertDatabaseCount('scenes', 1);
    }

    public function test_generate_characters_requires_a_screenplay(): void
    {
        $user = User::factory()->create();
        $project = $this->projectFor($user, [
            'screenplay' => 'Too short',
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/projects/{$project->id}/generate-characters")
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_generate_characters_saves_gemini_rows_on_the_project(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse(json_encode([
                'characters' => [
                    [
                        'name' => 'THE MAN',
                        'role' => 'protagonist',
                        'gender' => 'male',
                        'age_range' => '40s',
                        'description' => 'A man waiting in a kitchen.',
                        'personality' => 'Tense',
                        'appearance' => 'Tired, in shirtsleeves',
                        'wardrobe' => 'White shirt',
                        'importance' => 'lead',
                    ],
                    [
                        'name' => 'THE KNOCKER',
                        'role' => 'supporting',
                        'gender' => 'unknown',
                        'age_range' => 'adult',
                        'description' => 'Whoever is at the door.',
                        'personality' => 'Unseen',
                        'importance' => 'supporting',
                    ],
                ],
            ], JSON_THROW_ON_ERROR))),
        ]);

        $user = User::factory()->create();
        $project = $this->projectFor($user, [
            'screenplay' => "FADE IN\n\nINT. KITCHEN - NIGHT\n\nA MAN waits. A knock. He opens the door.",
            'current_step' => 'sceneboard',
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/projects/{$project->id}/generate-characters");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('project.current_step', 'characters')
            ->assertJsonPath('characters.0.name', 'THE MAN')
            ->assertJsonPath('characters.1.role', 'supporting');

        $this->assertDatabaseHas('characters', [
            'project_id' => $project->id,
            'name' => 'THE MAN',
            'importance' => 'lead',
        ]);
        $this->assertDatabaseCount('characters', 2);
    }

    public function test_generate_characters_skips_gemini_when_characters_already_exist(): void
    {
        Http::fake();

        $user = User::factory()->create();
        $project = $this->projectFor($user, [
            'screenplay' => "FADE IN\n\nINT. KITCHEN - NIGHT\n\nA MAN waits until someone knocks on the door.",
            'current_step' => 'sceneboard',
        ]);
        $project->characters()->create([
            'order_index' => 0,
            'name' => 'Existing character',
            'status' => 'suggested',
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/projects/{$project->id}/generate-characters")
            ->assertOk()
            ->assertJsonPath('characters.0.name', 'Existing character');

        Http::assertNothingSent();
        $this->assertDatabaseCount('characters', 1);
    }

    public function test_generate_environments_saves_gemini_rows_on_the_project(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse(json_encode([
                'environments' => [
                    [
                        'name' => 'Kitchen',
                        'type' => 'interior',
                        'time_of_day' => 'NIGHT',
                        'description' => 'A quiet kitchen.',
                        'mood' => 'Tense',
                        'importance' => 'primary',
                    ],
                ],
            ], JSON_THROW_ON_ERROR))),
        ]);

        $user = User::factory()->create();
        $project = $this->projectFor($user, [
            'screenplay' => "FADE IN\n\nINT. KITCHEN - NIGHT\n\nA MAN waits.",
            'current_step' => 'characters',
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/projects/{$project->id}/generate-environments")
            ->assertOk()
            ->assertJsonPath('environments.0.name', 'Kitchen')
            ->assertJsonPath('project.current_step', 'environments');

        $this->assertDatabaseHas('environments', [
            'project_id' => $project->id,
            'name' => 'Kitchen',
        ]);
    }

    public function test_generate_shots_requires_sequences(): void
    {
        $user = User::factory()->create();
        $project = $this->projectFor($user, [
            'screenplay' => "FADE IN\n\nINT. KITCHEN - NIGHT\n\nA MAN waits.",
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/projects/{$project->id}/generate-shots")
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_generate_shots_saves_gemini_rows_on_the_project(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse(json_encode([
                'shots' => [
                    [
                        'scene_number' => 1,
                        'title' => 'Man waits',
                        'description' => 'A man sits at the table.',
                        'action' => 'He looks at the door.',
                        'shot_size' => 'MEDIUM',
                        'camera_angle' => 'EYE LEVEL',
                        'camera_movement' => 'STATIC',
                    ],
                ],
            ], JSON_THROW_ON_ERROR))),
        ]);

        $user = User::factory()->create();
        $project = $this->projectFor($user, [
            'screenplay' => "FADE IN\n\nINT. KITCHEN - NIGHT\n\nA MAN waits.",
            'current_step' => 'environments',
        ]);
        $project->scenes()->create([
            'scene_number' => 1,
            'order_index' => 0,
            'title' => 'Kitchen wait',
            'location' => 'KITCHEN',
            'status' => 'completed',
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/projects/{$project->id}/generate-shots")
            ->assertOk()
            ->assertJsonPath('shots.0.title', 'Man waits')
            ->assertJsonPath('project.current_step', 'storyboard');

        $this->assertDatabaseHas('shots', [
            'project_id' => $project->id,
            'title' => 'Man waits',
        ]);
    }

    public function test_generate_character_image_saves_a_portrait(): void
    {
        Storage::fake('public');
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->geminiImageResponse()),
        ]);

        $user = User::factory()->create();
        $project = $this->projectFor($user);
        $character = $project->characters()->create([
            'order_index' => 0,
            'name' => 'The Waiter',
            'appearance' => 'Tired man in a grey shirt',
            'image_status' => 'pending',
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/projects/{$project->id}/characters/{$character->id}/generate-image")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('skipped', false)
            ->assertJsonPath('character.image_status', 'completed');

        $character->refresh();
        $this->assertSame('completed', $character->image_status);
        $this->assertDatabaseHas('character_assets', [
            'character_id' => $character->id,
            'asset_type' => 'portrait',
            'status' => 'completed',
        ]);
        $this->assertTrue(
            Storage::disk('public')->exists("projects/{$project->id}/characters/{$character->id}.png")
        );
    }

    public function test_generate_character_image_skips_when_a_portrait_already_exists(): void
    {
        Storage::fake('public');
        Http::fake();

        $user = User::factory()->create();
        $project = $this->projectFor($user);
        $character = $project->characters()->create([
            'order_index' => 0,
            'name' => 'The Waiter',
            'image_status' => 'completed',
        ]);
        $character->assets()->create([
            'asset_type' => 'portrait',
            'title' => 'The Waiter',
            'image_url' => '/storage/projects/1/characters/1.png',
            'is_primary' => true,
            'status' => 'completed',
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/projects/{$project->id}/characters/{$character->id}/generate-image")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('skipped', true);

        Http::assertNothingSent();
    }

    public function test_generate_shot_image_saves_a_still(): void
    {
        Storage::fake('public');
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->geminiImageResponse()),
        ]);

        $user = User::factory()->create();
        $project = $this->projectFor($user, ['current_step' => 'storyboard']);
        $scene = $project->scenes()->create([
            'scene_number' => 1,
            'order_index' => 0,
            'title' => 'Kitchen wait',
            'location' => 'KITCHEN',
            'status' => 'completed',
        ]);
        $shot = $project->shots()->create([
            'scene_id' => $scene->id,
            'shot_number' => '1',
            'order_index' => 0,
            'title' => 'Man waits',
            'action' => 'He looks at the door.',
            'image_status' => 'none',
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/projects/{$project->id}/shots/{$shot->id}/generate-image")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('skipped', false)
            ->assertJsonPath('shot.image_status', 'completed');

        $shot->refresh();
        $this->assertSame('completed', $shot->image_status);
        $this->assertDatabaseHas('shot_images', [
            'shot_id' => $shot->id,
            'status' => 'completed',
            'version_number' => 1,
        ]);
    }

    public function test_generate_shot_image_retries_without_references_when_gemini_returns_text_only(): void
    {
        Storage::fake('public');
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push($this->geminiResponse('I cannot generate that image.'))
                ->push($this->geminiImageResponse()),
        ]);

        $user = User::factory()->create();
        $project = $this->projectFor($user, ['current_step' => 'storyboard']);
        $character = $project->characters()->create([
            'order_index' => 0,
            'name' => 'Chloe',
            'image_status' => 'completed',
        ]);
        $portraitPath = "projects/{$project->id}/characters/{$character->id}.png";
        Storage::disk('public')->put($portraitPath, 'portrait-bytes');
        $character->assets()->create([
            'asset_type' => 'portrait',
            'title' => 'Chloe',
            'image_url' => '/storage/'.$portraitPath,
            'is_primary' => true,
            'status' => 'completed',
        ]);
        $scene = $project->scenes()->create([
            'scene_number' => 5,
            'order_index' => 4,
            'title' => 'Chloe is Unresponsive',
            'status' => 'completed',
        ]);
        $shot = $project->shots()->create([
            'scene_id' => $scene->id,
            'shot_number' => '1',
            'order_index' => 0,
            'title' => 'Dragging Chloe',
            'action' => 'Someone is dragging Chloe.',
            'image_status' => 'none',
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/projects/{$project->id}/shots/{$shot->id}/generate-image")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('shot.image_status', 'completed');

        Http::assertSentCount(2);
    }

    public function test_generate_shot_image_skips_when_an_image_already_exists(): void
    {
        Storage::fake('public');
        Http::fake();

        $user = User::factory()->create();
        $project = $this->projectFor($user, ['current_step' => 'storyboard']);
        $scene = $project->scenes()->create([
            'scene_number' => 1,
            'order_index' => 0,
            'title' => 'Kitchen wait',
            'status' => 'completed',
        ]);
        $shot = $project->shots()->create([
            'scene_id' => $scene->id,
            'shot_number' => '1',
            'order_index' => 0,
            'title' => 'Man waits',
            'image_status' => 'completed',
        ]);
        $shot->images()->create([
            'version_number' => 1,
            'image_url' => '/storage/projects/1/shots/1-v1.png',
            'status' => 'completed',
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/projects/{$project->id}/shots/{$shot->id}/generate-image")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('skipped', true);

        Http::assertNothingSent();
    }

    public function test_generate_shot_image_regenerates_with_a_custom_prompt(): void
    {
        Storage::fake('public');
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->geminiImageResponse()),
        ]);

        $user = User::factory()->create();
        $project = $this->projectFor($user, ['current_step' => 'storyboard']);
        $scene = $project->scenes()->create([
            'scene_number' => 1,
            'order_index' => 0,
            'title' => 'Kitchen wait',
            'status' => 'completed',
        ]);
        $shot = $project->shots()->create([
            'scene_id' => $scene->id,
            'shot_number' => '1',
            'order_index' => 0,
            'title' => 'Man waits',
            'image_status' => 'completed',
        ]);
        $existingPath = "projects/{$project->id}/shots/{$shot->id}-v1.png";
        Storage::disk('public')->put($existingPath, 'existing-bytes');
        $shot->images()->create([
            'version_number' => 1,
            'image_url' => '/storage/'.$existingPath,
            'status' => 'completed',
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/projects/{$project->id}/shots/{$shot->id}/generate-image", [
            'custom_prompt' => 'Make the kitchen warmer and add morning light.',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('skipped', false)
            ->assertJsonPath('shot.image_status', 'completed')
            ->assertJsonPath('shot.prompt', 'Make the kitchen warmer and add morning light.');

        $this->assertDatabaseHas('shots', [
            'id' => $shot->id,
            'prompt' => 'Make the kitchen warmer and add morning light.',
        ]);
        $this->assertDatabaseHas('shot_images', [
            'shot_id' => $shot->id,
            'version_number' => 2,
            'status' => 'completed',
        ]);
        Http::assertSentCount(1);
    }

    public function test_generate_environment_image_saves_a_plate(): void
    {
        Storage::fake('public');
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->geminiImageResponse()),
        ]);

        $user = User::factory()->create();
        $project = $this->projectFor($user, ['current_step' => 'environments']);
        $environment = $project->environments()->create([
            'order_index' => 0,
            'name' => 'Kitchen',
            'type' => 'interior',
            'time_of_day' => 'NIGHT',
            'description' => 'A quiet kitchen.',
            'image_status' => 'pending',
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/projects/{$project->id}/environments/{$environment->id}/generate-image")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('skipped', false)
            ->assertJsonPath('environment.image_status', 'completed')
            ->assertJsonPath('environment.name', 'Kitchen');

        $environment->refresh();
        $this->assertSame('completed', $environment->image_status);
        $this->assertDatabaseHas('environment_assets', [
            'environment_id' => $environment->id,
            'asset_type' => 'plate',
            'status' => 'completed',
        ]);
        $this->assertTrue(
            Storage::disk('public')->exists("projects/{$project->id}/environments/{$environment->id}.png")
        );
    }

    public function test_generate_shot_image_uses_identity_only_portraits_not_previous_stills(): void
    {
        Storage::fake('public');
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->geminiImageResponse()),
        ]);

        $user = User::factory()->create();
        $project = $this->projectFor($user, ['current_step' => 'storyboard']);
        $character = $project->characters()->create([
            'order_index' => 0,
            'name' => 'Chloe',
            'appearance' => 'Dark hair and a silver necklace',
            'wardrobe' => 'Grey coat',
            'image_status' => 'completed',
        ]);
        $portraitPath = "projects/{$project->id}/characters/{$character->id}.png";
        Storage::disk('public')->put($portraitPath, 'portrait-bytes');
        $character->assets()->create([
            'asset_type' => 'portrait',
            'title' => 'Chloe',
            'image_url' => '/storage/'.$portraitPath,
            'is_primary' => true,
            'status' => 'completed',
        ]);

        $environment = $project->environments()->create([
            'order_index' => 0,
            'name' => 'Kitchen',
            'image_status' => 'completed',
        ]);
        $platePath = "projects/{$project->id}/environments/{$environment->id}.png";
        Storage::disk('public')->put($platePath, 'environment-plate-bytes');
        $environment->assets()->create([
            'asset_type' => 'plate',
            'title' => 'Kitchen',
            'image_url' => '/storage/'.$platePath,
            'is_primary' => true,
            'status' => 'completed',
        ]);

        $scene = $project->scenes()->create([
            'scene_number' => 1,
            'order_index' => 0,
            'title' => 'Kitchen wait',
            'location' => 'KITCHEN',
            'status' => 'completed',
        ]);
        $previousShot = $project->shots()->create([
            'scene_id' => $scene->id,
            'shot_number' => '1',
            'order_index' => 0,
            'title' => 'Man waits',
            'action' => 'He looks at the door.',
            'image_status' => 'completed',
        ]);
        $previousPath = "projects/{$project->id}/shots/{$previousShot->id}-v1.png";
        Storage::disk('public')->put($previousPath, 'previous-still-bytes');
        $previousShot->images()->create([
            'version_number' => 1,
            'image_url' => '/storage/'.$previousPath,
            'status' => 'completed',
        ]);

        $shot = $project->shots()->create([
            'scene_id' => $scene->id,
            'environment_id' => $environment->id,
            'shot_number' => '2',
            'order_index' => 1,
            'title' => 'Chloe walks outside',
            'action' => 'Chloe steps onto the street.',
            'image_status' => 'none',
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/projects/{$project->id}/shots/{$shot->id}/generate-image")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('skipped', false);

        Http::assertSent(function ($request) {
            $parts = data_get($request->data(), 'contents.0.parts', []);
            $text = collect($parts)->pluck('text')->filter()->implode("\n");
            $inline = collect($parts)
                ->map(fn ($part) => data_get($part, 'inlineData.data') ?? data_get($part, 'inline_data.data'))
                ->filter()
                ->values();

            $this->assertStringContainsString('identity references only', $text);
            $this->assertStringContainsString('Do not copy a previous storyboard location', $text);
            $this->assertStringNotContainsString('previous still', strtolower($text));
            $this->assertStringNotContainsString('location plate', strtolower($text));
            $this->assertTrue($inline->contains(base64_encode('portrait-bytes')));
            $this->assertFalse($inline->contains(base64_encode('previous-still-bytes')));
            $this->assertFalse($inline->contains(base64_encode('environment-plate-bytes')));

            return true;
        });
    }

    private function projectFor(User $user, array $attributes = []): Project
    {
        return $user->projects()->create(array_merge([
            'title' => 'Test project',
            'style' => 'Cinematic Realistic',
            'story' => 'A man waits in a quiet kitchen until someone knocks on the door.',
            'current_step' => 'story',
            'status' => 'draft',
        ], $attributes));
    }

    private function geminiResponse(string $text): array
    {
        return [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            ['text' => $text],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function geminiImageResponse(): array
    {
        return [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            [
                                'inlineData' => [
                                    'mimeType' => 'image/png',
                                    'data' => base64_encode('fake-png-bytes'),
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
