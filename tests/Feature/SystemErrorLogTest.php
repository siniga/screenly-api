<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Services\SystemErrorLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SystemErrorLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_api_key_is_logged_and_hidden_from_the_user(): void
    {
        config(['services.gemini.api_key' => '']);

        $user = User::factory()->create();
        $project = $this->projectFor($user);

        Sanctum::actingAs($user);

        $this->postJson("/api/projects/{$project->id}/generate-screenplay")
            ->assertStatus(503)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', SystemErrorLogger::GENERATION)
            ->assertJsonMissing([
                'message' => 'Gemini API key is not configured.',
            ]);

        $this->assertDatabaseHas('system_error_logs', [
            'user_id' => $user->id,
            'project_id' => $project->id,
            'source' => 'generation',
            'message' => 'Gemini API key is not configured.',
            'user_message' => SystemErrorLogger::GENERATION,
            'status_code' => 503,
        ]);
    }

    public function test_user_message_mapper_hides_system_details(): void
    {
        $logger = app(SystemErrorLogger::class);

        $this->assertSame(
            SystemErrorLogger::GENERATION,
            $logger->userMessageFromString('Gemini API key is not configured.')
        );
        $this->assertSame(
            SystemErrorLogger::BUSY,
            $logger->userMessageFromString('Gemini API quota is exhausted. Check billing in Google AI Studio, then try again.')
        );
        $this->assertSame(
            SystemErrorLogger::SAFETY,
            $logger->userMessageFromString('Gemini blocked this image for safety. Retry uses a milder storyboard framing.')
        );

        $actionable = 'Your story is too long for a single screenplay. We need to divide it into 3 episodes.';
        $this->assertSame($actionable, $logger->userMessageFromString($actionable));
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
