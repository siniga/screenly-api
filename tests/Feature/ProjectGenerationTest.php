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

    public function test_generate_shots_syncs_only_characters_in_the_shot(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse(json_encode([
                'shots' => [
                    [
                        'scene_number' => 1,
                        'title' => 'Chloe waits',
                        'description' => 'Chloe sits at the table.',
                        'action' => 'Chloe looks at the door.',
                        'shot_size' => 'MEDIUM',
                        'camera_angle' => 'EYE LEVEL',
                        'camera_movement' => 'STATIC',
                        'characters_in_shot' => ['Chloe'],
                        'extras' => 'none',
                    ],
                ],
            ], JSON_THROW_ON_ERROR))),
        ]);

        $user = User::factory()->create();
        $project = $this->projectFor($user, [
            'screenplay' => "FADE IN\n\nINT. KITCHEN - NIGHT\n\nCHLOE waits. MARCO is outside.",
            'current_step' => 'environments',
        ]);
        $project->scenes()->create([
            'scene_number' => 1,
            'order_index' => 0,
            'title' => 'Kitchen wait',
            'location' => 'KITCHEN',
            'status' => 'completed',
        ]);
        $chloe = $project->characters()->create([
            'order_index' => 0,
            'name' => 'Chloe',
            'image_status' => 'none',
        ]);
        $marco = $project->characters()->create([
            'order_index' => 1,
            'name' => 'Marco',
            'image_status' => 'none',
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/projects/{$project->id}/generate-shots")
            ->assertOk()
            ->assertJsonPath('shots.0.title', 'Chloe waits');

        Http::assertSent(function ($request) {
            $text = (string) data_get($request->data(), 'contents.0.parts.0.text', '');
            $this->assertStringContainsString('characters_in_shot', $text);
            $this->assertStringContainsString('Chloe', $text);
            $this->assertStringContainsString('Marco', $text);
            $this->assertStringContainsString('No Dutch tilt', $text);

            return true;
        });

        $shot = $project->shots()->first();
        $this->assertNotNull($shot);
        $this->assertDatabaseHas('shot_character', [
            'shot_id' => $shot->id,
            'character_id' => $chloe->id,
        ]);
        $this->assertDatabaseMissing('shot_character', [
            'shot_id' => $shot->id,
            'character_id' => $marco->id,
        ]);
        $this->assertSame(['Chloe'], $shot->storyboard_settings['characters_in_shot'] ?? null);
        $this->assertSame('none', $shot->storyboard_settings['extras'] ?? null);
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

    public function test_update_character_saves_portrait_fields(): void
    {
        $user = User::factory()->create();
        $project = $this->projectFor($user);
        $character = $project->characters()->create([
            'order_index' => 0,
            'name' => 'The Waiter',
            'role' => 'supporting',
            'gender' => 'male',
            'age_range' => '30s',
            'ethnicity' => 'European',
            'appearance' => 'Tired man in a grey shirt',
            'wardrobe' => 'Grey shirt',
            'description' => 'Works the night shift.',
            'image_status' => 'completed',
        ]);

        Sanctum::actingAs($user);

        $this->patchJson("/api/projects/{$project->id}/characters/{$character->id}", [
            'role' => 'protagonist',
            'gender' => 'Male',
            'age' => '50s',
            'ethnicity' => 'East African',
            'appearance' => 'Older man with a white beard',
            'wardrobe' => 'Faded military jacket',
            'description' => 'A tired lead who still shows up.',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('character.role', 'protagonist')
            ->assertJsonPath('character.gender', 'Male')
            ->assertJsonPath('character.age_range', '50s')
            ->assertJsonPath('character.age', '50s')
            ->assertJsonPath('character.ethnicity', 'East African')
            ->assertJsonPath('character.appearance', 'Older man with a white beard')
            ->assertJsonPath('character.wardrobe', 'Faded military jacket');

        $character->refresh();
        $this->assertSame('protagonist', $character->role);
        $this->assertSame('50s', $character->age_range);
        $this->assertSame('East African', $character->ethnicity);
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

    public function test_generate_character_costume_saves_a_sheet(): void
    {
        Storage::fake('public');
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->geminiImageResponse()),
        ]);

        $user = User::factory()->create();
        $project = $this->projectFor($user);
        $character = $project->characters()->create([
            'order_index' => 0,
            'name' => 'Chloe',
            'wardrobe' => 'Grey coat, cream sweater, silver pendant, black boots',
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

        Sanctum::actingAs($user);

        $this->postJson("/api/projects/{$project->id}/characters/{$character->id}/generate-costume")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('skipped', false)
            ->assertJsonPath('character.costume_image_url', "/storage/projects/{$project->id}/characters/{$character->id}-costume.png");

        $this->assertDatabaseHas('character_assets', [
            'character_id' => $character->id,
            'asset_type' => 'costume',
            'status' => 'completed',
        ]);
        $this->assertTrue(
            Storage::disk('public')->exists("projects/{$project->id}/characters/{$character->id}-costume.png")
        );

        Http::assertSent(function ($request) {
            $parts = data_get($request->data(), 'contents.0.parts', []);
            $text = collect($parts)->pluck('text')->filter()->implode("\n");
            $inline = collect($parts)
                ->map(fn ($part) => data_get($part, 'inlineData.data') ?? data_get($part, 'inline_data.data'))
                ->filter()
                ->values();

            $this->assertStringContainsString('full-body costume sheet', $text);
            $this->assertTrue($inline->contains(base64_encode('portrait-bytes')));

            return true;
        });
    }

    public function test_generate_character_costume_skips_when_a_sheet_already_exists(): void
    {
        Storage::fake('public');
        Http::fake();

        $user = User::factory()->create();
        $project = $this->projectFor($user);
        $character = $project->characters()->create([
            'order_index' => 0,
            'name' => 'Chloe',
            'image_status' => 'completed',
        ]);
        $character->assets()->create([
            'asset_type' => 'costume',
            'title' => 'Chloe costume',
            'image_url' => '/storage/projects/1/characters/1-costume.png',
            'is_primary' => false,
            'status' => 'completed',
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/projects/{$project->id}/characters/{$character->id}/generate-costume")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('skipped', true);

        Http::assertNothingSent();
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
        $costumePath = "projects/{$project->id}/characters/{$character->id}-costume.png";
        Storage::disk('public')->put($costumePath, 'costume-sheet-bytes');
        $character->assets()->create([
            'asset_type' => 'costume',
            'title' => 'Chloe costume',
            'image_url' => '/storage/'.$costumePath,
            'is_primary' => false,
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
            'dialogue' => 'Wait for me at the corner.',
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

            $this->assertStringContainsString('costume sheets', $text);
            $this->assertStringContainsString('MUST WEAR: Grey coat', $text);
            $this->assertStringContainsString('exactly 1', $text);
            $this->assertStringContainsString('ACTION IS THE ONLY SOURCE FOR STAGING', $text);
            $this->assertStringContainsString('Do not overlap bodies', $text);
            $this->assertStringContainsString('Nobody looks into the lens', $text);
            $this->assertStringContainsString('Do not change the story', $text);
            $this->assertStringContainsString('Do not copy a previous storyboard location', $text);
            $this->assertStringContainsString('no captions', $text);
            $this->assertStringContainsString('no subtitles', $text);
            $this->assertStringContainsString('Fill every pixel of the 16:9 canvas', $text);
            $this->assertStringContainsString('Dialogue is not part of this picture', $text);
            $this->assertStringNotContainsString('Dialogue:', $text);
            $this->assertStringNotContainsString('Wait for me at the corner.', $text);
            $this->assertTrue($inline->contains(base64_encode('costume-sheet-bytes')));
            $this->assertTrue($inline->contains(base64_encode('environment-plate-bytes')));
            $this->assertFalse($inline->contains(base64_encode('portrait-bytes')));
            $this->assertFalse($inline->contains(base64_encode('previous-still-bytes')));

            return true;
        });
    }

    public function test_generate_shot_image_does_not_attach_offscreen_characters(): void
    {
        Storage::fake('public');
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->geminiImageResponse()),
        ]);

        $user = User::factory()->create();
        $project = $this->projectFor($user, ['current_step' => 'storyboard']);
        $chloe = $project->characters()->create([
            'order_index' => 0,
            'name' => 'Chloe',
            'wardrobe' => 'Grey coat',
            'image_status' => 'completed',
        ]);
        $marco = $project->characters()->create([
            'order_index' => 1,
            'name' => 'Marco',
            'wardrobe' => 'Blue jacket',
            'image_status' => 'completed',
        ]);

        $chloeCostume = "projects/{$project->id}/characters/{$chloe->id}-costume.png";
        Storage::disk('public')->put($chloeCostume, 'chloe-costume-bytes');
        $chloe->assets()->create([
            'asset_type' => 'costume',
            'title' => 'Chloe costume',
            'image_url' => '/storage/'.$chloeCostume,
            'is_primary' => false,
            'status' => 'completed',
        ]);

        $marcoCostume = "projects/{$project->id}/characters/{$marco->id}-costume.png";
        Storage::disk('public')->put($marcoCostume, 'marco-costume-bytes');
        $marco->assets()->create([
            'asset_type' => 'costume',
            'title' => 'Marco costume',
            'image_url' => '/storage/'.$marcoCostume,
            'is_primary' => false,
            'status' => 'completed',
        ]);

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
            'title' => 'Chloe walks outside',
            'action' => 'Chloe steps onto the street.',
            'image_status' => 'none',
            'storyboard_settings' => [
                'characters_in_shot' => ['Chloe'],
                'extras' => 'none',
            ],
        ]);
        $shot->characters()->attach($chloe->id);

        Sanctum::actingAs($user);

        $this->postJson("/api/projects/{$project->id}/shots/{$shot->id}/generate-image")
            ->assertOk()
            ->assertJsonPath('success', true);

        Http::assertSent(function ($request) {
            $parts = data_get($request->data(), 'contents.0.parts', []);
            $text = collect($parts)->pluck('text')->filter()->implode("\n");
            $inline = collect($parts)
                ->map(fn ($part) => data_get($part, 'inlineData.data') ?? data_get($part, 'inline_data.data'))
                ->filter()
                ->values();

            $this->assertStringContainsString('exactly 1 — Chloe', $text);
            $this->assertStringContainsString('Place them only as Action describes', $text);
            $this->assertStringContainsString('Do not include these project characters at all: Marco', $text);
            $this->assertStringContainsString('MUST WEAR: Grey coat', $text);
            $this->assertStringNotContainsString('MUST WEAR: Blue jacket', $text);
            $this->assertTrue($inline->contains(base64_encode('chloe-costume-bytes')));
            $this->assertFalse($inline->contains(base64_encode('marco-costume-bytes')));

            return true;
        });
    }

    public function test_generate_character_image_locks_cartoon_style_and_attaches_reference(): void
    {
        Storage::fake('public');
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->geminiImageResponse()),
        ]);

        $user = User::factory()->create();
        $project = $this->projectFor($user, [
            'style' => 'Cartoon · 2D · Comic book',
            'style_prompt' => 'Strict visual lock: American comic-book illustration.',
            'style_meta' => [
                'family' => 'cartoon',
                'medium' => '2d',
                'variant' => 'comic_book',
            ],
        ]);
        $stylePath = "projects/{$project->id}/style/reference.png";
        Storage::disk('public')->put($stylePath, 'style-ref-bytes');
        $project->style_reference_url = '/storage/'.$stylePath;
        $project->save();

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
            ->assertJsonPath('skipped', false);

        Http::assertSent(function ($request) {
            $parts = data_get($request->data(), 'contents.0.parts', []);
            $text = collect($parts)->pluck('text')->filter()->implode("\n");
            $inline = collect($parts)
                ->map(fn ($part) => data_get($part, 'inlineData.data') ?? data_get($part, 'inline_data.data'))
                ->filter()
                ->values();

            $this->assertStringContainsString('locked cartoon style', $text);
            $this->assertStringContainsString('American comic-book illustration', $text);
            $this->assertStringContainsString('STYLE LOCK', $text);
            $this->assertTrue($inline->contains(base64_encode('style-ref-bytes')));

            return true;
        });
    }

    public function test_generate_cover_saves_the_image_on_the_project(): void
    {
        Storage::fake('public');
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->geminiImageResponse()),
        ]);

        $user = User::factory()->create();
        $project = $this->projectFor($user, [
            'title' => 'Harbor Night',
            'story' => 'A man waits in a quiet kitchen until someone knocks on the door.',
            'screenplay' => "FADE IN\n\nINT. KITCHEN - NIGHT\n\nA MAN waits.",
        ]);
        $project->scenes()->create([
            'scene_number' => 1,
            'order_index' => 0,
            'title' => 'Kitchen wait',
            'location' => 'Kitchen',
            'time_of_day' => 'Night',
            'status' => 'completed',
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/projects/{$project->id}/generate-cover")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('skipped', false)
            ->assertJsonPath('generated', true)
            ->assertJsonPath('cover_image_url', "/storage/projects/{$project->id}/cover/poster.png")
            ->assertJsonPath('project.cover_image_url', "/storage/projects/{$project->id}/cover/poster.png");

        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'cover_image_url' => "/storage/projects/{$project->id}/cover/poster.png",
        ]);
        $this->assertTrue(
            Storage::disk('public')->exists("projects/{$project->id}/cover/poster.png")
        );

        Http::assertSent(function ($request) {
            $parts = data_get($request->data(), 'contents.0.parts', []);
            $text = collect($parts)->pluck('text')->filter()->implode("\n");

            $this->assertStringContainsString('key-art still', $text);
            $this->assertStringContainsString('Harbor Night', $text);
            $this->assertStringContainsString('Kitchen wait', $text);
            $this->assertStringContainsString('A man waits in a quiet kitchen', $text);
            $this->assertStringContainsString('no captions', $text);
            $this->assertStringContainsString('no subtitles', $text);
            $this->assertStringContainsString('Fill every pixel of the 16:9 canvas', $text);
            $this->assertStringContainsString('do not paint this as text', $text);

            return true;
        });
    }

    public function test_generate_cover_skips_when_a_cover_already_exists(): void
    {
        Storage::fake('public');
        Http::fake();

        $user = User::factory()->create();
        $project = $this->projectFor($user, [
            'cover_image_url' => '/storage/projects/1/cover/poster.png',
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/projects/{$project->id}/generate-cover")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('skipped', true)
            ->assertJsonPath('generated', false)
            ->assertJsonPath('cover_image_url', '/storage/projects/1/cover/poster.png');

        Http::assertNothingSent();
    }

    public function test_generate_cover_locks_cartoon_style_and_attaches_reference(): void
    {
        Storage::fake('public');
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->geminiImageResponse()),
        ]);

        $user = User::factory()->create();
        $project = $this->projectFor($user, [
            'style' => 'Cartoon · 2D · Comic book',
            'style_prompt' => 'Strict visual lock: American comic-book illustration.',
            'style_meta' => [
                'family' => 'cartoon',
                'medium' => '2d',
                'variant' => 'comic_book',
            ],
        ]);
        $stylePath = "projects/{$project->id}/style/reference.png";
        Storage::disk('public')->put($stylePath, 'style-ref-bytes');
        $project->style_reference_url = '/storage/'.$stylePath;
        $project->save();

        Sanctum::actingAs($user);

        $this->postJson("/api/projects/{$project->id}/generate-cover")
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

            $this->assertStringContainsString('locked cartoon style', $text);
            $this->assertStringContainsString('American comic-book illustration', $text);
            $this->assertStringContainsString('STYLE LOCK', $text);
            $this->assertTrue($inline->contains(base64_encode('style-ref-bytes')));

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
