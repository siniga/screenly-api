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

        $prompt = <<<PROMPT
You are a professional screenwriter. Turn the story below into a proper film/TV screenplay.

Rules:
- Write in the same language as the story.
- Keep the plot, characters, tone, and setting from the story. Do not invent a different story.
- Use standard screenplay layout: FADE IN, INT./EXT. scene headings, action lines, CHARACTER names, dialogue, FADE OUT.
- Use the visual style only as tone/atmosphere, not as camera jargon dumps.
- Output plain text only. No markdown, no title page, no commentary before or after the screenplay.
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

        $prompt = <<<PROMPT
You are a series showrunner. Split the story below into {$count} episodes.

Rules:
- Keep the same language as the story.
- Do not invent a different story. Cover the full plot across the episodes.
- Each episode should be one short-film act: about 8-12 scenes, under 15 pages.
- title: short episode title.
- summary: 2-4 sentences of what happens in that episode only.
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

        $prompt = <<<PROMPT
You are a professional screenwriter. Write ONLY episode {$number} as a proper film/TV screenplay.

Episode title: {$title}
Episode summary: {$summary}

Rules:
- Write in the same language as the story.
- Keep the plot, characters, tone, and setting from the story. Do not invent a different story.
- Cover only this episode. Do not write later episodes.
- Keep it short enough to generate reliably: 8-12 scenes, under 15 pages.
- Use standard screenplay layout: FADE IN, INT./EXT. scene headings, action lines, CHARACTER names, dialogue, FADE OUT.
- Output plain text only. No markdown, no title page, no commentary.
{$styleLine}

{$previousBlock}

