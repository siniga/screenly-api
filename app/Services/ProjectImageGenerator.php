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
use App\Support\CharacterNameMatch;
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

        $prompt = $this->characterPrompt($character, $project);
        $styleRef = $this->styleReferenceFile($project);
        $references = $styleRef ? [$styleRef] : [];

        try {
            $image = $this->tryImage($prompt, $references, '3:4', $styleRef);
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

        $prompt = $this->costumePrompt($character, $project);
        $references = [];
        $portrait = $this->store->readPublicUrl($this->portraitAsset($character)?->image_url);
        if ($portrait !== null) {
            $references[] = $portrait;
        }
        $styleRef = $this->styleReferenceFile($project);
        $references = $this->withStyleReferences($styleRef, $references);

        $image = $this->tryImage($prompt, $references, '3:4', $styleRef);

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

        $prompt = $this->environmentPrompt($environment, $project);
        $styleRef = $this->styleReferenceFile($project);
        $references = $styleRef ? [$styleRef] : [];

        try {
            $image = $this->tryImage($prompt, $references, '16:9', $styleRef);
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
        $shot->loadMissing(['scene', 'environment.assets', 'images', 'characters.assets']);
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

        $allCharacters = $project->characters()->with('assets')->orderBy('order_index')->get();
        $present = $this->presentCharacters($shot, $allCharacters);
        $absent = $allCharacters
            ->reject(fn (Character $character) => $present->contains('id', $character->id))
            ->values();
        $prompt = $this->shotPrompt($shot, $present, $absent, $project);
        $styleRef = $this->styleReferenceFile($project);
        $references = $this->shotReferences($present, $shot, $styleRef);

        if ($adjustment !== '') {
            $prompt .= "\n\nA current still is attached as a starting point for this edit.";
            $prompt .= "\nKeep identity only: faces, hair, and the exact garments from the costume sheet.";
            $prompt .= "\nApply this adjustment:\n".$adjustment;
            $prompt .= "\nIf the adjustment changes action, pose, camera, location, or who is in the frame, follow the adjustment.";
            $prompt .= "\nDo not change the story. Do not add people, props, or business the Action and adjustment do not describe.";
            $prompt .= "\nDo not keep the old room, pose, or action unless the adjustment asks you to.";
            $prompt .= "\nDo not redesign the outfit unless the adjustment asks you to.";
            $currentStill = $this->store->readPublicUrl($existing?->image_url);
            if ($currentStill !== null) {
                array_unshift($references, $currentStill);
                $references = array_slice($references, 0, 4);
            }
            $shot->prompt = $adjustment;
        }

        try {
            $image = $this->tryShotImage($prompt, $references, $styleRef);
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

    /**
     * @return array{skipped: bool, generated: bool, url: ?string}
     */
    public function generateProjectCover(Project $project, bool $force = false): array
    {
        if (filled($project->cover_image_url) && ! $force) {
            return [
                'skipped' => true,
                'generated' => false,
                'url' => $project->cover_image_url,
            ];
        }

        $project->loadMissing(['scenes' => fn ($query) => $query->orderBy('order_index')]);

        $prompt = $this->coverPrompt($project);
        $styleRef = $this->styleReferenceFile($project);
        $references = $styleRef ? [$styleRef] : [];

        $image = $this->tryImage($prompt, $references, '16:9', $styleRef);

        $url = $this->store->put(
            $project->id,
            'cover',
            'poster',
            $image['binary'],
            $image['mime'],
        );

        $project->cover_image_url = $url;
        $project->save();

        return [
            'skipped' => false,
            'generated' => true,
            'url' => $url,
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
     * @param  array{binary: string, mime: string}|null  $requiredReference
     * @return array{binary: string, mime: string}
     */
    private function tryShotImage(string $prompt, array $references, ?array $requiredReference = null): array
    {
        return $this->tryImage($prompt, $references, '16:9', $requiredReference);
    }

    /**
     * @param  list<array{binary: string, mime: string}>  $references
     * @param  array{binary: string, mime: string}|null  $requiredReference
     * @return array{binary: string, mime: string}
     */
    private function tryImage(string $prompt, array $references, string $aspectRatio, ?array $requiredReference = null): array
    {
        try {
            return $this->gemini->generateImage($prompt, $references, $aspectRatio);
        } catch (GenerationFailedException $e) {
            if ($references === [] || ! $this->shouldRetryWithoutReferences($e)) {
                throw $e;
            }
        }

        if ($requiredReference !== null) {
            return $this->gemini->generateImage($prompt, [$requiredReference], $aspectRatio);
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
     * @return array{binary: string, mime: string}|null
     */
    private function styleReferenceFile(Project $project): ?array
    {
        if (filled($project->style_reference_url)) {
            $file = $this->store->readPublicUrl($project->style_reference_url);
            if ($file !== null) {
                return $file;
            }
        }

        $variant = data_get($project->style_meta, 'variant');
        if (! is_string($variant) || $variant === '' || $variant === 'custom') {
            return null;
        }

        $path = public_path('style-previews/'.$variant.'.png');
        if (! is_file($path)) {
            return null;
        }

        $binary = file_get_contents($path);
        if (! is_string($binary) || $binary === '') {
            return null;
        }

        return [
            'binary' => $binary,
            'mime' => 'image/png',
        ];
    }

    /**
     * @param  array{binary: string, mime: string}|null  $styleRef
     * @param  list<array{binary: string, mime: string}>  $others
     * @return list<array{binary: string, mime: string}>
     */
    private function withStyleReferences(?array $styleRef, array $others): array
    {
        $references = [];
        if ($styleRef !== null) {
            $references[] = $styleRef;
        }

        foreach ($others as $other) {
            if (count($references) >= 4) {
                break;
            }
            if (! is_array($other) || ($other['binary'] ?? '') === '') {
                continue;
            }
            $references[] = $other;
        }

        return $references;
    }

    /**
     * @param  Collection<int, Character>  $present
     * @param  array{binary: string, mime: string}|null  $styleRef
     * @return list<array{binary: string, mime: string}>
     */
    private function shotReferences(Collection $present, Shot $shot, ?array $styleRef = null): array
    {
        $identity = [];

        foreach ($present as $character) {
            if (count($identity) >= 3) {
                break;
            }

            $asset = $this->identityAsset($character);
            $file = $this->store->readPublicUrl($asset?->image_url);
            if ($file !== null) {
                $identity[] = $file;
            }
        }

        if (count($identity) < 3) {
            $plate = $this->environmentPlateFile($shot);
            if ($plate !== null) {
                $identity[] = $plate;
            }
        }

        return $this->withStyleReferences($styleRef, $identity);
    }

    /**
     * @return array{binary: string, mime: string}|null
     */
    private function environmentPlateFile(Shot $shot): ?array
    {
        $environment = $shot->environment;
        if ($environment === null) {
            return null;
        }

        $environment->loadMissing('assets');

        return $this->store->readPublicUrl($this->primaryEnvironmentAsset($environment)?->image_url);
    }

    /**
     * @param  Collection<int, Character>  $all
     * @return Collection<int, Character>
     */
    private function presentCharacters(Shot $shot, Collection $all): Collection
    {
        $shot->loadMissing('characters');
        if ($shot->characters->isNotEmpty()) {
            $ids = $shot->characters->modelKeys();

            return $all->whereIn('id', $ids)->values();
        }

        $settings = is_array($shot->storyboard_settings) ? $shot->storyboard_settings : [];
        $fromSettings = CharacterNameMatch::fromNames(
            $all,
            is_array($settings['characters_in_shot'] ?? null) ? $settings['characters_in_shot'] : []
        );
        if ($fromSettings->isNotEmpty()) {
            $this->rememberShotCast($shot, $fromSettings);

            return $fromSettings;
        }

        $fromText = CharacterNameMatch::fromHaystack($all, implode(' ', array_filter([
            $shot->title,
            $shot->action,
            $shot->description,
            $shot->dialogue,
        ])));
        if ($fromText->isNotEmpty()) {
            $this->rememberShotCast($shot, $fromText);
        }

        return $fromText;
    }

    /**
     * @param  Collection<int, Character>  $present
     */
    private function rememberShotCast(Shot $shot, Collection $present): void
    {
        $shot->characters()->sync($present->pluck('id')->all());
        $shot->setRelation('characters', $present);
    }

    private function shotExtras(Shot $shot): string
    {
        $settings = is_array($shot->storyboard_settings) ? $shot->storyboard_settings : [];
        $extras = trim((string) ($settings['extras'] ?? ''));

        if ($extras === '' || preg_match('/^(none|no|n\/a|-)$/i', $extras)) {
            return '';
        }

        return $extras;
    }

    private function styleLockLines(Project $project): array
    {
        $prompt = $project->generationStylePrompt();
        if (! filled($prompt)) {
            return [];
        }

        $lines = [
            'STYLE LOCK - ABSOLUTE:',
            $prompt,
        ];

        if ($this->styleReferenceFile($project) !== null) {
            $lines[] = 'A style-reference image is attached. Copy line, color, shading, proportions, materials, and rendering from it only. Do not copy faces, bodies, clothing, or composition from it.';
        }

        if ($project->isIllustratedStyle()) {
            $lines[] = 'Match this look on every person, costume, place, and frame. Do not switch to photorealism, live-action, or a different medium.';
        } else {
            $lines[] = 'Match this look on every person, costume, place, and frame. Stay photoreal. Do not switch to cartoon, anime, or illustration.';
        }

        $lines[] = 'Style must not change Action or story.';

        return $lines;
    }

    private function pictureIntegrityLines(): array
    {
        return [
            'PICTURE LOCK - ABSOLUTE:',
            'Output one finished picture only. The picture is the entire canvas.',
            'Fill the frame edge to edge. No letterboxing, no pillarboxing, no black bars, no white bars, no borders, no frames, no mattes, no padding, no strip lines, no film rebate, no collage, no split screen, no UI chrome.',
            'No text of any kind: no captions, no subtitles, no titles, no credits, no logos, no watermarks, no labels, no numbers, no speech bubbles, no closed captions, no lower-thirds, no title cards.',
            'Nothing overlaid on the picture. No graphics, no stickers, no comic balloons, no burned-in dialogue.',
        ];
    }

    private function characterPrompt(Character $character, Project $project): string
    {
        $illustrated = $project->isIllustratedStyle();
        $look = $project->lockedLookPhrase();
        $lines = [
            $illustrated
                ? 'Create a single character portrait in the '.$look.'.'
                : 'Create a single photorealistic character portrait for a film.',
            'One person only, facing camera, chest-up, plain studio backdrop.',
            ...$this->pictureIntegrityLines(),
            '',
            ...$this->styleLockLines($project),
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

        return implode("\n", $lines);
    }

    private function costumePrompt(Character $character, Project $project): string
    {
        $illustrated = $project->isIllustratedStyle();
        $look = $project->lockedLookPhrase();
        $lines = [
            $illustrated
                ? 'Create a single full-body costume sheet in the '.$look.'.'
                : 'Create a single photorealistic full-body costume sheet for a film character.',
            'One person only, standing, facing camera, head to shoes visible, plain studio backdrop.',
            'Show the complete outfit: top, bottom, shoes, bag, jewelry, and any accessories.',
            ...$this->pictureIntegrityLines(),
            'If a portrait is attached, copy that exact face and hair. This is a wardrobe bible, not a scene.',
            '',
            ...$this->styleLockLines($project),
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

        return implode("\n", $lines);
    }

    private function environmentPrompt(Environment $environment, Project $project): string
    {
        $illustrated = $project->isIllustratedStyle();
        $look = $project->lockedLookPhrase();
        $lines = [
            $illustrated
                ? 'Create a single empty location plate in the '.$look.'.'
                : 'Create a single photorealistic empty location plate for a film.',
            'No people in the frame.',
            ...$this->pictureIntegrityLines(),
            '',
            ...$this->styleLockLines($project),
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

        return implode("\n", $lines);
    }

    private function coverPrompt(Project $project): string
    {
        $illustrated = $project->isIllustratedStyle();
        $look = $project->lockedLookPhrase();
        $title = trim((string) ($project->title ?? ''));
        if ($title === '' || strcasecmp($title, 'Untitled Project') === 0) {
            $title = '';
        }

        $lines = [
            $illustrated
                ? 'Create a single 16:9 key-art still for a film project cover in the '.$look.'.'
                : 'Create a single 16:9 cinematic key-art still for a film project cover.',
            'One wide establishing image of the story world. Poster-like, not a character sheet, not a collage, not a split frame.',
            'Fill every pixel of the 16:9 canvas with the picture.',
            ...$this->pictureIntegrityLines(),
            'Keep it PG-13: implied action, no graphic injury, no blood, no sexual content.',
            '',
            ...$this->styleLockLines($project),
            '',
            'STORY LOCK - ABSOLUTE:',
            'Do not invent a different plot. Represent this story only.',
        ];

        if ($title !== '') {
            $lines[] = 'Project title (do not paint this as text): '.$title;
        }

        $story = trim((string) ($project->story ?? ''));
        if ($story !== '') {
            $lines[] = 'Story: '.$this->clipText($story, 1200);
        }

        $scenes = $project->relationLoaded('scenes') ? $project->scenes : collect();
        if ($scenes->isNotEmpty()) {
            $lines[] = 'Sequences:';
            foreach ($scenes->take(6) as $scene) {
                $bit = trim(implode(' — ', array_filter([
                    $scene->title,
                    $scene->location,
                    $scene->time_of_day,
                ], fn ($value) => filled($value))));
                if ($bit !== '') {
                    $lines[] = '- '.$bit;
                }
            }
        }

        $screenplay = trim((string) ($project->screenplay ?? ''));
        if ($story === '' && $screenplay !== '') {
            $lines[] = 'Screenplay excerpt: '.$this->clipText($screenplay, 900);
        }

        return implode("\n", $lines);
    }

    private function clipText(string $text, int $max): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $max - 3)).'...';
    }

    /**
     * @param  Collection<int, Character>  $present
     * @param  Collection<int, Character>  $absent
     */
    private function shotPrompt(Shot $shot, Collection $present, Collection $absent, Project $project): string
    {
        $count = $present->count();
        $presentNames = $present->pluck('name')->filter()->values();
        $absentNames = $absent->pluck('name')->filter()->values();
        $extras = $this->shotExtras($shot);
        $action = $this->softenForImage((string) ($shot->action ?: $shot->description));
        $illustrated = $project->isIllustratedStyle();
        $look = $project->lockedLookPhrase();
        $world = match ($project->styleFamily()) {
            'cartoon' => 'this cartoon world',
            'anime' => 'this anime world',
            'storyboard_sketch' => 'this drawn storyboard world',
            default => null,
        };

        $lines = [
            $illustrated
                ? 'Create a single storyboard still in the '.$look.'. One frame only.'
                : 'Create a single cinematic storyboard still for a fictional film. One frame only.',
            'Keep it PG-13: implied action, no graphic injury, no blood, no sexual content.',
            '16:9 widescreen. Fill every pixel of the 16:9 canvas with the scene. Level horizon. Do not distort bodies, faces, limbs, or architecture. No Dutch tilt, fisheye, or stretched perspective.',
            ...$this->pictureIntegrityLines(),
            'If people are speaking, show it only in faces and mouths. Do not render spoken lines. Dialogue is not part of this picture.',
            '',
            ...$this->styleLockLines($project),
            '',
            'STORY LOCK - ABSOLUTE:',
            'Do not change the story. Do not add, remove, reorder, or invent people, props, gestures, or business.',
            'Render ONLY what Action describes. If Action does not mention it, it is not in the frame.',
            '',
            'ACTION IS THE ONLY SOURCE FOR STAGING:',
            'Read Action first. Pose, gesture, body position, eyeline, distance, and who faces whom MUST match Action exactly.',
            $illustrated
                ? 'Freeze that exact beat. Natural blocking for '.$world.'. Not a portrait. Not a costume sheet. Not a lineup.'
                : 'Freeze that exact beat. Natural documentary blocking, as if a hidden camera caught it. Not a portrait. Not a costume sheet. Not a lineup.',
        ];

        if ($action !== '') {
            $lines[] = 'ACTION (match this exactly): '.$action;
        }

        $lines[] = '';
        $lines[] = 'NATURAL SET AND BLOCKING - ABSOLUTE:';
        $lines[] = $illustrated
            ? 'The whole set must look like a location in '.$world.', matching Location, Time of day, and Action. Not a photograph of a real place unless the locked style is photoreal.'
            : 'The whole set must look real and lived-in, matching Location, Time of day, and Action.';
        $lines[] = 'People occupy distinct physical space. Do not overlap bodies, faces, or limbs. No fused figures, no shared arms, no one standing inside another person.';
        $lines[] = 'Contact (a hug, a grab, sitting together) is allowed only if Action describes it. Otherwise leave clear space between people.';
        $lines[] = 'Do not arrange anyone for the camera. No catalog stance, no fashion pose, no everyone facing the same way unless Action says so.';
        $lines[] = 'Eyelines follow Action (at the other person, an object, the door, the ground, etc.). Nobody looks into the lens unless Action says they do.';
        $lines[] = 'Hands, feet, and weight must look physically possible for that Action.';
        $lines[] = '';
        $lines[] = 'Attached images may include a style-reference still, costume sheets for visible characters, and/or an empty location plate.';
        $lines[] = 'Copy FACE, HAIR, and EXACT GARMENTS from costume sheets only. Do not copy standing pose, studio backdrop, or sheet lighting.';
        $lines[] = 'If a location plate is attached, use that architecture and perspective. Do not copy people from it (it should be empty).';
        $lines[] = 'Do not copy a previous storyboard location, pose, or action.';

        if ($count === 0) {
            $lines[] = 'VISIBLE NAMED CHARACTERS: none. Only people described in Action, if any.';
        } else {
            $lines[] = 'VISIBLE NAMED CHARACTERS: exactly '.$count." \u{2014} ".$presentNames->implode(', ').'.';
            $lines[] = 'Each of these people appears exactly once. Do not clone, duplicate, or split anyone into two bodies.';
            $lines[] = 'Place them only as Action describes. Do not add any other named person.';
        }

        if ($absentNames->isNotEmpty()) {
            $lines[] = 'Do not include these project characters at all: '.$absentNames->implode(', ').'.';
        }

        if ($extras !== '') {
            $lines[] = 'Background extras: '.$extras.'. Small, out of focus, unrecognizable, not matching any named character, not looking at the camera.';
        } else {
            $lines[] = 'No crowd. No extra faces. No background people unless Action requires them; if it does, they are unrecognizable and not looking at the camera.';
        }

        $lines[] = '';
        $lines[] = 'Shot: '.$this->softenForImage($shot->title ?: 'Untitled shot');

        foreach ([
            'Action' => $action,
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

        $cast = $present
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
            $lines[] = 'Costume lock (identity only - Action still controls pose):';
            $lines = array_merge($lines, $cast->all());
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
