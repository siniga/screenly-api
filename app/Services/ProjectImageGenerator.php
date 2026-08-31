<?php

namespace App\Services;

use App\Exceptions\GenerationFailedException;
use App\Models\Character;
use App\Models\CharacterAsset;
use App\Models\Environment;
use App\Models\EnvironmentAsset;
use App\Models\Project;
use App\Models\Shot;
use App\Models\ShotImage;
use Illuminate\Support\Collection;

class ProjectImageGenerator
{
    public function __construct(
        private readonly GeminiClient $gemini,
        private readonly ProjectImageStore $store,
    ) {}

    /**
     * @return array{skipped: bool, character: Character, asset: CharacterAsset|null}
     */
    public function generateCharacterPortrait(Project $project, Character $character, bool $force = false): array
    {
        $existing = $this->primaryAsset($character);
        if ($existing && filled($existing->image_url) && ! $force) {
            return [
                'skipped' => true,
                'character' => $character,
                'asset' => $existing,
            ];
        }

        $prompt = $this->characterPrompt($character, $project->style);

        try {
            $image = $this->gemini->generateImage($prompt, [], '3:4');
        } catch (GenerationFailedException $e) {
            $character->image_status = 'failed';
            $character->save();
            throw $e;
        }

        $url = $this->store->put(
            $project->id,
            'characters',
            (string) $character->id,
            $image['binary'],
            $image['mime'],
        );

        $asset = $existing ?? $character->assets()->make([
            'asset_type' => 'portrait',
            'title' => $character->name,
            'is_primary' => true,
        ]);
        $asset->image_url = $url;
        $asset->status = 'completed';
        $asset->is_primary = true;
        $asset->save();

        $character->image_status = 'completed';
        $character->save();
        $character->setRelation('assets', $character->assets()->get());

        return [
            'skipped' => false,
            'character' => $character,
            'asset' => $asset,
        ];
    }

    /**
     * @return array{skipped: bool, environment: Environment, asset: EnvironmentAsset|null}
     */
    public function generateEnvironmentStill(Project $project, Environment $environment, bool $force = false): array
    {
        $environment->loadMissing('assets');
        $existing = $this->primaryEnvironmentAsset($environment);
        if ($existing && filled($existing->image_url) && ! $force) {
            return [
                'skipped' => true,
                'environment' => $environment,
                'asset' => $existing,
            ];
        }

        $prompt = $this->environmentPrompt($environment, $project->style);

        try {
            $image = $this->gemini->generateImage($prompt, [], '16:9');
        } catch (GenerationFailedException $e) {
            $environment->image_status = 'failed';
            $environment->save();
            throw $e;
        }

        $url = $this->store->put(
            $project->id,
            'environments',
            (string) $environment->id,
            $image['binary'],
            $image['mime'],
        );

        $asset = $existing ?? $environment->assets()->make([
            'asset_type' => 'location',
            'title' => $environment->name,
            'is_primary' => true,
        ]);
        $asset->image_url = $url;
        $asset->status = 'completed';
        $asset->is_primary = true;
        $asset->save();

        $environment->image_status = 'completed';
        $environment->prompt = $prompt;
        $environment->save();
        $environment->setRelation('assets', $environment->assets()->get());

        return [
            'skipped' => false,
            'environment' => $environment,
            'asset' => $asset,
        ];
    }

