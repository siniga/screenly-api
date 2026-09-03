<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectStoryAnalysis;
use App\Models\User;
use App\Services\ProjectStoryAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProjectStoryAnalysisTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_analyses_a_linear_story(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse($this->linearAnalysis())),
        ]);

        $user = User::factory()->create();
        $project = $this->projectFor($user, [
            'story' => "A woman waits at the station.\n\nA train arrives. She boards it.",
        ]);
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/projects/{$project->id}/analyze-story");

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('reused', false)
            ->assertJsonPath('current', true)
            ->assertJsonPath('story_analysis.status', 'completed')
            ->assertJsonPath('story_analysis.current', true)
            ->assertJsonPath('story_analysis.analysis.language', 'English')
            ->assertJsonPath('story_analysis.analysis.beats.0.timeline_id', 'timeline_present')
            ->assertJsonPath('story_analysis.analysis.beats.1.type', 'action')
            ->assertJsonPath('story_analysis.analysis.source_blocks.0.source_id', 'source_001')
            ->assertJsonPath('story_analysis.analysis.source_blocks.1.source_id', 'source_002');

        $this->assertDatabaseHas('project_story_analyses', [
            'project_id' => $project->id,
            'status' => 'completed',
            'story_hash' => ProjectStoryAnalysisService::hash($project->story),
        ]);

        Http::assertSent(function ($request) {
            $text = (string) data_get($request->data(), 'contents.0.parts.0.text');
            $this->assertStringContainsString('strict narrative continuity analyst', $text);
            $this->assertStringContainsString('source_001', $text);
            $this->assertStringNotContainsString('Cinematic Realistic', $text);
            $this->assertStringNotContainsString('Visual style', $text);
            $this->assertStringNotContainsString('FADE IN', $text);

            return true;
        });
    }

    public function test_it_analyses_a_story_containing_flashbacks(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse($this->flashbackAnalysis())),
        ]);

        $user = User::factory()->create();
        $project = $this->projectFor($user, [
            'story' => "Mira stands in the kitchen and washes a cup.\n\nShe remembers the winter she left home.\n\nShe dries the cup and turns off the light.",
        ]);
        Sanctum::actingAs($user);

        $this->postJson("/api/projects/{$project->id}/analyze-story")
            ->assertCreated()
            ->assertJsonPath('story_analysis.analysis.timelines.1.type', 'flashback')
            ->assertJsonPath('story_analysis.analysis.beats.1.timeline_id', 'timeline_memory')
            ->assertJsonPath('story_analysis.analysis.flashbacks.0.text', 'Mira remembers the winter she left home.');
    }

    public function test_it_keeps_missing_age_and_appearance_as_null(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse($this->linearAnalysis())),
        ]);

        $user = User::factory()->create();
        $project = $this->projectFor($user, [
            'story' => "A woman waits at the station.\n\nA train arrives. She boards it.",
        ]);
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/projects/{$project->id}/analyze-story")
            ->assertCreated()
            ->assertJsonPath('story_analysis.analysis.characters.0.age', null)
            ->assertJsonPath('story_analysis.analysis.characters.0.age_range', null)
            ->assertJsonPath('story_analysis.analysis.characters.0.unknown_details.0', 'appearance');

        $this->assertSame([], $response->json('story_analysis.analysis.characters.0.confirmed_facts'));
    }

    public function test_it_rejects_gemini_output_that_cites_unknown_source_ids(): void
    {
        $payload = $this->linearAnalysis();
        $payload['characters'][0]['source_ids'] = ['source_999'];

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse($payload)),
        ]);

        $user = User::factory()->create();
        $project = $this->projectFor($user, [
            'story' => "A woman waits at the station.\n\nA train arrives. She boards it.",
        ]);
        Sanctum::actingAs($user);

        $this->postJson("/api/projects/{$project->id}/analyze-story")
            ->assertStatus(502)
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas('project_story_analyses', [
            'project_id' => $project->id,
            'status' => 'failed',
        ]);
        $this->assertDatabaseMissing('project_story_analyses', [
            'project_id' => $project->id,
            'status' => 'completed',
        ]);
    }

    public function test_it_reuses_a_completed_analysis_for_the_same_story_hash(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse($this->linearAnalysis())),
        ]);

        $user = User::factory()->create();
        $story = "A woman waits at the station.\n\nA train arrives. She boards it.";
        $project = $this->projectFor($user, ['story' => $story]);
        Sanctum::actingAs($user);

        $first = $this->postJson("/api/projects/{$project->id}/analyze-story")
            ->assertCreated()
            ->assertJsonPath('reused', false);

        $second = $this->postJson("/api/projects/{$project->id}/analyze-story")
            ->assertOk()
            ->assertJsonPath('reused', true)
            ->assertJsonPath('story_analysis.id', $first->json('story_analysis.id'));

        $this->assertSame(
            ProjectStoryAnalysisService::hash($story),
            $second->json('story_analysis.story_hash'),
        );
        Http::assertSentCount(1);
        $this->assertSame(1, ProjectStoryAnalysis::query()->count());
    }

    public function test_a_story_change_makes_the_previous_analysis_non_current(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse($this->linearAnalysis())),
        ]);

        $user = User::factory()->create();
        $project = $this->projectFor($user, [
            'story' => "A woman waits at the station.\n\nA train arrives. She boards it.",
        ]);
        Sanctum::actingAs($user);

        $this->postJson("/api/projects/{$project->id}/analyze-story")->assertCreated();

        $project->story = 'A different story now begins at dawn.';
        $project->save();

        $response = $this->getJson("/api/projects/{$project->id}/story-analysis")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('current', false)
            ->assertJsonPath('story_analysis.current', false)
            ->assertJsonPath(
                'story_hash',
                ProjectStoryAnalysisService::hash('A different story now begins at dawn.'),
            );

        $this->assertNotSame(
            $response->json('story_hash'),
            $response->json('story_analysis.story_hash'),
        );
    }

    public function test_it_rejects_malformed_gemini_json(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse('not-json')),
        ]);

        $user = User::factory()->create();
        $project = $this->projectFor($user, [
            'story' => "A woman waits at the station.\n\nA train arrives. She boards it.",
        ]);
        Sanctum::actingAs($user);

        $this->postJson("/api/projects/{$project->id}/analyze-story")
            ->assertStatus(502)
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas('project_story_analyses', [
            'project_id' => $project->id,
            'status' => 'failed',
        ]);
        $this->assertNull(ProjectStoryAnalysis::query()->first()?->analysis);
    }

    public function test_guests_cannot_analyse_a_story(): void
    {
        $user = User::factory()->create();
        $project = $this->projectFor($user);

        $this->postJson("/api/projects/{$project->id}/analyze-story")
            ->assertUnauthorized();
        $this->getJson("/api/projects/{$project->id}/story-analysis")
            ->assertUnauthorized();
    }

    public function test_another_user_cannot_access_story_analysis(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $project = $this->projectFor($owner);
        Sanctum::actingAs($other);

        $this->postJson("/api/projects/{$project->id}/analyze-story")
            ->assertNotFound();
        $this->getJson("/api/projects/{$project->id}/story-analysis")
            ->assertNotFound();
    }

    public function test_it_rejects_an_empty_story(): void
    {
        Http::fake();

        $user = User::factory()->create();
        $project = $this->projectFor($user, ['story' => '   ']);
        Sanctum::actingAs($user);

        $this->postJson("/api/projects/{$project->id}/analyze-story")
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Story is required before analysis.');

        $this->assertDatabaseCount('project_story_analyses', 0);
        Http::assertNothingSent();
    }

    public function test_clock_time_phrase_is_not_stored_as_a_character_age(): void
    {
        $story = 'At eleven, they brought in the white dress.';
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse($this->clockTimeAnalysis())),
        ]);

        $user = User::factory()->create();
        $project = $this->projectFor($user, ['story' => $story]);
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/projects/{$project->id}/analyze-story")
            ->assertCreated()
            ->assertJsonPath('story_analysis.analysis.time_expressions.0.text', 'At eleven')
            ->assertJsonPath('story_analysis.analysis.time_expressions.0.meaning_type', 'clock_time')
            ->assertJsonPath('story_analysis.analysis.characters.0.age', null)
            ->assertJsonPath('story_analysis.analysis.characters.0.age_range', null);

        $this->assertSame(
            $story,
            $response->json('story_analysis.analysis.source_blocks.0.text'),
        );
    }

    public function test_force_regenerates_an_analysis_for_the_same_story(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse($this->linearAnalysis())),
        ]);

        $user = User::factory()->create();
        $project = $this->projectFor($user, [
            'story' => "A woman waits at the station.\n\nA train arrives. She boards it.",
        ]);
        Sanctum::actingAs($user);

        $firstId = $this->postJson("/api/projects/{$project->id}/analyze-story")
            ->assertCreated()
            ->json('story_analysis.id');

        $second = $this->postJson("/api/projects/{$project->id}/analyze-story", ['force' => true])
            ->assertCreated()
            ->assertJsonPath('reused', false);

        $this->assertNotSame($firstId, $second->json('story_analysis.id'));
        $this->assertSame(2, ProjectStoryAnalysis::query()->count());
        Http::assertSentCount(2);
    }

    /**
     * @return array<string, mixed>
     */
    private function linearAnalysis(): array
    {
        return [
            'language' => 'English',
            'story_type' => 'short_story',
            'characters' => [[
                'character_id' => 'character_001',
                'name' => 'A woman',
                'aliases' => [],
                'gender' => 'female',
                'age' => null,
                'age_range' => null,
                'relationships' => [],
                'confirmed_facts' => [],
                'unknown_details' => ['appearance'],
                'source_ids' => ['source_001', 'source_002'],
            ]],
            'locations' => [[
                'location_id' => 'location_station',
                'name' => 'the station',
                'type' => 'exterior',
                'confirmed_details' => [],
                'source_ids' => ['source_001'],
            ]],
            'time_expressions' => [],
            'timelines' => [[
                'timeline_id' => 'timeline_present',
                'type' => 'present',
                'description' => 'Current narrative timeline.',
                'source_ids' => ['source_001'],
            ]],
            'beats' => [
                [
                    'beat_id' => 'beat_001',
                    'order' => 1,
                    'timeline_id' => 'timeline_present',
                    'type' => 'action',
                    'summary' => 'A woman waits at the station.',
                    'characters' => ['character_001'],
                    'location_id' => 'location_station',
                    'importance' => 'critical',
                    'must_appear' => true,
                    'source_ids' => ['source_001'],
                ],
                [
                    'beat_id' => 'beat_002',
                    'order' => 2,
                    'timeline_id' => 'timeline_present',
                    'type' => 'action',
                    'summary' => 'A train arrives and she boards it.',
                    'characters' => ['character_001'],
                    'location_id' => 'location_station',
                    'importance' => 'critical',
                    'must_appear' => true,
                    'source_ids' => ['source_002'],
                ],
            ],
            'dialogue' => [],
            'internal_narration' => [],
            'flashbacks' => [],
            'delayed_reveals' => [],
            'motifs' => [],
            'must_preserve_elements' => [[
                'element_id' => 'element_001',
                'text' => 'She boards the arriving train.',
                'source_ids' => ['source_002'],
            ]],
            'unknown_details' => [],
            'ambiguities' => [],
            'contradictions' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function flashbackAnalysis(): array
    {
        return [
            'language' => 'English',
            'story_type' => 'short_story',
            'characters' => [[
                'character_id' => 'character_mira',
                'name' => 'Mira',
                'aliases' => [],
                'gender' => 'female',
                'age' => null,
                'age_range' => null,
                'relationships' => [],
                'confirmed_facts' => [
                    ['fact' => 'Mira washes a cup in the kitchen.', 'source_ids' => ['source_001']],
                ],
                'unknown_details' => ['appearance', 'age'],
                'source_ids' => ['source_001', 'source_002', 'source_003'],
            ]],
            'locations' => [[
                'location_id' => 'location_kitchen',
                'name' => 'kitchen',
                'type' => 'interior',
                'confirmed_details' => [],
                'source_ids' => ['source_001'],
            ]],
            'time_expressions' => [[
                'text' => 'the winter',
                'interpretation' => 'a past winter',
                'meaning_type' => 'relative_time',
                'confidence' => 0.8,
                'source_ids' => ['source_002'],
            ]],
            'timelines' => [
                [
                    'timeline_id' => 'timeline_present',
                    'type' => 'present',
                    'description' => 'Mira in the kitchen now.',
                    'source_ids' => ['source_001', 'source_003'],
                ],
                [
                    'timeline_id' => 'timeline_memory',
                    'type' => 'flashback',
                    'description' => 'The winter Mira left home.',
                    'source_ids' => ['source_002'],
                ],
            ],
            'beats' => [
                [
                    'beat_id' => 'beat_001',
                    'order' => 1,
                    'timeline_id' => 'timeline_present',
                    'type' => 'action',
                    'summary' => 'Mira washes a cup.',
                    'characters' => ['character_mira'],
                    'location_id' => 'location_kitchen',
                    'importance' => 'important',
                    'must_appear' => true,
                    'source_ids' => ['source_001'],
                ],
                [
                    'beat_id' => 'beat_002',
                    'order' => 2,
                    'timeline_id' => 'timeline_memory',
                    'type' => 'internal',
                    'summary' => 'She remembers the winter she left home.',
                    'characters' => ['character_mira'],
                    'location_id' => null,
                    'importance' => 'critical',
                    'must_appear' => true,
                    'source_ids' => ['source_002'],
                ],
                [
                    'beat_id' => 'beat_003',
                    'order' => 3,
                    'timeline_id' => 'timeline_present',
                    'type' => 'action',
                    'summary' => 'She dries the cup and turns off the light.',
                    'characters' => ['character_mira'],
                    'location_id' => 'location_kitchen',
                    'importance' => 'important',
                    'must_appear' => true,
                    'source_ids' => ['source_003'],
                ],
            ],
            'dialogue' => [],
            'internal_narration' => [],
            'flashbacks' => [[
                'flashback_id' => 'flashback_001',
                'text' => 'Mira remembers the winter she left home.',
                'timeline_id' => 'timeline_memory',
                'beat_id' => 'beat_002',
                'source_ids' => ['source_002'],
            ]],
            'delayed_reveals' => [],
            'motifs' => [],
            'must_preserve_elements' => [],
            'unknown_details' => [],
            'ambiguities' => [],
            'contradictions' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function clockTimeAnalysis(): array
    {
        return [
            'language' => 'English',
            'story_type' => 'short_story',
            'characters' => [[
                'character_id' => 'character_they',
                'name' => 'they',
                'aliases' => [],
                'gender' => null,
                'age' => null,
                'age_range' => null,
                'relationships' => [],
                'confirmed_facts' => [
                    ['fact' => 'They brought in the white dress.', 'source_ids' => ['source_001']],
                ],
                'unknown_details' => ['age', 'appearance'],
                'source_ids' => ['source_001'],
            ]],
            'locations' => [],
            'time_expressions' => [[
                'text' => 'At eleven',
                'interpretation' => '11:00',
                'meaning_type' => 'clock_time',
                'confidence' => 0.99,
                'source_ids' => ['source_001'],
            ]],
            'timelines' => [[
                'timeline_id' => 'timeline_present',
                'type' => 'present',
                'description' => 'Current narrative timeline.',
                'source_ids' => ['source_001'],
            ]],
            'beats' => [[
                'beat_id' => 'beat_001',
                'order' => 1,
                'timeline_id' => 'timeline_present',
                'type' => 'action',
                'summary' => 'They brought in the white dress.',
                'characters' => ['character_they'],
                'location_id' => null,
                'importance' => 'critical',
                'must_appear' => true,
                'source_ids' => ['source_001'],
            ]],
            'dialogue' => [],
            'internal_narration' => [],
            'flashbacks' => [],
            'delayed_reveals' => [],
            'motifs' => [[
                'motif_id' => 'motif_001',
                'text' => 'the white dress',
                'source_ids' => ['source_001'],
            ]],
            'must_preserve_elements' => [[
                'element_id' => 'element_001',
                'text' => 'They brought in the white dress at eleven.',
                'source_ids' => ['source_001'],
            ]],
            'unknown_details' => [],
            'ambiguities' => [],
            'contradictions' => [],
        ];
    }

    /**
     * @param  array<string, mixed>|string  $payload
     * @return array<string, mixed>
     */
    private function geminiResponse(array|string $payload): array
    {
        $text = is_string($payload) ? $payload : json_encode($payload, JSON_THROW_ON_ERROR);

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
}
