<?php

namespace App\Services;

class ProjectTextGenerator
{
    public function __construct(private GeminiClient $gemini)
    {
    }

    public function screenplayFromStory(string $story, ?string $style = null): string
    {
        $styleLine = $this->styleLine($style);

        $fidelity = $this->fidelityRules('story');

        $prompt = <<<PROMPT
You are a formatter. Convert the story below into screenplay layout. Do not rewrite it.

Rules:
- Write in the same language as the story.
{$fidelity}
- Format only: FADE IN, INT./EXT. headings, action lines, CHARACTER names, dialogue, FADE OUT.
- Use only dialogue that already appears in the story. If there is no dialogue, write action only.
- Use the visual style only as look/atmosphere. It must not change the story.
- Output plain text only. No markdown, no title page, no commentary.
{$styleLine}

STORY:
{$story}
PROMPT;

        return $this->gemini->generateText($prompt, 16384);
    }

    /**
     * @return list<array{episode_number: int, title: string, summary: string}>
     */
    public function episodePlanFromStory(string $story, int $targetCount, ?string $style = null): array
    {
        $styleLine = $this->styleLine($style);
        $count = max(2, min(12, $targetCount));

        $fidelity = $this->fidelityRules('story');

        $prompt = <<<PROMPT
You are a series editor. Split the story below into {$count} episodes. Do not rewrite it.

Rules:
- Keep the same language as the story.
{$fidelity}
- Cover the full plot across the episodes, in the same order. Do not add filler episodes or new acts.
- title: short title from events that already happen.
- summary: 2-4 sentences of what already happens in that slice only.
- Output JSON only: {"episodes":[{"episode_number":1,"title":"...","summary":"..."}]}
{$styleLine}

STORY:
{$story}
PROMPT;

        $payload = $this->gemini->generateJson($prompt, 4096);
        $rows = [];
        if (isset($payload['episodes']) && is_array($payload['episodes'])) {
            $rows = $payload['episodes'];
        } elseif (array_is_list($payload)) {
            $rows = $payload;
        }

        $episodes = [];
        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $title = trim((string) ($row['title'] ?? ''));
            $summary = trim((string) ($row['summary'] ?? ''));
            if ($title === '' && $summary === '') {
                continue;
            }

            $episodes[] = [
                'episode_number' => $index + 1,
                'title' => $title !== '' ? $title : 'Episode '.($index + 1),
                'summary' => $summary !== '' ? $summary : 'Continues the story.',
            ];
        }

        if (count($episodes) < 2) {
            throw new \App\Exceptions\GenerationFailedException(
                'Gemini did not return enough episodes for this story.',
            );
        }