    /**
     * @return array{skipped: bool, shot: Shot, image: ShotImage|null}
     */
    public function generateShotStill(
        Project $project,
        Shot $shot,
        bool $force = false,
        ?string $customPrompt = null,
    ): array {
        $shot->loadMissing(['scene.environment.assets', 'environment.assets', 'images']);
        $existing = $this->latestShotImage($shot);
        $adjustment = is_string($customPrompt) ? trim($customPrompt) : '';
        $shouldForce = $force || $adjustment !== '';

        if ($existing && filled($existing->image_url) && ! $shouldForce) {
            return [
                'skipped' => true,
                'shot' => $shot,
                'image' => $existing,
            ];
        }

        $characters = $project->characters()->with('assets')->orderBy('order_index')->get();
        $prompt = $this->shotPrompt($shot, $characters, $project->style);
        $previous = $this->previousCompletedShot($shot);
        $previousImage = $previous ? $this->latestShotImage($previous) : null;
        $previousPrompt = filled($previousImage?->prompt)
            ? (string) $previousImage->prompt
            : (string) ($previous?->prompt ?? '');

        if ($previousPrompt !== '') {
            $prompt = $this->continuityPreamble($previousPrompt)."\n\n".$prompt;
        }

        $references = $this->shotReferences($project, $shot, $characters, $previousImage);

        if ($adjustment !== '') {
            $prompt .= "\n\nEdit the attached existing still. Apply this adjustment:\n".$adjustment;
            $currentStill = $this->store->readPublicUrl($existing?->image_url);
            if ($currentStill !== null) {
                array_unshift($references, $currentStill);
                $references = array_slice($references, 0, 3);
            }
            $shot->prompt = $adjustment;
        }

        try {
            $image = $this->tryShotImage($prompt, $references);
        } catch (GenerationFailedException $e) {
            $shot->image_status = 'failed';
            $shot->generation_error = app(SystemErrorLogger::class)->userMessage($e);
            $shot->save();
            throw $e;
        }

        $version = ((int) $shot->images()->max('version_number')) + 1;
        $url = $this->store->put(
            $project->id,
            'shots',
            $shot->id.'-v'.$version,
            $image['binary'],
            $image['mime'],
        );

        $row = $shot->images()->create([
            'version_number' => $version,
            'image_url' => $url,
            'thumbnail_url' => $url,
            'prompt' => $prompt,
            'status' => 'completed',
        ]);

        $shot->image_status = 'completed';
        $shot->generation_error = null;
        $shot->save();
        $shot->setRelation('images', $shot->images()->get());
        $shot->loadMissing('scene');

        return [
            'skipped' => false,
            'shot' => $shot,
            'image' => $row,
        ];
    }

    private function primaryAsset(Character $character): ?CharacterAsset
    {
        $assets = $character->relationLoaded('assets')
            ? $character->assets
            : $character->assets()->get();

        return $assets->first(
            fn (CharacterAsset $asset) => $asset->is_primary && filled($asset->image_url)
        ) ?? $assets->first(fn (CharacterAsset $asset) => filled($asset->image_url));
    }

    private function primaryEnvironmentAsset(?Environment $environment): ?EnvironmentAsset
    {
        if (! $environment) {
            return null;
        }

        $assets = $environment->relationLoaded('assets')
            ? $environment->assets
            : $environment->assets()->get();

        return $assets->first(
            fn (EnvironmentAsset $asset) => $asset->is_primary && filled($asset->image_url)
        ) ?? $assets->first(fn (EnvironmentAsset $asset) => filled($asset->image_url));
    }

    private function previousCompletedShot(Shot $shot): ?Shot
    {
        if ($shot->scene_id == null) {
            return null;
        }

        $previous = Shot::query()
            ->where('project_id', $shot->project_id)
            ->where('scene_id', $shot->scene_id)
            ->where('id', '!=', $shot->id)
            ->where(function ($query) use ($shot) {
                $query->where('order_index', '<', (int) $shot->order_index)
                    ->orWhere(function ($sameOrder) use ($shot) {
                        $sameOrder->where('order_index', (int) $shot->order_index)
                            ->where('id', '<', $shot->id);
                    });
            })
            ->orderByDesc('order_index')
            ->orderByDesc('id')
            ->with('images')
            ->get()
            ->first(fn (Shot $candidate) => $this->latestShotImage($candidate) !== null);

        return $previous;
    }

    private function continuityPreamble(string $previousPrompt): string
    {
        return implode("\n", [
            'CONTINUITY — previous still in this scene. Keep the same people, wardrobe, and location unless this shot clearly changes them.',
            'Previous prompt:',
            $previousPrompt,
            'Attached images may include: the previous still, a location plate, and character portraits.',
        ]);
    }