FULL STORY (for continuity):
{$story}
PROMPT;

        return $this->gemini->generateText($prompt, 8192);
    }

    public function scriptFromStory(string $story, ?string $style = null): string
    {
        $styleLine = $this->styleLine($style);

        $prompt = <<<PROMPT
You are a professional screenwriter. Turn the story below into a readable film/TV script.

Rules:
- Write in the same language as the story.
- Keep the plot, characters, tone, and setting from the story. Do not invent a different story.
- Output a script with scene headings (INT./EXT.), brief action lines, and character dialogue.
- Use the visual style only as tone/atmosphere, not as camera jargon dumps.
- Output plain text only. No markdown, no title page, no commentary before or after the script.
{$styleLine}

STORY:
{$story}
PROMPT;

        return $this->gemini->generateText($prompt);
    }

    /**
     * @return list<array{title: string, location: ?string, time_of_day: ?string, description: ?string, mood: ?string}>
     */
    public function scenesFromScreenplay(string $screenplay, ?string $style = null): array
    {
        $styleLine = $this->styleLine($style);

        $prompt = <<<PROMPT
You are a storyboard supervisor. Break the screenplay below into visual story sequences suitable for generating a storyboard.

Rules:
- Keep the same language as the screenplay.
- Do not divide sequences based only on location or time changes (INT./EXT. headings).
- Create a new sequence whenever there is a major action, emotional shift, decision, problem, or reveal.
- One location or time block may contain several sequences. A new heading does not always start a new sequence.
- Every important event from the screenplay must appear in at least one sequence. Do not invent plot that is not in the screenplay.
- Do not over-summarize or omit actions. Cover the complete screenplay from beginning to end, in order.
- Return enough sequences to represent the whole story, typically 8-20 for an episode-length screenplay.
- title: short sequence title.
- location: place where this sequence mostly happens (no INT./EXT.).
- time_of_day: DAY, NIGHT, DAWN, DUSK, or similar.
- description: 2-5 sentences of what happens — who does what, what changes. No camera jargon.
- mood: one short mood word or phrase.
- Output JSON only: {"scenes":[...]} with those keys on each sequence.
{$styleLine}

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
    public function charactersFromScreenplay(string $screenplay, ?string $style = null, array $sequences = []): array
    {
        $styleLine = $this->styleLine($style);
        $sequenceBlock = $this->sequenceContext($sequences);

        $prompt = <<<PROMPT
You are a casting director. Extract the character list from the screenplay below.

Rules:
- Keep the same language as the screenplay.
- Include named speaking characters and any unnamed character who drives a plot beat.
- Do not invent people who are not in the screenplay.
- Skip background extras who only appear in a crowd or one passing mention.
- Typically 4-12 characters. Lead first, then supporting, then minor.
- name: character name as written (or a short label like "THE KNOCKER" if unnamed).
- role: protagonist, antagonist, supporting, or minor.
- gender: as implied, or unknown.
- age_range: short, e.g. 30s, teen, elderly.
- ethnicity: only if the screenplay states or clearly implies it; otherwise omit.
- description: 1-3 sentences of who they are and what they want in this story.
- personality: a few traits.
- appearance: visible look from the screenplay, no camera jargon.
- wardrobe: clothing if mentioned.
- importance: lead, supporting, or minor.
- Output JSON only: {"characters":[...]} with those keys on each character.
{$styleLine}
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
    public function environmentsFromScreenplay(string $screenplay, ?string $style = null, array $sequences = []): array
    {
        $styleLine = $this->styleLine($style);
        $sequenceBlock = $this->sequenceContext($sequences);

        $prompt = <<<PROMPT
You are a production designer. Extract the distinct environments (places) needed to film the screenplay below.

Rules:
- Keep the same language as the screenplay.
- One object per unique place. Merge the same location at different times into one environment unless the place itself changes.
- Do not invent locations that do not appear in the screenplay or sequences.
- Typically 3-10 environments. Most important places first.
- name: place name (no INT./EXT.).
- type: interior, exterior, or mixed.
- time_of_day: the time it is mostly seen, or mixed.
- description: 1-3 sentences of what this place is and how it is used.
- appearance: visible look, architecture, colors, materials. No camera jargon.
- lighting: natural light, practicals, mood lighting, etc.
- mood: one short mood word or phrase.
- importance: primary, supporting, or minor.
- Output JSON only: {"environments":[...]} with those keys on each environment.
{$styleLine}
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
    public function shotsFromSequences(string $screenplay, array $sequences, ?string $style = null): array
    {
        $styleLine = $this->styleLine($style);
        $sequenceBlock = $this->numberedSequenceContext($sequences);

        $prompt = <<<PROMPT
You are a director. Break the visual sequences below into storyboard shots. Do not generate images. Describe shots only.

Rules:
- Keep the same language as the screenplay.
- Create 2-6 shots per sequence. Cover the important action in that sequence.
- Do not invent plot that is not in the sequences or screenplay.
- Every sequence listed must have at least one shot. Use that sequence's scene_number.
- title: short shot title.
- description: what we see.
- action: what happens in the shot.
- dialogue: spoken line if any, else omit.
- shot_size: WIDE, FULL, MEDIUM, CLOSE-UP, or similar.
- camera_angle: EYE LEVEL, LOW, HIGH, OVER THE SHOULDER, or similar.
- camera_movement: STATIC, PAN, TILT, TRACK, HANDHELD, or similar.
- lighting and mood: short phrases.
- environment: place name for this shot.
- Output JSON only: {"shots":[...]} with scene_number and those keys on each shot.
{$styleLine}
{$sequenceBlock}

SCREENPLAY (for continuity):
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

        $prompt = <<<PROMPT
You are a professional screenwriter. Format the draft script below into a proper screenplay.

Rules:
- Write in the same language as the script.
- Keep the same scenes, characters, and dialogue. Tighten formatting; do not rewrite the plot.
- Use standard screenplay layout: FADE IN, INT./EXT. scene headings, action, CHARACTER names, dialogue, FADE OUT.
- Output plain text only. No markdown, no title page, no commentary before or after the screenplay.
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

    private function styleLine(?string $style): string
    {
        $trimmed = trim((string) $style);

        return $trimmed !== ''
            ? "Visual style: {$trimmed}."
            : '';
    }

    private function nullableString(mixed $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
