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
        $existing = $this->portraitAsset($character);
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
     * @return array{skipped: bool, character: Character, asset: CharacterAsset|null}
     */
    public function generateCharacterCostumeSheet(Project $project, Character $character, bool $force = false): array
    {
        $character->loadMissing('assets');
        $existing = $this->costumeAsset($character);
        if ($existing && filled($existing->image_url) && ! $force) {
            return [
                'skipped' => true,
                'character' => $character,
                'asset' => $existing,
            ];
        }

        $prompt = $this->costumePrompt($character, $project->style);
        $references = [];
        $portrait = $this->store->readPublicUrl($this->portraitAsset($character)?->image_url);
        if ($portrait !== null) {
            $references[] = $portrait;
        }

        $image = $this->tryImage($prompt, $references, '3:4');

        $url = $this->store->put(
            $project->id,
            'characters',
            $character->id.'-costume',
            $image['binary'],
            $image['mime'],
        );

        $asset = $existing ?? $character->assets()->make([
            'asset_type' => 'costume',
            'title' => $character->name.' costume',
            'is_primary' => false,
        ]);
        $asset->image_url = $url;
        $asset->status = 'completed';
        $asset->is_primary = false;
        $asset->save();

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
            'asset_type' => 'plate',
            'title' => $environment->name,
            'is_primary' => true,
        ]);
        $asset->image_url = $url;
        $asset->status = 'completed';
        $asset->is_primary = true;
        $asset->save();

        $environment->image_status = 'completed';
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
        $shot->loadMissing(['scene', 'environment', 'images']);
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
        $references = $this->identityReferences($characters, $shot);

        if ($adjustment !== '') {
            $prompt .= "\n\nA current still is attached as a starting point for this edit.";
            $prompt .= "\nKeep identity only: faces, hair, and the exact garments from the costume sheet.";
            $prompt .= "\nApply this adjustment:\n".$adjustment;
            $prompt .= "\nIf the adjustment changes action, pose, camera, or location, follow the adjustment.";
            $prompt .= "\nDo not keep the old room, pose, or action unless the adjustment asks you to.";
            $prompt .= "\nDo not redesign the outfit unless the adjustment asks you to.";
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

    private function characterAssets(Character $character): Collection
    {
        return $character->relationLoaded('assets')
            ? $character->assets
            : $character->assets()->get();
    }

    private function portraitAsset(Character $character): ?CharacterAsset
    {
        $assets = $this->characterAssets($character);

        return $assets->first(
            fn (CharacterAsset $asset) => $asset->asset_type === 'portrait' && filled($asset->image_url)
        ) ?? $assets->first(
            fn (CharacterAsset $asset) => $asset->is_primary && filled($asset->image_url)
        );
    }

    private function costumeAsset(Character $character): ?CharacterAsset
    {
        return $this->characterAssets($character)->first(
            fn (CharacterAsset $asset) => $asset->asset_type === 'costume' && filled($asset->image_url)
        );
    }

    private function identityAsset(Character $character): ?CharacterAsset
    {
        return $this->costumeAsset($character) ?? $this->portraitAsset($character);
    }

    private function primaryEnvironmentAsset(Environment $environment): ?EnvironmentAsset
    {
        $assets = $environment->relationLoaded('assets')
            ? $environment->assets
            : $environment->assets()->get();

        return $assets->first(
            fn (EnvironmentAsset $asset) => $asset->is_primary && filled($asset->image_url)
        ) ?? $assets->first(fn (EnvironmentAsset $asset) => filled($asset->image_url));
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
        return $this->tryImage($prompt, $references, '16:9');
    }

    /**
     * @param  list<array{binary: string, mime: string}>  $references
     * @return array{binary: string, mime: string}
     */
    private function tryImage(string $prompt, array $references, string $aspectRatio): array
    {
        try {
            return $this->gemini->generateImage($prompt, $references, $aspectRatio);
        } catch (GenerationFailedException $e) {
            if ($references === [] || ! $this->shouldRetryWithoutReferences($e)) {
                throw $e;
            }
        }

        return $this->gemini->generateImage($prompt, [], $aspectRatio);
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
    private function identityReferences(Collection $characters, Shot $shot): array
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
            if (count($references) >= 3) {
                break;
            }

            $asset = $this->identityAsset($character);
            $file = $this->store->readPublicUrl($asset?->image_url);
            if ($file !== null) {
                $references[] = $file;
            }
        }

        return $references;
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

    private function costumePrompt(Character $character, ?string $style): string
    {
        $lines = [
            'Create a single photorealistic full-body costume sheet for a film character.',
            'One person only, standing, facing camera, head to shoes visible, plain studio backdrop.',
            'Show the complete outfit: top, bottom, shoes, bag, jewelry, and any accessories.',
            'No text, no watermark, no collage, no split frames.',
            'If a portrait is attached, copy that exact face and hair. This is a wardrobe bible, not a scene.',
            '',
            'Name: '.$character->name,
        ];

        foreach ([
            'Role' => $character->role,
            'Gender' => $character->gender,
            'Age' => $character->age_range,
            'Ethnicity' => $character->ethnicity,
            'Appearance' => $character->appearance,
            'Must wear' => $character->wardrobe,
            'Description' => $character->description,
        ] as $label => $value) {
            if (filled($value)) {
                $lines[] = $label.': '.$value;
            }
        }

        if (! filled($character->wardrobe)) {
            $lines[] = 'Invent a complete, specific costume (top, bottom, shoes, and 1-2 accessories with colors and materials) and lock that exact look.';
        }

        if (filled($style)) {
            $lines[] = 'Visual style: '.$style;
        }

        return implode("\n", $lines);
    }

    private function environmentPrompt(Environment $environment, ?string $style): string
    {
        $lines = [
            'Create a single photorealistic empty location plate for a film.',
            'No people, no text, no watermark, no collage, no split frames.',
            '',
            'Name: '.($environment->name ?: 'Untitled location'),
        ];

        foreach ([
            'Type' => $environment->type,
            'Time of day' => $environment->time_of_day,
            'Description' => $environment->description,
            'Appearance' => $environment->appearance,
            'Lighting' => $environment->lighting,
            'Mood' => $environment->mood,
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
            'This is a NEW frame. Follow THIS shot\'s action, camera, and location exactly.',
            'Attached images are costume sheets (or portraits if no sheet exists).',
            'Copy the EXACT garments, colors, and accessories from each costume sheet. Do not redesign the outfit.',
            'Copy faces and hair from those sheets.',
            'Do not copy the studio backdrop, standing pose, or lighting from the sheets.',
            'Do not copy a previous storyboard location, pose, or action.',
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
                $line = $character->name;
                if (filled($character->wardrobe)) {
                    $line .= '. MUST WEAR: '.$character->wardrobe;
                }
                if (filled($character->appearance)) {
                    $line .= '. '.$character->appearance;
                }

                return '- '.$line;
            })
            ->values();

        if ($cast->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'Costume lock (copy these garments exactly from the attached sheets):';
            $lines = array_merge($lines, $cast->all());
        }

        if (filled($style)) {
            $lines[] = 'Visual style: '.$style;
        }

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