    /**
     * @param  Collection<int, Character>  $characters
     * @return list<array{binary: string, mime: string}>
     */
    private function shotReferences(
        Project $project,
        Shot $shot,
        Collection $characters,
        ?ShotImage $previousImage,
    ): array {
        $references = [];

        $previousFile = $this->store->readPublicUrl($previousImage?->image_url);
        if ($previousFile !== null) {
            $references[] = $previousFile;
        }

        $environment = $this->resolveEnvironment($shot, $project);
        $environmentFile = $this->store->readPublicUrl($this->primaryEnvironmentAsset($environment)?->image_url);
        if ($environmentFile !== null) {
            $references[] = $environmentFile;
        }

        $remaining = 3 - count($references);
        if ($remaining > 0) {
            $references = array_merge(
                $references,
                $this->characterReferences($characters, $shot, $remaining),
            );
        }

        return array_slice($references, 0, 3);
    }

    private function resolveEnvironment(Shot $shot, Project $project): ?Environment
    {
        $shot->loadMissing(['environment.assets', 'scene.environment.assets']);

        if ($shot->environment) {
            return $shot->environment;
        }

        if ($shot->scene?->environment) {
            return $shot->scene->environment;
        }

        $place = strtolower(trim((string) ($shot->scene?->location ?? '')));
        if ($place === '') {
            return null;
        }

        return $project->environments()->with('assets')->get()->first(function (Environment $environment) use ($place) {
            $name = strtolower(trim((string) $environment->name));

            return $name !== '' && (str_contains($name, $place) || str_contains($place, $name));
        });
    }

    private function latestShotImage(Shot $shot): ?ShotImage
    {
        $images = $shot->relationLoaded('images')
            ? $shot->images
            : $shot->images()->get();

        return $images
            ->filter(fn (ShotImage $image) => $image->status === 'completed' && filled($image->image_url))
            ->sortByDesc('version_number')
            ->first();
    }

    /**
     * @param  list<array{binary: string, mime: string}>  $references
     * @return array{binary: string, mime: string}
     */
    private function tryShotImage(string $prompt, array $references): array
    {
        try {
            return $this->gemini->generateImage($prompt, $references, '16:9');
        } catch (GenerationFailedException $e) {
            if ($references === [] || ! $this->shouldRetryWithoutReferences($e)) {
                throw $e;
            }
        }

        return $this->gemini->generateImage($prompt, [], '16:9');
    }

