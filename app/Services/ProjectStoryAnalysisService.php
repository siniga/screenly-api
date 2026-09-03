<?php

namespace App\Services;

use App\Exceptions\GenerationFailedException;
use App\Models\Project;
use App\Models\ProjectStoryAnalysis;
use Throwable;

class ProjectStoryAnalysisService
{
    public function __construct(private ProjectTextGenerator $generator) {}

    public static function hash(?string $story): string
    {
        return hash('sha256', (string) $story);
    }

    public function currentFor(Project $project): ?ProjectStoryAnalysis
    {
        $story = (string) ($project->story ?? '');
        if (trim($story) === '') {
            return null;
        }

        return $this->completedForHash($project, self::hash($story));
    }

    public function latestFor(Project $project): ?ProjectStoryAnalysis
    {
        return $project->storyAnalyses()->latest('id')->first();
    }

    public function analyze(Project $project, bool $force = false): ProjectStoryAnalysis
    {
        $story = (string) ($project->story ?? '');
        if (trim($story) === '') {
            throw new GenerationFailedException('Story is required before analysis.', 422);
        }

        $hash = self::hash($story);
        $existing = $this->completedForHash($project, $hash);
        if ($existing && ! $force) {
            return $existing;
        }

        $version = ((int) $project->storyAnalyses()->max('story_version')) + 1;

        $row = $project->storyAnalyses()->create([
            'story_hash' => $hash,
            'story_version' => $version,
            'status' => ProjectStoryAnalysis::STATUS_PROCESSING,
            'analysis' => null,
            'model' => (string) config('services.gemini.text_model', 'gemini-2.5-flash'),
            'error_message' => null,
        ]);

        try {
            $analysis = $this->generator->analyzeStory($story);
            $row->analysis = $analysis;
            $row->status = ProjectStoryAnalysis::STATUS_COMPLETED;
            $row->error_message = null;
            $row->save();

            return $row->fresh() ?? $row;
        } catch (GenerationFailedException $exception) {
            $this->markFailed($row, $exception);
            throw $exception;
        } catch (Throwable $exception) {
            $this->markFailed($row, $exception);
            throw new GenerationFailedException(
                'Story analysis failed.',
                502,
            );
        }
    }

    private function completedForHash(Project $project, string $hash): ?ProjectStoryAnalysis
    {
        return $project->storyAnalyses()
            ->where('story_hash', $hash)
            ->where('status', ProjectStoryAnalysis::STATUS_COMPLETED)
            ->latest('id')
            ->first();
    }

    private function markFailed(ProjectStoryAnalysis $row, Throwable $exception): void
    {
        $row->status = ProjectStoryAnalysis::STATUS_FAILED;
        $row->analysis = null;
        $row->error_message = app(SystemErrorLogger::class)->userMessage($exception);
        $row->save();
    }
}