        return $episodes;
    }

    public function screenplayForEpisode(
        string $story,
        array $episode,
        array $previousEpisodes = [],
        ?string $style = null,
    ): string {
        $styleLine = $this->styleLine($style);
        $number = (int) ($episode['episode_number'] ?? 1);
        $title = trim((string) ($episode['title'] ?? 'Episode '.$number));
        $summary = trim((string) ($episode['summary'] ?? ''));

        $previous = '';
        foreach ($previousEpisodes as $prior) {
            $priorNumber = (int) ($prior['episode_number'] ?? 0);
            $priorTitle = trim((string) ($prior['title'] ?? ''));
            $priorSummary = trim((string) ($prior['summary'] ?? ''));
            $previous .= "- Episode {$priorNumber}: {$priorTitle}. {$priorSummary}\n";
        }

        $previousBlock = $previous !== ''
            ? "What already happened:\n{$previous}"
            : 'This is the first episode.';

        $fidelity = $this->fidelityRules('story');

        $prompt = <<<PROMPT
You are a formatter. Write ONLY episode {$number} by converting that slice of the story into screenplay layout. Do not rewrite it.

Episode title: {$title}
Episode summary: {$summary}

Rules:
- Write in the same language as the story.
{$fidelity}
- Cover only the events in this episode summary. Do not write later episodes or invent bridges.
- Use only dialogue that already appears in the story for these events.
- Format only: FADE IN, INT./EXT. headings, action lines, CHARACTER names, dialogue, FADE OUT.
- Output plain text only. No markdown, no title page, no commentary.
{$styleLine}

{$previousBlock}

FULL STORY (source of truth):
{$story}
PROMPT;

        return $this->gemini->generateText($prompt, 8192);
    }

    public function scriptFromStory(string $story, ?string $style = null): string
    {
        $styleLine = $this->styleLine($style);

        $fidelity = $this->fidelityRules('story');

        $prompt = <<<PROMPT
You are a formatter. Convert the story below into a readable film/TV script. Do not rewrite it.

Rules:
- Write in the same language as the story.
{$fidelity}
- Output scene headings (INT./EXT.), action lines, and only dialogue that already appears in the story.
- Use the visual style only as look/atmosphere. It must not change the story.
- Output plain text only. No markdown, no title page, no commentary.
{$styleLine}

STORY:
{$story}
PROMPT;

        return $this->gemini->generateText($prompt);
    }

    /**
     * @return list<array{title: string, location: ?string, time_of_day: ?string, description: ?string, mood: ?string}>
     */
    public function scenesFromScreenplay(string $screenplay, ?string $style = null, ?string $sourceStory = null): array
    {
        $styleLine = $this->styleLine($style);
        $fidelity = $this->fidelityRules('screenplay');
        $storyLock = $this->sourceStoryBlock($sourceStory);

        $prompt = <<<PROMPT
You are a storyboard supervisor. Extract visual sequences from the screenplay. Do not invent story.

Rules:
- Keep the same language as the screenplay.
{$fidelity}
- Do not divide sequences based only on location or time changes (INT./EXT. headings).
- Create a new sequence whenever there is a major action, emotional shift, decision, problem, or reveal that is already in the screenplay.
- One location or time block may contain several sequences. A new heading does not always start a new sequence.
- Cover every event from the screenplay, in order. Do not omit actions and do not add new ones.
- Use as many sequences as the events require. Do not pad to a count and do not compress away events.
- title: short sequence title from what happens.
- location: place named in the screenplay (no INT./EXT.).
- time_of_day: only if stated or clearly implied; otherwise omit.
- description: 2-5 sentences of what already happens — who does what. No camera jargon. No new motives.
- mood: one short mood word from the scene, or omit.
- Output JSON only: {"scenes":[...]} with those keys on each sequence.
{$styleLine}
{$storyLock}

SCREENPLAY:
{$screenplay}
PROMPT;

        $payload = $this->gemini->generateJson($prompt, 8192);
        $rows = [];
        if (array_is_list($payload)) {
            $rows = $payload;
        } elseif (isset($payload['scenes']) && is_array($payload['scenes'])) {
            $rows = $payload['scenes'];
        } elseif (isset($payload['sequences']) && is_array($payload['sequences'])) {
            $rows = $payload['sequences'];
        }

        $scenes = [];
        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $title = trim((string) ($row['title'] ?? ''));
            $description = trim((string) ($row['description'] ?? ''));
            if ($title === '' && $description === '') {
                continue;
            }

            $scenes[] = [
                'title' => $title !== '' ? $title : 'Sequence '.($index + 1),
                'location' => $this->nullableString($row['location'] ?? null),
                'time_of_day' => $this->nullableString($row['time_of_day'] ?? $row['timeOfDay'] ?? null),
                'description' => $description !== '' ? $description : null,
                'mood' => $this->nullableString($row['mood'] ?? null),
            ];
        }

        if ($scenes === []) {
            throw new \App\Exceptions\GenerationFailedException(
                'Gemini did not return any sequences from the screenplay.',
            );
        }

        return $scenes;
    }

    /**
     * @param  list<array{title?: string, description?: string}>  $sequences
     * @return list<array{name: string, role: ?string, gender: ?string, age_range: ?string, ethnicity: ?string, description: ?string, personality: ?string, appearance: ?string, wardrobe: ?string, importance: ?string}>
     */
    public function charactersFromScreenplay(string $screenplay, ?string $style = null, array $sequences = [], ?string $sourceStory = null): array
    {
        $styleLine = $this->styleLine($style);
        $sequenceBlock = $this->sequenceContext($sequences);
        $fidelity = $this->fidelityRules('screenplay');
        $storyLock = $this->sourceStoryBlock($sourceStory);

        $prompt = <<<PROMPT
You are a casting director. Extract the character list. Do not invent people or looks.

Rules:
- Keep the same language as the screenplay.
{$fidelity}
- Include named speaking characters and any unnamed character who already drives a plot beat.
- Skip background extras who only appear in a crowd or one passing mention.
- List only characters who appear. Do not pad to a count.
- name: character name as written (or a short label like "THE KNOCKER" if unnamed).
- role: protagonist, antagonist, supporting, or minor — only if clear from the text.
- gender, age_range, ethnicity: only if stated or clearly implied; otherwise omit.
- description: 1-3 sentences from what the text already says they do. Do not invent wants or backstory.
- personality: only traits shown in the text; otherwise omit.
- appearance: only visible details stated in the text. If none, omit.
- wardrobe: only clothing stated in the text. If none, omit. Do not invent a costume.
- importance: lead, supporting, or minor.
- Output JSON only: {"characters":[...]} with those keys on each character.
{$styleLine}
{$storyLock}
{$sequenceBlock}

SCREENPLAY:
{$screenplay}
PROMPT;

        $payload = $this->gemini->generateJson($prompt, 4096);
        $rows = [];
        if (array_is_list($payload)) {
            $rows = $payload;
        } elseif (isset($payload['characters']) && is_array($payload['characters'])) {
            $rows = $payload['characters'];
        }

        $characters = [];
        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $characters[] = [
                'name' => $name,
                'role' => $this->nullableString($row['role'] ?? null),
                'gender' => $this->nullableString($row['gender'] ?? null),
                'age_range' => $this->nullableString($row['age_range'] ?? $row['ageRange'] ?? $row['age'] ?? null),
                'ethnicity' => $this->nullableString($row['ethnicity'] ?? null),
                'description' => $this->nullableString($row['description'] ?? null),
                'personality' => $this->nullableString($row['personality'] ?? null),
                'appearance' => $this->nullableString($row['appearance'] ?? null),
                'wardrobe' => $this->nullableString($row['wardrobe'] ?? $row['clothing'] ?? null),
                'importance' => $this->nullableString($row['importance'] ?? null) ?? 'supporting',
            ];
        }

        if ($characters === []) {
            throw new \App\Exceptions\GenerationFailedException(
                'Gemini did not return any characters from the screenplay.',
            );
        }

        return $characters;
    }

    /**
     * @param  list<array{title?: string, description?: string, location?: string}>  $sequences
     * @return list<array{name: string, type: ?string, time_of_day: ?string, description: ?string, appearance: ?string, lighting: ?string, mood: ?string, importance: ?string}>
     */
    public function environmentsFromScreenplay(string $screenplay, ?string $style = null, array $sequences = [], ?string $sourceStory = null): array
    {
        $styleLine = $this->styleLine($style);
        $sequenceBlock = $this->sequenceContext($sequences);
        $fidelity = $this->fidelityRules('screenplay');
        $storyLock = $this->sourceStoryBlock($sourceStory);

        $prompt = <<<PROMPT
You are a production designer. Extract the places that already appear. Do not invent locations.

Rules:
- Keep the same language as the screenplay.
{$fidelity}
- One object per unique place. Merge the same location at different times into one environment unless the place itself changes.
- List only places that appear. Do not pad to a count.
- name: place name as written (no INT./EXT.).
- type: interior, exterior, or mixed — only if clear.
- time_of_day: only if stated or clearly implied; otherwise omit.
- description: 1-3 sentences of how the place is used in the text. No new history.
- appearance: only architecture, colors, or materials stated in the text. If none, omit.
- lighting / mood: only if stated or clearly implied; otherwise omit.
- importance: primary, supporting, or minor.
- Output JSON only: {"environments":[...]} with those keys on each environment.
{$styleLine}
{$storyLock}
{$sequenceBlock}

SCREENPLAY:
{$screenplay}
PROMPT;

        $payload = $this->gemini->generateJson($prompt, 4096);
        $rows = [];
        if (array_is_list($payload)) {
            $rows = $payload;
        } elseif (isset($payload['environments']) && is_array($payload['environments'])) {
            $rows = $payload['environments'];
        }

        $environments = [];
        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $environments[] = [
                'name' => $name,
                'type' => $this->nullableString($row['type'] ?? null),
                'time_of_day' => $this->nullableString($row['time_of_day'] ?? $row['timeOfDay'] ?? null),
                'description' => $this->nullableString($row['description'] ?? null),
                'appearance' => $this->nullableString($row['appearance'] ?? null),
                'lighting' => $this->nullableString($row['lighting'] ?? null),
                'mood' => $this->nullableString($row['mood'] ?? null),
                'importance' => $this->nullableString($row['importance'] ?? null) ?? 'supporting',
            ];
        }

        if ($environments === []) {
            throw new \App\Exceptions\GenerationFailedException(
                'Gemini did not return any environments from the screenplay.',
            );
        }

        return $environments;
    }

    /**
     * @param  list<array{scene_number?: int, title?: string, description?: string, location?: string}>  $sequences
     * @return list<array{scene_number: int, title: string, description: ?string, action: ?string, dialogue: ?string, shot_size: ?string, camera_angle: ?string, camera_movement: ?string, lighting: ?string, mood: ?string, environment: ?string}>
     */
    public function shotsFromSequences(string $screenplay, array $sequences, ?string $style = null, ?string $sourceStory = null): array
    {
        $styleLine = $this->styleLine($style);
        $sequenceBlock = $this->numberedSequenceContext($sequences);
        $fidelity = $this->fidelityRules('screenplay and sequences');
        $storyLock = $this->sourceStoryBlock($sourceStory);

        $prompt = <<<PROMPT
You are a director. Cover the sequences with storyboard shots. Do not generate images. Do not invent story.

Rules:
- Keep the same language as the screenplay.
{$fidelity}
- Create only as many shots as needed to show the action already in that sequence (usually 2-6). Do not add extra beats.
- Every sequence listed must have at least one shot. Use that sequence's scene_number.
- title: short shot title from what happens.
- description: what we already see in that beat.
- action: the action already written. No new business.
- dialogue: copy a spoken line only if it already exists; otherwise omit.
- shot_size, camera_angle, camera_movement: coverage only. These must not change the story.
- lighting, mood, environment: from the sequence/screenplay only.
- Output JSON only: {"shots":[...]} with scene_number and those keys on each shot.
{$styleLine}
{$storyLock}
{$sequenceBlock}

SCREENPLAY:
{$screenplay}
PROMPT;

        $payload = $this->gemini->generateJson($prompt, 8192);
        $rows = [];
        if (array_is_list($payload)) {
            $rows = $payload;
        } elseif (isset($payload['shots']) && is_array($payload['shots'])) {
            $rows = $payload['shots'];
        }

        $shots = [];
        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $title = trim((string) ($row['title'] ?? ''));
            $description = trim((string) ($row['description'] ?? $row['action'] ?? ''));
            if ($title === '' && $description === '') {
                continue;
            }

            $shots[] = [
                'scene_number' => (int) ($row['scene_number'] ?? $row['sequence_number'] ?? 0),
                'title' => $title !== '' ? $title : 'Shot '.($index + 1),
                'description' => $description !== '' ? $description : null,
                'action' => $this->nullableString($row['action'] ?? null),
                'dialogue' => $this->nullableString($row['dialogue'] ?? null),
                'shot_size' => $this->nullableString($row['shot_size'] ?? $row['shotSize'] ?? null),
                'camera_angle' => $this->nullableString($row['camera_angle'] ?? $row['cameraAngle'] ?? null),
                'camera_movement' => $this->nullableString($row['camera_movement'] ?? $row['cameraMovement'] ?? null),
                'lighting' => $this->nullableString($row['lighting'] ?? null),
                'mood' => $this->nullableString($row['mood'] ?? null),
                'environment' => $this->nullableString($row['environment'] ?? $row['location'] ?? null),
            ];
        }

        if ($shots === []) {
            throw new \App\Exceptions\GenerationFailedException(
                'Gemini did not return any shots from the sequences.',
            );
        }

        return $shots;
    }

    public function screenplayFromScript(string $script, ?string $style = null): string
    {
        $styleLine = $this->styleLine($style);

        $fidelity = $this->fidelityRules('script');

        $prompt = <<<PROMPT
You are a formatter. Put the draft script into screenplay layout. Do not rewrite it.

Rules:
- Write in the same language as the script.
{$fidelity}
- Keep the same scenes, characters, action, and dialogue. Change formatting only.
- Format: FADE IN, INT./EXT. headings, action, CHARACTER names, dialogue, FADE OUT.
- Output plain text only. No markdown, no title page, no commentary.
{$styleLine}

SCRIPT:
{$script}
PROMPT;

        return $this->gemini->generateText($prompt);
    }

    /**
     * @param  list<array{title?: string, description?: string}>  $sequences
     */
    private function sequenceContext(array $sequences): string
    {
        $lines = '';
        foreach ($sequences as $index => $sequence) {
            if (! is_array($sequence)) {
                continue;
            }

            $title = trim((string) ($sequence['title'] ?? ''));
            $description = trim((string) ($sequence['description'] ?? ''));
            if ($title === '' && $description === '') {
                continue;
            }

            $number = $index + 1;
            $label = $title !== '' ? $title : "Sequence {$number}";
            $lines .= "- {$label}";
            if ($description !== '') {
                $lines .= ": {$description}";
            }
            $lines .= "\n";
        }

        if ($lines === '') {
            return '';
        }

        return "Visual sequences (for who appears where):\n{$lines}";
    }

    /**
     * @param  list<array{scene_number?: int, title?: string, description?: string, location?: string}>  $sequences
     */
    private function numberedSequenceContext(array $sequences): string
    {
        $lines = '';
        foreach ($sequences as $index => $sequence) {
            if (! is_array($sequence)) {
                continue;
            }

            $number = (int) ($sequence['scene_number'] ?? $index + 1);
            $title = trim((string) ($sequence['title'] ?? ''));
            $location = trim((string) ($sequence['location'] ?? ''));
            $description = trim((string) ($sequence['description'] ?? ''));
            $label = $title !== '' ? $title : "Sequence {$number}";
            $lines .= "{$number}. {$label}";
            if ($location !== '') {
                $lines .= " ({$location})";
            }
            if ($description !== '') {
                $lines .= ": {$description}";
            }
            $lines .= "\n";
        }

        if ($lines === '') {
            return '';
        }

        return "SEQUENCES:\n{$lines}";
    }

    private function fidelityRules(string $sourceLabel): string
    {
        return <<<RULES
- The {$sourceLabel} is the only source of plot, people, places, events, and spoken words.
- Do not add plot, characters, locations, objects, motives, backstory, or dialogue that is not in the {$sourceLabel}.
- Do not remove, merge, reorder, or replace events, characters, or spoken lines from the {$sourceLabel}.
- If a detail is missing (costume, age, ethnicity, backstory), omit it or write unspecified. Do not invent it.
- Use the same names and the same order of events.
RULES;
    }

    private function sourceStoryBlock(?string $story): string
    {
        $trimmed = trim((string) $story);
        if ($trimmed === '') {
            return '';
        }

        return "ORIGINAL STORY (source of truth — if later text disagrees, follow this story):\n{$trimmed}\n";
    }

    private function styleLine(?string $style): string
    {
        $trimmed = trim((string) $style);

        return $trimmed !== ''
            ? "Visual style (look only, not story): {$trimmed}. Do not use style to add plot, characters, or dialogue."
            : '';
    }

    private function nullableString(mixed $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