    private function shouldRetryWithoutReferences(GenerationFailedException $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'did not return an image')
            || str_contains($message, 'safety')
            || str_contains($message, 'blocked');
    }

    /**
     * @param  Collection<int, Character>  $characters
     * @return list<array{binary: string, mime: string}>
     */
    private function characterReferences(Collection $characters, Shot $shot, int $limit = 3): array
    {
        $haystack = strtolower(implode(' ', array_filter([
            $shot->title,
            $shot->action,
            $shot->description,
            $shot->dialogue,
        ])));

        $ranked = $characters->sortBy(function (Character $character) use ($haystack) {
            $name = strtolower((string) $character->name);
            if ($haystack === '' || $name === '') {
                return 1;
            }

            return str_contains($haystack, $name) ? 0 : 1;
        })->values();

        $references = [];

        foreach ($ranked as $character) {
            if (count($references) >= $limit) {
                break;
            }

            $asset = $this->primaryAsset($character);
            $file = $this->store->readPublicUrl($asset?->image_url);
            if ($file !== null) {
                $references[] = $file;
            }
        }

        return $references;
    }

    private function environmentPrompt(Environment $environment, ?string $style): string
    {
        $lines = [
            'Create a single cinematic location still for a fictional film.',
            'Empty of people. Show the space, architecture, and lighting only.',
            'No text, no watermark, no collage, no split frames.',
            '',
            'Location: '.$environment->name,
        ];

        foreach ([
            'Type' => $environment->type,
            'Time of day' => $environment->time_of_day,
            'Appearance' => $environment->appearance,
            'Lighting' => $environment->lighting,
            'Mood' => $environment->mood,
            'Description' => $environment->description,
        ] as $label => $value) {
            if (filled($value)) {
                $lines[] = $label.': '.$value;
            }
        }

        if (filled($style)) {
            $lines[] = 'Visual style: '.$style;
        }

        return implode("\n", $lines);
    }

    private function characterPrompt(Character $character, ?string $style): string
    {
        $lines = [
            'Create a single photorealistic character portrait for a film.',
            'One person only, facing camera, chest-up, plain studio backdrop.',
            'No text, no watermark, no collage, no split frames.',
            '',
            'Name: '.$character->name,
        ];

        foreach ([
            'Role' => $character->role,
            'Gender' => $character->gender,
            'Age' => $character->age_range,
            'Ethnicity' => $character->ethnicity,
            'Appearance' => $character->appearance,
            'Wardrobe' => $character->wardrobe,
            'Description' => $character->description,
        ] as $label => $value) {
            if (filled($value)) {
                $lines[] = $label.': '.$value;
            }
        }

        if (filled($style)) {
            $lines[] = 'Visual style: '.$style;
        }

        return implode("\n", $lines);
    }

    /**
     * @param  Collection<int, Character>  $characters
     */
    private function shotPrompt(Shot $shot, Collection $characters, ?string $style): string
    {
        $lines = [
            'Create a single cinematic storyboard still for a fictional film. One frame only.',
            'Keep it PG-13: implied action, no graphic injury, no blood, no sexual content.',
            'No text, no watermark, no split screen, no collage.',
            '',
            'Shot: '.$this->softenForImage($shot->title ?: 'Untitled shot'),
        ];

        foreach ([
            'Action' => $this->softenForImage((string) ($shot->action ?: $shot->description)),
            'Dialogue' => $shot->dialogue,
            'Shot size' => $shot->shot_size,
            'Camera angle' => $shot->camera_angle,
            'Camera movement' => $shot->camera_movement,
            'Lighting' => $shot->lighting,
            'Mood' => $shot->mood,
            'Location' => $shot->scene?->location ?: $shot->environment?->name,
            'Time of day' => $shot->scene?->time_of_day ?: $shot->environment?->time_of_day,
        ] as $label => $value) {
            if (filled($value)) {
                $lines[] = $label.': '.$value;
            }
        }

        $cast = $characters
            ->filter(fn (Character $character) => filled($character->name))
            ->map(function (Character $character) {
                $parts = array_filter([
                    $character->name,
                    $character->appearance,
                    $character->wardrobe,
                ]);

                return '- '.implode('. ', $parts);
            })
            ->values();

        if ($cast->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'Keep these characters looking consistent with the attached portraits:';
            $lines = array_merge($lines, $cast->all());
        }

        if (filled($style)) {
            $lines[] = 'Visual style: '.$style;
        }

        $lines[] = '';
        $lines[] = 'If a previous still or location plate is attached, match those faces, wardrobe, and architecture.';

        return implode("\n", $lines);
    }

    private function softenForImage(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return $text;
        }

        $replacements = [
            '/\bdragging\b/i' => 'supporting',
            '/\bdragged\b/i' => 'carried',
            '/\bunresponsive\b/i' => 'collapsed, exhausted',
            '/\bunconscious\b/i' => 'asleep, slumped',
            '/\bstrangl(?:e|ing|ed)\b/i' => 'confronting',
            '/\bcorpses?\b/i' => 'fallen figure',
            '/\bdead body\b/i' => 'still figure',
            '/\bblood(?:y|ied)?\b/i' => 'shadowed',
            '/\bkill(?:ed|ing)?\b/i' => 'overcoming',
            '/\bmurder(?:ed|ing)?\b/i' => 'confronting',
        ];

        return trim((string) preg_replace(array_keys($replacements), array_values($replacements), $text));
    }
}
