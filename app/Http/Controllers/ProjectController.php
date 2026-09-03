<?php

namespace App\Http\Controllers;

use App\Exceptions\GenerationFailedException;
use App\Http\Requests\GenerateCharactersRequest;
use App\Http\Requests\GenerateEnvironmentsRequest;
use App\Http\Requests\GenerateEpisodeRequest;
use App\Http\Requests\GenerateScenesRequest;
use App\Http\Requests\GenerateScreenplayRequest;
use App\Http\Requests\GenerateScriptRequest;
use App\Http\Requests\GenerateShotsRequest;
use App\Http\Requests\PlanEpisodesRequest;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateCharacterRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Models\Character;
use App\Models\CharacterAsset;
use App\Models\Environment;
use App\Models\EnvironmentAsset;
use App\Models\Episode;
use App\Models\Project;
use App\Models\Scene;
use App\Models\Shot;
use App\Models\ShotImage;
use App\Services\ProjectImageGenerator;
use App\Services\ProjectImageStore;
use App\Services\ProjectTextGenerator;
use App\Services\SystemErrorLogger;
use App\Support\CharacterNameMatch;
use App\Support\StoryLength;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $projects = $request->user()
            ->projects()
            ->withCount(['scenes', 'shots'])
            ->latest('updated_at')
            ->get();

        $sceneCovers = $this->sceneCoverUrlsByProjectId($projects->pluck('id')->all());

        return response()->json([
            'success' => true,
            'projects' => $projects
                ->map(fn (Project $project) => $this->summary(
                    $project,
                    $sceneCovers[(int) $project->id] ?? null,
                ))
                ->values(),
        ]);
    }

    public function store(StoreProjectRequest $request): JsonResponse
    {
        $attributes = $this->attributesFromRequest($request->validated());

        if (empty($attributes['title'])) {
            $attributes['title'] = 'Untitled Project';
        }

        $project = $request->user()->projects()->create($attributes);
        $this->attachBundledStylePreview($project);

        return response()->json([
            'success' => true,
            'project' => $this->detail($project->fresh()),
        ], 201);
    }

    public function show(Request $request, Project $project): JsonResponse
    {
        $this->authorizeProject($request, $project);

        $project->loadCount(['scenes', 'shots']);
        $project->load([
            'scenes' => fn ($query) => $query->orderBy('order_index'),
            'characters' => fn ($query) => $query->orderBy('order_index'),
            'characters.assets',
            'environments' => fn ($query) => $query->orderBy('order_index'),
            'environments.assets',
            'shots' => fn ($query) => $query->orderBy('order_index'),
            'shots.scene',
            'shots.images',
        ]);

        return response()->json([
            'success' => true,
            'project' => $this->detail($project),
        ]);
    }

    public function update(UpdateProjectRequest $request, Project $project): JsonResponse
    {
        $this->authorizeProject($request, $project);

        $project->fill($this->attributesFromRequest($request->validated()));
        $project->save();
        $this->attachBundledStylePreview($project);

        $project->loadCount(['scenes', 'shots']);

        return response()->json([
            'success' => true,
            'project' => $this->detail($project),
        ]);
    }

    public function destroy(Request $request, Project $project): JsonResponse
    {
        $this->authorizeProject($request, $project);
        $project->delete();

        return response()->json(['success' => true]);
    }

    public function generateScript(
        GenerateScriptRequest $request,
        Project $project,
        ProjectTextGenerator $generator,
    ): JsonResponse {
        $this->authorizeProject($request, $project);
        set_time_limit(120);

        $data = $request->validated();
        $story = trim((string) ($data['story'] ?? $project->story ?? ''));
        if (mb_strlen($story) < 20) {
            return response()->json([
                'success' => false,
                'message' => 'Story must be at least 20 characters.',
            ], 422);
        }

        $style = $this->styleFromRequest($data, $project);

        try {
            $script = $generator->scriptFromStory($story, $style);
        } catch (GenerationFailedException $e) {
            return $this->generationFailed($e, $project);
        }

        $project->story = $story;
        if ($style !== null && ! filled($project->style_prompt)) {
            $project->style = $style;
        }
        $project->script = $script;
        $project->current_step = 'script';
        $project->save();
        $project->loadCount(['scenes', 'shots']);

        return response()->json([
            'success' => true,
            'script' => $script,
            'project' => $this->detail($project),
        ]);
    }

    public function generateScreenplay(
        GenerateScreenplayRequest $request,
        Project $project,
        ProjectTextGenerator $generator,
    ): JsonResponse {
        $this->authorizeProject($request, $project);
        set_time_limit(180);

        $data = $request->validated();
        $story = trim((string) ($data['story'] ?? $project->story ?? ''));
        if (mb_strlen($story) < 20) {
            return response()->json([
                'success' => false,
                'message' => 'Story must be at least 20 characters.',
            ], 422);
        }

        $style = $this->styleFromRequest($data, $project);

        if (StoryLength::needsEpisodes($story)) {
            return response()->json($this->storyTooLongPayload($story), 422);
        }

        try {
            $screenplay = $generator->screenplayFromStory($story, $style);
        } catch (GenerationFailedException $e) {
            return $this->generationFailed($e, $project);
        }

        $project->story = $story;
        if ($style !== null && ! filled($project->style_prompt)) {
            $project->style = $style;
        }
        $project->screenplay = $screenplay;
        $project->current_step = 'screenplay';
        $project->save();
        $project->loadCount(['scenes', 'shots']);
        $project->load(['scenes' => fn ($query) => $query->orderBy('order_index')]);

        return response()->json([
            'success' => true,
            'screenplay' => $screenplay,
            'project' => $this->detail($project),
        ]);
    }

    public function planEpisodes(
        PlanEpisodesRequest $request,
        Project $project,
        ProjectTextGenerator $generator,
    ): JsonResponse {
        $this->authorizeProject($request, $project);
        set_time_limit(180);

        $data = $request->validated();
        $story = trim((string) ($data['story'] ?? $project->story ?? ''));
        if (mb_strlen($story) < 20) {
            return response()->json([
                'success' => false,
                'message' => 'Story must be at least 20 characters.',
            ], 422);
        }

        $style = $this->styleFromRequest($data, $project);
        $existing = $project->episodes()->orderBy('episode_number')->get();
        if ($existing->count() >= 2) {
            $project->story = $story;
            if ($style !== null && ! filled($project->style_prompt)) {
                $project->style = $style;
            }
            $project->save();
            $project->loadCount(['scenes', 'shots']);
            $project->setRelation('episodes', $existing);

            return response()->json([
                'success' => true,
                'episodes' => $existing->map(fn (Episode $episode) => $this->serializeEpisode($episode))->values(),
                'project' => $this->detail($project),
            ]);
        }

        try {
            $rows = $generator->episodePlanFromStory(
                $story,
                StoryLength::estimatedEpisodeCount($story),
                $style,
            );
        } catch (GenerationFailedException $e) {
            return $this->generationFailed($e, $project);
        }

        $project->story = $story;
        if ($style !== null && ! filled($project->style_prompt)) {
            $project->style = $style;
        }
        $project->save();
        $project->episodes()->delete();

        $created = [];
        foreach ($rows as $row) {
            $created[] = $project->episodes()->create([
                'episode_number' => $row['episode_number'],
                'title' => $row['title'],
                'summary' => $row['summary'],
                'status' => 'planned',
            ]);
        }

        $episodes = collect($created);
        $project->loadCount(['scenes', 'shots']);
        $project->setRelation('episodes', $episodes);

        return response()->json([
            'success' => true,
            'episodes' => $episodes->map(fn (Episode $episode) => $this->serializeEpisode($episode))->values(),
            'project' => $this->detail($project),
        ]);
    }

    public function generateEpisode(
        GenerateEpisodeRequest $request,
        Project $project,
        ProjectTextGenerator $generator,
    ): JsonResponse {
        $this->authorizeProject($request, $project);
        set_time_limit(180);

        $data = $request->validated();
        $episodeNumber = (int) ($data['episode_number'] ?? 1);
        $style = $this->styleFromRequest($data, $project);
        $story = trim((string) ($project->story ?? ''));
        if (mb_strlen($story) < 20) {
            return response()->json([
                'success' => false,
                'message' => 'Story must be at least 20 characters.',
            ], 422);
        }

        $episode = $project->episodes()->where('episode_number', $episodeNumber)->first();
        if (! $episode) {
            return response()->json([
                'success' => false,
                'message' => 'Plan episodes before generating an episode screenplay.',
            ], 422);
        }

        if (mb_strlen(trim((string) $episode->screenplay)) >= 20) {
            $project->screenplay = $episode->screenplay;
            $project->current_step = 'screenplay';
            if ($style !== null && ! filled($project->style_prompt)) {
                $project->style = $style;
            }
            $project->save();
            $project->loadCount(['scenes', 'shots']);
            $project->load(['episodes' => fn ($query) => $query->orderBy('episode_number')]);

            return response()->json([
                'success' => true,
                'screenplay' => $episode->screenplay,
                'episode' => $this->serializeEpisode($episode),
                'project' => $this->detail($project),
            ]);
        }

        $previous = $project->episodes()
            ->where('episode_number', '<', $episodeNumber)
            ->orderBy('episode_number')
            ->get()
            ->map(fn (Episode $prior) => [
                'episode_number' => $prior->episode_number,
                'title' => $prior->title,
                'summary' => $prior->summary,
            ])
            ->all();

        try {
            $screenplay = $generator->screenplayForEpisode(
                $story,
                [
                    'episode_number' => $episode->episode_number,
                    'title' => $episode->title,
                    'summary' => $episode->summary,
                ],
                $previous,
                $style,
            );
        } catch (GenerationFailedException $e) {
            return $this->generationFailed($e, $project);
        }

        $episode->screenplay = $screenplay;
        $episode->status = 'written';
        $episode->save();

        $project->screenplay = $screenplay;
        $project->current_step = 'screenplay';
        if ($style !== null && ! filled($project->style_prompt)) {
            $project->style = $style;
        }
        $project->save();
        $project->loadCount(['scenes', 'shots']);
        $project->load(['episodes' => fn ($query) => $query->orderBy('episode_number')]);

        return response()->json([
            'success' => true,
            'screenplay' => $screenplay,
            'episode' => $this->serializeEpisode($episode->fresh()),
            'project' => $this->detail($project),
        ]);
    }

    public function generateScenes(
        GenerateScenesRequest $request,
        Project $project,
        ProjectTextGenerator $generator,
        ProjectImageGenerator $images,
    ): JsonResponse {
        $this->authorizeProject($request, $project);
        set_time_limit(300);

        $data = $request->validated();
        $screenplay = trim((string) ($data['screenplay'] ?? $project->screenplay ?? ''));
        if (mb_strlen($screenplay) < 20) {
            return response()->json([
                'success' => false,
                'message' => 'Screenplay must be at least 20 characters.',
            ], 422);
        }

        $style = $this->styleFromRequest($data, $project);

        $existing = $project->scenes()->orderBy('order_index')->get();
        if ($existing->isNotEmpty()) {
            $project->screenplay = $screenplay;
            if ($style !== null && ! filled($project->style_prompt)) {
                $project->style = $style;
            }
            $project->current_step = 'sceneboard';
            $project->save();
            $project->loadCount(['scenes', 'shots']);
            $project->setRelation('scenes', $existing);
            $this->ensureProjectCover($project, $images);

            return response()->json([
                'success' => true,
                'scenes' => $existing->map(fn (Scene $scene) => $this->serializeScene($scene))->values(),
                'project' => $this->detail($project->fresh()),
            ]);
        }

        try {
            $rows = $generator->scenesFromScreenplay(
                $screenplay,
                $style,
                $this->sourceStory($project),
            );
        } catch (GenerationFailedException $e) {
            return $this->generationFailed($e, $project);
        }

        $project->screenplay = $screenplay;
        if ($style !== null && ! filled($project->style_prompt)) {
            $project->style = $style;
        }
        $project->current_step = 'sceneboard';
        $project->save();

        $created = [];
        foreach ($rows as $index => $row) {
            $created[] = $project->scenes()->create([
                'scene_number' => $index + 1,
                'order_index' => $index,
                'title' => $row['title'],
                'location' => $row['location'],
                'time_of_day' => $row['time_of_day'],
                'description' => $row['description'],
                'mood' => $row['mood'],
                'visual_style' => $style,
                'status' => 'completed',
                'generated_at' => now(),
            ]);
        }

        $scenes = collect($created);
        $project->loadCount(['scenes', 'shots']);
        $project->setRelation('scenes', $scenes);
        $this->ensureProjectCover($project, $images);

        return response()->json([
            'success' => true,
            'scenes' => $scenes->map(fn (Scene $scene) => $this->serializeScene($scene))->values(),
            'project' => $this->detail($project->fresh()),
        ]);
    }

    public function generateCharacters(
        GenerateCharactersRequest $request,
        Project $project,
        ProjectTextGenerator $generator,
    ): JsonResponse {
        $this->authorizeProject($request, $project);
        set_time_limit(180);

        $data = $request->validated();
        $screenplay = trim((string) ($data['screenplay'] ?? $project->screenplay ?? ''));
        if (mb_strlen($screenplay) < 20) {
            return response()->json([
                'success' => false,
                'message' => 'Screenplay must be at least 20 characters.',
            ], 422);
        }

        $style = $this->styleFromRequest($data, $project);

        $existing = $project->characters()->orderBy('order_index')->get();
        if ($existing->isNotEmpty()) {
            if ($style !== null && ! filled($project->style_prompt)) {
                $project->style = $style;
            }
            $project->current_step = 'characters';
            $project->save();
            $project->loadCount(['scenes', 'shots']);
            $project->setRelation('characters', $existing);

            return response()->json([
                'success' => true,
                'characters' => $existing->map(fn (Character $character) => $this->serializeCharacter($character))->values(),
                'project' => $this->detail($project),
            ]);
        }

        $sequences = $project->scenes()
            ->orderBy('order_index')
            ->get(['title', 'description'])
            ->map(fn (Scene $scene) => [
                'title' => $scene->title,
                'description' => $scene->description,
            ])
            ->all();

        try {
            $rows = $generator->charactersFromScreenplay(
                $screenplay,
                $style,
                $sequences,
                $this->sourceStory($project),
            );
        } catch (GenerationFailedException $e) {
            return $this->generationFailed($e, $project);
        }

        if ($style !== null && ! filled($project->style_prompt)) {
            $project->style = $style;
        }
        $project->current_step = 'characters';
        $project->save();

        $created = [];
        foreach ($rows as $index => $row) {
            $created[] = $project->characters()->create([
                'order_index' => $index,
                'name' => $row['name'],
                'role' => $row['role'],
                'gender' => $row['gender'],
                'age_range' => $row['age_range'],
                'ethnicity' => $row['ethnicity'],
                'description' => $row['description'],
                'personality' => $row['personality'],
                'appearance' => $row['appearance'],
                'wardrobe' => $row['wardrobe'],
                'importance' => $row['importance'],
                'status' => 'suggested',
                'image_status' => 'pending',
            ]);
        }

        $characters = collect($created);
        $project->loadCount(['scenes', 'shots']);
        $project->setRelation('characters', $characters);

        return response()->json([
            'success' => true,
            'characters' => $characters->map(fn (Character $character) => $this->serializeCharacter($character))->values(),
            'project' => $this->detail($project),
        ]);
    }

    public function generateEnvironments(
        GenerateEnvironmentsRequest $request,
        Project $project,
        ProjectTextGenerator $generator,
    ): JsonResponse {
        $this->authorizeProject($request, $project);
        set_time_limit(180);

        $data = $request->validated();
        $screenplay = trim((string) ($data['screenplay'] ?? $project->screenplay ?? ''));
        if (mb_strlen($screenplay) < 20) {
            return response()->json([
                'success' => false,
                'message' => 'Screenplay must be at least 20 characters.',
            ], 422);
        }

        $style = $this->styleFromRequest($data, $project);

        $existing = $project->environments()->orderBy('order_index')->get();
        if ($existing->isNotEmpty()) {
            if ($style !== null && ! filled($project->style_prompt)) {
                $project->style = $style;
            }
            $project->current_step = 'environments';
            $project->save();
            $project->loadCount(['scenes', 'shots']);
            $project->setRelation('environments', $existing);

            return response()->json([
                'success' => true,
                'environments' => $existing->map(fn (Environment $environment) => $this->serializeEnvironment($environment))->values(),
                'project' => $this->detail($project),
            ]);
        }

        $sequences = $project->scenes()
            ->orderBy('order_index')
            ->get(['title', 'description', 'location'])
            ->map(fn (Scene $scene) => [
                'title' => $scene->title,
                'description' => $scene->description,
                'location' => $scene->location,
            ])
            ->all();

        try {
            $rows = $generator->environmentsFromScreenplay(
                $screenplay,
                $style,
                $sequences,
                $this->sourceStory($project),
            );
        } catch (GenerationFailedException $e) {
            return $this->generationFailed($e, $project);
        }

        if ($style !== null && ! filled($project->style_prompt)) {
            $project->style = $style;
        }
        $project->current_step = 'environments';
        $project->save();

        $created = [];
        foreach ($rows as $index => $row) {
            $created[] = $project->environments()->create([
                'order_index' => $index,
                'name' => $row['name'],
                'type' => $row['type'],
                'time_of_day' => $row['time_of_day'],
                'description' => $row['description'],
                'appearance' => $row['appearance'],
                'lighting' => $row['lighting'],
                'mood' => $row['mood'],
                'importance' => $row['importance'],
                'status' => 'suggested',
                'image_status' => 'pending',
            ]);
        }

        $environments = collect($created);
        $project->loadCount(['scenes', 'shots']);
        $project->setRelation('environments', $environments);

        return response()->json([
            'success' => true,
            'environments' => $environments->map(fn (Environment $environment) => $this->serializeEnvironment($environment))->values(),
            'project' => $this->detail($project),
        ]);
    }

    public function generateShots(
        GenerateShotsRequest $request,
        Project $project,
        ProjectTextGenerator $generator,
    ): JsonResponse {
        $this->authorizeProject($request, $project);
        set_time_limit(180);

        $data = $request->validated();
        $screenplay = trim((string) ($project->screenplay ?? ''));
        $scenes = $project->scenes()->orderBy('order_index')->get();
        if ($scenes->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Generate sequences before creating storyboard shots.',
            ], 422);
        }

        $style = $this->styleFromRequest($data, $project);

        $existing = $project->shots()->orderBy('order_index')->with('scene')->get();
        if ($existing->isNotEmpty()) {
            if ($style !== null && ! filled($project->style_prompt)) {
                $project->style = $style;
            }
            $project->current_step = 'storyboard';
            $project->save();
            $project->loadCount(['scenes', 'shots']);
            $project->setRelation('shots', $existing);

            return response()->json([
                'success' => true,
                'shots' => $existing->map(fn (Shot $shot) => $this->serializeShot($shot))->values(),
                'project' => $this->detail($project),
            ]);
        }

        $sequences = $scenes->map(fn (Scene $scene) => [
            'scene_number' => $scene->scene_number,
            'title' => $scene->title,
            'description' => $scene->description,
            'location' => $scene->location,
        ])->all();

        $characters = $project->characters()->orderBy('order_index')->get();
        $characterNames = $characters->pluck('name')->filter()->values()->all();

        try {
            $rows = $generator->shotsFromSequences(
                $screenplay,
                $sequences,
                $style,
                $this->sourceStory($project),
                $characterNames,
            );
        } catch (GenerationFailedException $e) {
            return $this->generationFailed($e, $project);
        }

        $scenesByNumber = $scenes->keyBy('scene_number');
        $environments = $project->environments()->orderBy('order_index')->get();

        if ($style !== null && ! filled($project->style_prompt)) {
            $project->style = $style;
        }
        $project->current_step = 'storyboard';
        $project->save();

        $created = [];
        $order = 0;
        foreach ($rows as $row) {
            $scene = $scenesByNumber->get($row['scene_number']) ?? $scenes->first();
            if (! $scene) {
                continue;
            }

            $environmentId = null;
            $place = strtolower((string) ($row['environment'] ?? $scene->location ?? ''));
            if ($place !== '') {
                $match = $environments->first(
                    fn (Environment $environment) => str_contains(strtolower($environment->name), $place)
                        || str_contains($place, strtolower($environment->name))
                );
                $environmentId = $match?->id;
            }

            $castNames = $row['characters_in_shot'] ?? [];
            $present = CharacterNameMatch::fromNames($characters, $castNames);
            if ($present->isEmpty()) {
                $present = CharacterNameMatch::fromHaystack(
                    $characters,
                    implode(' ', array_filter([
                        $row['title'] ?? null,
                        $row['description'] ?? null,
                        $row['action'] ?? null,
                        $row['dialogue'] ?? null,
                    ]))
                );
            }

            $shot = $project->shots()->create([
                'scene_id' => $scene->id,
                'environment_id' => $environmentId,
                'shot_number' => (string) ($order + 1),
                'order_index' => $order,
                'title' => $row['title'],
                'description' => $row['description'],
                'action' => $row['action'],
                'dialogue' => $row['dialogue'],
                'shot_size' => $row['shot_size'],
                'camera_angle' => $row['camera_angle'],
                'camera_movement' => $row['camera_movement'],
                'lighting' => $row['lighting'],
                'mood' => $row['mood'],
                'review_status' => 'draft',
                'image_status' => 'none',
                'storyboard_settings' => [
                    'characters_in_shot' => $castNames,
                    'extras' => $row['extras'] ?? 'none',
                ],
            ]);
            if ($present->isNotEmpty()) {
                $shot->characters()->sync($present->pluck('id')->all());
            }
            $shot->setRelation('scene', $scene);
            $shot->setRelation('characters', $present);
            $created[] = $shot;
            $order++;
        }

        if ($created === []) {
            return response()->json([
                'success' => false,
                'message' => 'Gemini did not return any shots from the sequences.',
            ], 502);
        }

        $shots = collect($created);
        $project->loadCount(['scenes', 'shots']);
        $project->setRelation('shots', $shots);

        return response()->json([
            'success' => true,
            'shots' => $shots->map(fn (Shot $shot) => $this->serializeShot($shot))->values(),
            'project' => $this->detail($project),
        ]);
    }

    public function updateCharacter(
        UpdateCharacterRequest $request,
        Project $project,
        Character $character,
    ): JsonResponse {
        $this->authorizeProject($request, $project);
        abort_unless($character->project_id === $project->id, 404);

        $data = $request->validated();
        if (array_key_exists('age', $data) && ! array_key_exists('age_range', $data)) {
            $data['age_range'] = $data['age'];
        }
        unset($data['age']);

        $character->fill($data);
        $character->save();
        $character->load('assets');

        return response()->json([
            'success' => true,
            'character' => $this->serializeCharacter($character),
        ]);
    }

    public function generateCover(
        Request $request,
        Project $project,
        ProjectImageGenerator $images,
    ): JsonResponse {
        $this->authorizeProject($request, $project);
        set_time_limit(180);

        $force = $request->boolean('force');

        try {
            $result = $images->generateProjectCover($project, $force);
        } catch (GenerationFailedException $e) {
            return $this->generationFailed($e, $project);
        }

        $project->loadCount(['scenes', 'shots']);

        return response()->json([
            'success' => true,
            'skipped' => $result['skipped'],
            'generated' => $result['generated'],
            'cover_image_url' => $result['url'],
            'project' => $this->detail($project->fresh()),
        ]);
    }

    public function generateCharacterImage(
        Request $request,
        Project $project,
        Character $character,
        ProjectImageGenerator $images,
    ): JsonResponse {
        $this->authorizeProject($request, $project);
        abort_unless($character->project_id === $project->id, 404);
        set_time_limit(180);

        $force = $request->boolean('force');
        $character->load('assets');

        try {
            $result = $images->generateCharacterPortrait($project, $character, $force);
        } catch (GenerationFailedException $e) {
            return $this->generationFailed($e, $project);
        }

        return response()->json([
            'success' => true,
            'skipped' => $result['skipped'],
            'character' => $this->serializeCharacter($result['character']),
            'asset' => $result['asset'] ? $this->serializeCharacterAsset($result['asset']) : null,
        ]);
    }

    public function generateCharacterCostume(
        Request $request,
        Project $project,
        Character $character,
        ProjectImageGenerator $images,
    ): JsonResponse {
        $this->authorizeProject($request, $project);
        abort_unless($character->project_id === $project->id, 404);
        set_time_limit(180);

        $force = $request->boolean('force');
        $character->load('assets');

        try {
            $result = $images->generateCharacterCostumeSheet($project, $character, $force);
        } catch (GenerationFailedException $e) {
            return $this->generationFailed($e, $project);
        }

        return response()->json([
            'success' => true,
            'skipped' => $result['skipped'],
            'character' => $this->serializeCharacter($result['character']),
            'asset' => $result['asset'] ? $this->serializeCharacterAsset($result['asset']) : null,
        ]);
    }

    public function generateEnvironmentImage(
        Request $request,
        Project $project,
        Environment $environment,
        ProjectImageGenerator $images,
    ): JsonResponse {
        $this->authorizeProject($request, $project);
        abort_unless($environment->project_id === $project->id, 404);
        set_time_limit(180);

        $force = $request->boolean('force');
        $environment->load('assets');

        try {
            $result = $images->generateEnvironmentStill($project, $environment, $force);
        } catch (GenerationFailedException $e) {
            return $this->generationFailed($e, $project);
        }

        return response()->json([
            'success' => true,
            'skipped' => $result['skipped'],
            'environment' => $this->serializeEnvironment($result['environment']),
            'asset' => $result['asset'] ? $this->serializeEnvironmentAsset($result['asset']) : null,
        ]);
    }

    public function generateShotImage(
        Request $request,
        Project $project,
        Shot $shot,
        ProjectImageGenerator $images,
    ): JsonResponse {
        $this->authorizeProject($request, $project);
        abort_unless($shot->project_id === $project->id, 404);
        set_time_limit(180);

        $customPrompt = $request->input('custom_prompt') ?? $request->input('prompt');
        $customPrompt = is_string($customPrompt) ? trim($customPrompt) : null;
        $force = $request->boolean('force') || filled($customPrompt);
        $shot->load(['scene', 'images']);

        try {
            $result = $images->generateShotStill($project, $shot, $force, $customPrompt);
        } catch (GenerationFailedException $e) {
            return $this->generationFailed($e, $project);
        }

        return response()->json([
            'success' => true,
            'skipped' => $result['skipped'],
            'shot' => $this->serializeShot($result['shot']),
            'image' => $result['image'] ? $this->serializeShotImage($result['image']) : null,
        ]);
    }

    private function authorizeProject(Request $request, Project $project): void
    {
        abort_unless($project->user_id === $request->user()->id, 404);
    }

    private function ensureProjectCover(Project $project, ProjectImageGenerator $images): void
    {
        try {
            $images->generateProjectCover($project);
        } catch (\Throwable) {
            // Sequences must still save if the cover image fails.
        }
    }

    private function sourceStory(Project $project): ?string
    {
        $story = trim((string) ($project->story ?? ''));

        return $story !== '' ? $story : null;
    }

    private function sceneCoverUrlForProject(Project $project): ?string
    {
        return $this->sceneCoverUrlsByProjectId([(int) $project->id])[(int) $project->id] ?? null;
    }

    /**
     * @param  list<int|string>  $projectIds
     * @return array<int, string>
     */
    private function sceneCoverUrlsByProjectId(array $projectIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $projectIds))));
        if ($ids === []) {
            return [];
        }

        $rows = ShotImage::query()
            ->select([
                'shots.project_id',
                'shot_images.image_url',
            ])
            ->join('shots', 'shots.id', '=', 'shot_images.shot_id')
            ->join('scenes', 'scenes.id', '=', 'shots.scene_id')
            ->whereIn('shots.project_id', $ids)
            ->where('shot_images.status', 'completed')
            ->whereNotNull('shot_images.image_url')
            ->where('shot_images.image_url', '!=', '')
            ->orderBy('scenes.order_index')
            ->orderBy('shots.order_index')
            ->orderByDesc('shot_images.is_approved')
            ->orderByDesc('shot_images.version_number')
            ->get();

        $covers = [];
        foreach ($rows as $row) {
            $projectId = (int) $row->project_id;
            if (! isset($covers[$projectId]) && filled($row->image_url)) {
                $covers[$projectId] = $row->image_url;
            }
        }

        return $covers;
    }

    private function styleFromRequest(array $data, Project $project): ?string
    {
        return $project->generationStylePrompt(
            $data['style'] ?? $data['visual_style'] ?? null,
        );
    }

    private function attachBundledStylePreview(Project $project): void
    {
        if (filled($project->style_reference_url)) {
            return;
        }

        $variant = data_get($project->style_meta, 'variant');
        if (! is_string($variant) || $variant === '' || $variant === 'custom') {
            return;
        }

        $path = public_path('style-previews/'.$variant.'.png');
        if (! is_file($path)) {
            return;
        }

        $binary = file_get_contents($path);
        if (! is_string($binary) || $binary === '') {
            return;
        }

        $url = app(ProjectImageStore::class)->put(
            (int) $project->id,
            'style',
            'reference',
            $binary,
            'image/png',
        );

        $project->style_reference_url = $url;
        $project->save();
    }

    public function storeStyleReference(Request $request, Project $project, ProjectImageStore $store): JsonResponse
    {
        $this->authorizeProject($request, $project);

        $validated = $request->validate([
            'image' => ['required', 'file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:8192'],
        ]);

        $file = $validated['image'];
        $binary = file_get_contents($file->getRealPath());
        if (! is_string($binary) || $binary === '') {
            return response()->json([
                'success' => false,
                'message' => 'The style image could not be read.',
            ], 422);
        }

        $url = $store->put(
            (int) $project->id,
            'style',
            'reference',
            $binary,
            $file->getMimeType() ?: 'image/png',
        );

        $project->style_reference_url = $url;
        $project->save();

        return response()->json([
            'success' => true,
            'project' => $this->detail($project->fresh()),
        ]);
    }

    private function attributesFromRequest(array $data): array
    {
        $attributes = [];

        if (array_key_exists('title', $data) && filled($data['title'])) {
            $attributes['title'] = trim($data['title']);
        }

        $style = $data['style'] ?? $data['visual_style'] ?? null;
        if (filled($style)) {
            $attributes['style'] = trim((string) $style);
        }

        if (array_key_exists('style_prompt', $data)) {
            $prompt = $data['style_prompt'];
            $attributes['style_prompt'] = filled($prompt) ? trim((string) $prompt) : null;
        }

        if (array_key_exists('style_meta', $data)) {
            $attributes['style_meta'] = is_array($data['style_meta']) ? $data['style_meta'] : null;
        }

        if (array_key_exists('style_reference_url', $data)) {
            $attributes['style_reference_url'] = filled($data['style_reference_url'])
                ? trim((string) $data['style_reference_url'])
                : null;
        }

        $story = $data['story'] ?? $data['story_text'] ?? null;
        if (array_key_exists('story', $data) || array_key_exists('story_text', $data)) {
            $attributes['story'] = $story;
        }

        foreach (['script', 'screenplay', 'current_step', 'status', 'cover_image_url'] as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = $data[$field];
            }
        }

        return $attributes;
    }

    private function summary(Project $project, ?string $sceneCoverUrl = null): array
    {
        $story = $project->story ?? '';

        return [
            'id' => $project->id,
            'title' => $project->title,
            'story' => $story,
            'story_preview' => mb_substr(trim($story), 0, 160),
            'style' => $project->style,
            'current_step' => $project->current_step,
            'status' => $project->status,
            'cover_image_url' => $project->cover_image_url
                ?: $sceneCoverUrl
                ?: $project->style_reference_url,
            'scenes_count' => $project->scenes_count ?? 0,
            'shots_count' => $project->shots_count ?? 0,
            'generated_images_count' => 0,
            'updated_at' => optional($project->updated_at)?->toIso8601String(),
        ];
    }

    private function detail(Project $project): array
    {
        $style = $project->style;

        return [
            'id' => $project->id,
            'title' => $project->title,
            'story' => $project->story,
            'script' => $project->script,
            'screenplay' => $project->screenplay,
            'style' => $style,
            'visual_style' => $style,
            'style_prompt' => $project->style_prompt,
            'style_meta' => $project->style_meta,
            'style_reference_url' => $project->style_reference_url,
            'current_step' => $project->current_step,
            'status' => $project->status,
            'cover_image_url' => $project->cover_image_url ?: $this->sceneCoverUrlForProject($project),
            'meta' => [
                'visual_style' => $style,
                'style_prompt' => $project->style_prompt,
                'style_meta' => $project->style_meta,
                'style_reference_url' => $project->style_reference_url,
            ],
            'scenes' => $this->serializeScenes($project),
            'shots' => $this->serializeShots($project),
            'characters' => $this->serializeCharacters($project),
            'environments' => $this->serializeEnvironments($project),
            'objects' => [],
            'episodes' => $this->serializeEpisodes($project),
            'scenes_count' => $project->scenes_count ?? 0,
            'shots_count' => $project->shots_count ?? 0,
            'updated_at' => optional($project->updated_at)?->toIso8601String(),
        ];
    }

    private function serializeScenes(Project $project): array
    {
        $scenes = $project->relationLoaded('scenes')
            ? $project->scenes
            : $project->scenes()->orderBy('order_index')->get();

        return $scenes->map(fn (Scene $scene) => $this->serializeScene($scene))->values()->all();
    }

    private function serializeScene(Scene $scene): array
    {
        return [
            'id' => $scene->id,
            'scene_number' => $scene->scene_number,
            'order_index' => $scene->order_index,
            'title' => $scene->title,
            'location' => $scene->location,
            'time_of_day' => $scene->time_of_day,
            'description' => $scene->description,
            'mood' => $scene->mood,
            'visual_style' => $scene->visual_style,
            'status' => $scene->status,
            'generation_error' => $this->publicErrorMessage($scene->generation_error),
            'generated_at' => optional($scene->generated_at)?->toIso8601String(),
        ];
    }

    private function serializeCharacters(Project $project): array
    {
        $characters = $project->relationLoaded('characters')
            ? $project->characters
            : $project->characters()->orderBy('order_index')->get();

        return $characters->map(fn (Character $character) => $this->serializeCharacter($character))->values()->all();
    }

    private function serializeCharacter(Character $character): array
    {
        $assets = $character->relationLoaded('assets')
            ? $character->assets
            : $character->assets()->get();
        $portrait = $assets->first(
            fn (CharacterAsset $asset) => $asset->asset_type === 'portrait' && filled($asset->image_url)
        ) ?? $assets->first(
            fn (CharacterAsset $asset) => $asset->is_primary && filled($asset->image_url)
        );
        $costume = $assets->first(
            fn (CharacterAsset $asset) => $asset->asset_type === 'costume' && filled($asset->image_url)
        );

        return [
            'id' => $character->id,
            'order_index' => $character->order_index,
            'name' => $character->name,
            'role' => $character->role,
            'gender' => $character->gender,
            'age_range' => $character->age_range,
            'age' => $character->age_range,
            'ethnicity' => $character->ethnicity,
            'description' => $character->description,
            'personality' => $character->personality,
            'appearance' => $character->appearance,
            'wardrobe' => $character->wardrobe,
            'importance' => $character->importance,
            'status' => $character->status,
            'image_url' => $portrait?->image_url,
            'costume_image_url' => $costume?->image_url,
            'image_status' => filled($portrait?->image_url) ? 'completed' : $character->image_status,
            'prompt' => $character->prompt,
            'assets' => $assets->map(fn (CharacterAsset $asset) => $this->serializeCharacterAsset($asset))->values()->all(),
            'updated_at' => optional($character->updated_at)?->toIso8601String(),
        ];
    }

    private function serializeCharacterAsset(CharacterAsset $asset): array
    {
        return [
            'id' => $asset->id,
            'asset_type' => $asset->asset_type,
            'title' => $asset->title,
            'image_url' => $asset->image_url,
            'is_primary' => $asset->is_primary,
            'status' => $asset->status,
            'updated_at' => optional($asset->updated_at)?->toIso8601String(),
        ];
    }

    private function serializeEnvironments(Project $project): array
    {
        $environments = $project->relationLoaded('environments')
            ? $project->environments
            : $project->environments()->with('assets')->orderBy('order_index')->get();

        return $environments->map(fn (Environment $environment) => $this->serializeEnvironment($environment))->values()->all();
    }

    private function serializeEnvironment(Environment $environment): array
    {
        $assets = $environment->relationLoaded('assets')
            ? $environment->assets
            : $environment->assets()->get();
        $primary = $assets->first(
            fn (EnvironmentAsset $asset) => $asset->is_primary && filled($asset->image_url)
        ) ?? $assets->first(fn (EnvironmentAsset $asset) => filled($asset->image_url));

        return [
            'id' => $environment->id,
            'order_index' => $environment->order_index,
            'name' => $environment->name,
            'type' => $environment->type,
            'time_of_day' => $environment->time_of_day,
            'description' => $environment->description,
            'appearance' => $environment->appearance,
            'lighting' => $environment->lighting,
            'mood' => $environment->mood,
            'importance' => $environment->importance,
            'status' => $environment->status,
            'image_url' => $primary?->image_url,
            'image_status' => filled($primary?->image_url) ? 'completed' : $environment->image_status,
            'prompt' => $environment->prompt,
            'assets' => $assets->map(fn (EnvironmentAsset $asset) => $this->serializeEnvironmentAsset($asset))->values()->all(),
            'updated_at' => optional($environment->updated_at)?->toIso8601String(),
        ];
    }

    private function serializeEnvironmentAsset(EnvironmentAsset $asset): array
    {
        return [
            'id' => $asset->id,
            'asset_type' => $asset->asset_type,
            'title' => $asset->title,
            'image_url' => $asset->image_url,
            'is_primary' => $asset->is_primary,
            'status' => $asset->status,
            'updated_at' => optional($asset->updated_at)?->toIso8601String(),
        ];
    }

    private function serializeShots(Project $project): array
    {
        $shots = $project->relationLoaded('shots')
            ? $project->shots
            : $project->shots()->with(['scene', 'images'])->orderBy('order_index')->get();

        return $shots->map(fn (Shot $shot) => $this->serializeShot($shot))->values()->all();
    }

    private function serializeShot(Shot $shot): array
    {
        $images = $shot->relationLoaded('images')
            ? $shot->images
            : $shot->images()->get();
        $latest = $images
            ->filter(fn (ShotImage $image) => $image->status === 'completed' && filled($image->image_url))
            ->sortByDesc('version_number')
            ->first();

        return [
            'id' => $shot->id,
            'scene_id' => $shot->scene_id,
            'scene_number' => $shot->scene?->scene_number,
            'environment_id' => $shot->environment_id,
            'shot_number' => $shot->shot_number,
            'order_index' => $shot->order_index,
            'title' => $shot->title,
            'description' => $shot->description,
            'action' => $shot->action,
            'dialogue' => $shot->dialogue,
            'shot_size' => $shot->shot_size,
            'camera_angle' => $shot->camera_angle,
            'camera_movement' => $shot->camera_movement,
            'lighting' => $shot->lighting,
            'mood' => $shot->mood,
            'prompt' => $shot->prompt,
            'image_url' => $latest?->image_url,
            'image_status' => filled($latest?->image_url) ? 'completed' : $shot->image_status,
            'review_status' => $shot->review_status,
            'shot_images' => $images->map(fn (ShotImage $image) => $this->serializeShotImage($image))->values()->all(),
            'updated_at' => optional($shot->updated_at)?->toIso8601String(),
        ];
    }

    private function serializeShotImage(ShotImage $image): array
    {
        return [
            'id' => $image->id,
            'shot_id' => $image->shot_id,
            'version_number' => $image->version_number,
            'image_url' => $image->image_url,
            'thumbnail_url' => $image->thumbnail_url,
            'prompt' => $image->prompt,
            'is_approved' => $image->is_approved,
            'status' => $image->status,
            'updated_at' => optional($image->updated_at)?->toIso8601String(),
        ];
    }

    private function serializeEpisodes(Project $project): array
    {
        $episodes = $project->relationLoaded('episodes')
            ? $project->episodes
            : $project->episodes()->orderBy('episode_number')->get();

        return $episodes->map(fn (Episode $episode) => $this->serializeEpisode($episode))->values()->all();
    }

    private function serializeEpisode(Episode $episode): array
    {
        return [
            'id' => $episode->id,
            'episode_number' => $episode->episode_number,
            'title' => $episode->title,
            'summary' => $episode->summary,
            'screenplay' => $episode->screenplay,
            'status' => $episode->status,
        ];
    }

    private function publicErrorMessage(?string $message): ?string
    {
        if ($message === null || trim($message) === '') {
            return null;
        }

        return app(SystemErrorLogger::class)->userMessageFromString($message);
    }

    private function storyTooLongPayload(string $story): array
    {
        $words = StoryLength::wordCount($story);
        $episodes = StoryLength::estimatedEpisodeCount($story);

        return [
            'success' => false,
            'code' => 'story_too_long',
            'message' => "Your story is too long for a single screenplay. We need to divide it into {$episodes} episodes.",
            'word_count' => $words,
            'max_words' => StoryLength::MAX_SINGLE_SCREENPLAY_WORDS,
            'estimated_episodes' => $episodes,
        ];
    }
}
