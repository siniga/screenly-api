<?php

namespace App\Support;

use App\Exceptions\GenerationFailedException;

class StoryAnalysisValidator
{
    private const REQUIRED_ARRAYS = [
        'characters',
        'locations',
        'time_expressions',
        'timelines',
        'beats',
        'dialogue',
        'internal_narration',
        'flashbacks',
        'delayed_reveals',
        'motifs',
        'must_preserve_elements',
        'unknown_details',
        'ambiguities',
        'contradictions',
    ];

    private const TIMELINE_TYPES = [
        'present',
        'flashback',
        'flash_forward',
        'memory',
        'dream',
        'imagined',
        'unknown',
    ];

    private const BEAT_TYPES = [
        'action',
        'dialogue',
        'decision',
        'revelation',
        'transition',
        'internal',
        'ending',
    ];

    private const IMPORTANCE = [
        'critical',
        'important',
        'supporting',
    ];

    private const MEANING_TYPES = [
        'clock_time',
        'age',
        'duration',
        'date',
        'relative_time',
        'unknown',
    ];

    private const LOCATION_TYPES = [
        'interior',
        'exterior',
        'mixed',
        'unknown',
    ];

    private const CLOCK_WORDS = [
        'one' => 1,
        'two' => 2,
        'three' => 3,
        'four' => 4,
        'five' => 5,
        'six' => 6,
        'seven' => 7,
        'eight' => 8,
        'nine' => 9,
        'ten' => 10,
        'eleven' => 11,
        'twelve' => 12,
    ];

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<array{source_id: string, text: string}>  $sourceBlocks
     * @return array<string, mixed>
     */
    public static function normalize(array $payload, array $sourceBlocks): array
    {
        if (isset($payload['analysis']) && is_array($payload['analysis'])) {
            $payload = $payload['analysis'];
        }

        $sourceIds = StorySourceBlocks::ids($sourceBlocks);
        if ($sourceIds === []) {
            throw new GenerationFailedException('Story analysis has no source blocks to validate against.');
        }

        $missing = [];
        foreach (self::REQUIRED_ARRAYS as $key) {
            if (! array_key_exists($key, $payload)) {
                $missing[] = $key;
            } elseif (! is_array($payload[$key])) {
                throw new GenerationFailedException("Story analysis field \"{$key}\" must be an array.");
            }
        }

        if ($missing !== []) {
            throw new GenerationFailedException(
                'Story analysis is missing required arrays: '.implode(', ', $missing).'.',
            );
        }

        $language = self::requiredString($payload['language'] ?? null, 'language');
        $storyType = self::requiredString($payload['story_type'] ?? null, 'story_type');

        $characters = self::normalizeCharacters($payload['characters'], $sourceIds);
        $characterIds = array_column($characters, 'character_id');
        $locations = self::normalizeLocations($payload['locations'], $sourceIds);
        $locationIds = array_column($locations, 'location_id');
        $timeExpressions = self::normalizeTimeExpressions($payload['time_expressions'], $sourceIds);
        $timelines = self::normalizeTimelines($payload['timelines'], $sourceIds);
        $timelineIds = array_column($timelines, 'timeline_id');
        $beats = self::normalizeBeats($payload['beats'], $sourceIds, $timelineIds, $characterIds, $locationIds);
        $beatIds = array_column($beats, 'beat_id');
        $dialogue = self::normalizeDialogue($payload['dialogue'], $sourceIds, $beatIds);

        $analysis = [
            'language' => $language,
            'story_type' => $storyType,
            'source_blocks' => $sourceBlocks,
            'characters' => self::clearClockTimeAgeCollisions($characters, $timeExpressions),
            'locations' => $locations,
            'time_expressions' => $timeExpressions,
            'timelines' => $timelines,
            'beats' => $beats,
            'dialogue' => $dialogue,
            'internal_narration' => self::normalizeNotes(
                $payload['internal_narration'],
                'internal_narration',
                $sourceIds,
                $characterIds,
                $beatIds,
            ),
            'flashbacks' => self::normalizeNotes(
                $payload['flashbacks'],
                'flashbacks',
                $sourceIds,
                $characterIds,
                $beatIds,
                $timelineIds,
            ),
            'delayed_reveals' => self::normalizeNotes(
                $payload['delayed_reveals'],
                'delayed_reveals',
                $sourceIds,
                $characterIds,
                $beatIds,
            ),
            'motifs' => self::normalizeNotes($payload['motifs'], 'motifs', $sourceIds),
            'must_preserve_elements' => self::normalizeNotes(
                $payload['must_preserve_elements'],
                'must_preserve_elements',
                $sourceIds,
            ),
            'unknown_details' => self::normalizeNotes($payload['unknown_details'], 'unknown_details', $sourceIds, requireSources: false),
            'ambiguities' => self::normalizeNotes($payload['ambiguities'], 'ambiguities', $sourceIds, requireSources: false),
            'contradictions' => self::normalizeNotes($payload['contradictions'], 'contradictions', $sourceIds, requireSources: false),
        ];

        return $analysis;
    }

    /**
     * @param  list<mixed>  $rows
     * @param  list<string>  $sourceIds
     * @return list<array<string, mixed>>
     */
    private static function normalizeCharacters(array $rows, array $sourceIds): array
    {
        $characters = [];
        $seen = [];

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                throw new GenerationFailedException('Story analysis characters must be objects.');
            }

            $id = self::requiredId($row['character_id'] ?? null, 'character_id', $index);
            self::assertUnique($seen, $id, 'character_id');
            $seen[$id] = true;

            $name = self::requiredString($row['name'] ?? null, "characters[{$index}].name");
            $characters[] = [
                'character_id' => $id,
                'name' => $name,
                'aliases' => self::stringList($row['aliases'] ?? []),
                'gender' => self::nullableScalar($row['gender'] ?? null),
                'age' => self::nullableAge($row['age'] ?? null),
                'age_range' => self::nullableScalar($row['age_range'] ?? null),
                'relationships' => self::normalizeRelationships($row['relationships'] ?? [], $sourceIds, $index),
                'confirmed_facts' => self::normalizeFactList($row['confirmed_facts'] ?? [], $sourceIds, "characters[{$index}].confirmed_facts"),
                'unknown_details' => self::stringList($row['unknown_details'] ?? []),
                'source_ids' => self::requiredSourceIds($row['source_ids'] ?? [], $sourceIds, "characters[{$index}]"),
            ];
        }

        self::assertRelationshipTargets($characters);

        return $characters;
    }

    /**
     * @param  list<mixed>  $rows
     * @param  list<string>  $sourceIds
     * @return list<array<string, mixed>>
     */
    private static function normalizeRelationships(mixed $rows, array $sourceIds, int $characterIndex): array
    {
        if (! is_array($rows)) {
            throw new GenerationFailedException("characters[{$characterIndex}].relationships must be an array.");
        }

        $relationships = [];
        foreach ($rows as $index => $row) {
            if (is_string($row)) {
                $text = self::nullableScalar($row);
                if ($text === null) {
                    continue;
                }
                throw new GenerationFailedException(
                    "characters[{$characterIndex}].relationships[{$index}] must be an object with source_ids, not a bare string.",
                );
            }

            if (! is_array($row)) {
                throw new GenerationFailedException("characters[{$characterIndex}].relationships[{$index}] must be an object.");
            }

            $relatedId = self::nullableScalar($row['character_id'] ?? $row['related_character_id'] ?? null);
            $relationships[] = [
                'character_id' => $relatedId,
                'name' => self::nullableScalar($row['name'] ?? null),
                'type' => self::nullableScalar($row['type'] ?? $row['relationship'] ?? null),
                'source_ids' => self::requiredSourceIds(
                    $row['source_ids'] ?? [],
                    $sourceIds,
                    "characters[{$characterIndex}].relationships[{$index}]",
                ),
            ];
        }

        return $relationships;
    }

    /**
     * @param  list<array<string, mixed>>  $characters
     */
    private static function assertRelationshipTargets(array $characters): void
    {
        $ids = array_column($characters, 'character_id');
        foreach ($characters as $character) {
            foreach ($character['relationships'] as $relationship) {
                $target = $relationship['character_id'];
                if ($target !== null && ! in_array($target, $ids, true)) {
                    throw new GenerationFailedException(
                        "Story analysis relationship references unknown character_id \"{$target}\".",
                    );
                }
            }
        }
    }

    /**
     * @param  list<mixed>  $rows
     * @param  list<string>  $sourceIds
     * @return list<array<string, mixed>>
     */
    private static function normalizeLocations(array $rows, array $sourceIds): array
    {
        $locations = [];
        $seen = [];

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                throw new GenerationFailedException('Story analysis locations must be objects.');
            }

            $id = self::requiredId($row['location_id'] ?? null, 'location_id', $index);
            self::assertUnique($seen, $id, 'location_id');
            $seen[$id] = true;

            $locations[] = [
                'location_id' => $id,
                'name' => self::requiredString($row['name'] ?? null, "locations[{$index}].name"),
                'type' => self::enum($row['type'] ?? null, self::LOCATION_TYPES, 'unknown', "locations[{$index}].type"),
                'confirmed_details' => self::normalizeFactList(
                    $row['confirmed_details'] ?? [],
                    $sourceIds,
                    "locations[{$index}].confirmed_details",
                    required: false,
                ),
                'source_ids' => self::requiredSourceIds($row['source_ids'] ?? [], $sourceIds, "locations[{$index}]"),
            ];
        }

        return $locations;
    }

    /**
     * @param  list<mixed>  $rows
     * @param  list<string>  $sourceIds
     * @return list<array<string, mixed>>
     */
    private static function normalizeTimeExpressions(array $rows, array $sourceIds): array
    {
        $expressions = [];

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                throw new GenerationFailedException('Story analysis time_expressions must be objects.');
            }

            $text = self::requiredString($row['text'] ?? null, "time_expressions[{$index}].text");
            $expressions[] = [
                'text' => $text,
                'interpretation' => self::nullableScalar($row['interpretation'] ?? null),
                'meaning_type' => self::enum(
                    $row['meaning_type'] ?? null,
                    self::MEANING_TYPES,
                    'unknown',
                    "time_expressions[{$index}].meaning_type",
                ),
                'confidence' => self::nullableConfidence($row['confidence'] ?? null),
                'source_ids' => self::requiredSourceIds($row['source_ids'] ?? [], $sourceIds, "time_expressions[{$index}]"),
            ];
        }

        return $expressions;
    }

    /**
     * @param  list<mixed>  $rows
     * @param  list<string>  $sourceIds
     * @return list<array<string, mixed>>
     */
    private static function normalizeTimelines(array $rows, array $sourceIds): array
    {
        $timelines = [];
        $seen = [];

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                throw new GenerationFailedException('Story analysis timelines must be objects.');
            }

            $id = self::requiredId($row['timeline_id'] ?? null, 'timeline_id', $index);
            self::assertUnique($seen, $id, 'timeline_id');
            $seen[$id] = true;

            $timelines[] = [
                'timeline_id' => $id,
                'type' => self::enum($row['type'] ?? null, self::TIMELINE_TYPES, 'unknown', "timelines[{$index}].type"),
                'description' => self::nullableScalar($row['description'] ?? null) ?? '',
                'source_ids' => self::sourceIds($row['source_ids'] ?? [], $sourceIds, "timelines[{$index}]", required: false),
            ];
        }

        return $timelines;
    }

    /**
     * @param  list<mixed>  $rows
     * @param  list<string>  $sourceIds
     * @param  list<string>  $timelineIds
     * @param  list<string>  $characterIds
     * @param  list<string>  $locationIds
     * @return list<array<string, mixed>>
     */
    private static function normalizeBeats(
        array $rows,
        array $sourceIds,
        array $timelineIds,
        array $characterIds,
        array $locationIds,
    ): array {
        $beats = [];
        $seen = [];
        $orders = [];

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                throw new GenerationFailedException('Story analysis beats must be objects.');
            }

            $id = self::requiredId($row['beat_id'] ?? null, 'beat_id', $index);
            self::assertUnique($seen, $id, 'beat_id');
            $seen[$id] = true;

            if (! is_numeric($row['order'] ?? null)) {
                throw new GenerationFailedException("beats[{$index}].order must be numeric.");
            }

            $order = (int) $row['order'];
            if (isset($orders[$order])) {
                throw new GenerationFailedException("Story analysis beat order {$order} is duplicated.");
            }
            $orders[$order] = true;

            $timelineId = self::requiredString($row['timeline_id'] ?? null, "beats[{$index}].timeline_id");
            if (! in_array($timelineId, $timelineIds, true)) {
                throw new GenerationFailedException("beats[{$index}] references unknown timeline_id \"{$timelineId}\".");
            }

            $locationId = self::nullableScalar($row['location_id'] ?? null);
            if ($locationId !== null && ! in_array($locationId, $locationIds, true)) {
                throw new GenerationFailedException("beats[{$index}] references unknown location_id \"{$locationId}\".");
            }

            $beats[] = [
                'beat_id' => $id,
                'order' => $order,
                'timeline_id' => $timelineId,
                'type' => self::enum($row['type'] ?? null, self::BEAT_TYPES, null, "beats[{$index}].type"),
                'summary' => self::requiredString($row['summary'] ?? null, "beats[{$index}].summary"),
                'characters' => self::characterRefs($row['characters'] ?? [], $characterIds, "beats[{$index}].characters"),
                'location_id' => $locationId,
                'importance' => self::enum($row['importance'] ?? null, self::IMPORTANCE, 'supporting', "beats[{$index}].importance"),
                'must_appear' => self::boolean($row['must_appear'] ?? false),
                'source_ids' => self::requiredSourceIds($row['source_ids'] ?? [], $sourceIds, "beats[{$index}]"),
            ];
        }

        usort($beats, fn (array $a, array $b) => $a['order'] <=> $b['order']);

        return array_values($beats);
    }

    /**
     * @param  list<mixed>  $rows
     * @param  list<string>  $sourceIds
     * @param  list<string>  $beatIds
     * @return list<array<string, mixed>>
     */
    private static function normalizeDialogue(array $rows, array $sourceIds, array $beatIds): array
    {
        $dialogue = [];
        $seen = [];

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                throw new GenerationFailedException('Story analysis dialogue must be objects.');
            }

            $id = self::requiredId($row['dialogue_id'] ?? null, 'dialogue_id', $index);
            self::assertUnique($seen, $id, 'dialogue_id');
            $seen[$id] = true;

            $text = self::requiredString($row['text'] ?? null, "dialogue[{$index}].text");
            $beatId = self::nullableScalar($row['beat_id'] ?? null);
            if ($beatId !== null && ! in_array($beatId, $beatIds, true)) {
                throw new GenerationFailedException("dialogue[{$index}] references unknown beat_id \"{$beatId}\".");
            }

            $dialogue[] = [
                'dialogue_id' => $id,
                'speaker' => self::nullableScalar($row['speaker'] ?? null),
                'text' => $text,
                'beat_id' => $beatId,
                'source_ids' => self::requiredSourceIds($row['source_ids'] ?? [], $sourceIds, "dialogue[{$index}]"),
            ];
        }

        return $dialogue;
    }

    /**
     * @param  list<mixed>  $rows
     * @param  list<string>  $sourceIds
     * @param  list<string>  $characterIds
     * @param  list<string>  $beatIds
     * @param  list<string>  $timelineIds
     * @return list<array<string, mixed>>
     */
    private static function normalizeNotes(
        array $rows,
        string $label,
        array $sourceIds,
        array $characterIds = [],
        array $beatIds = [],
        array $timelineIds = [],
        bool $requireSources = true,
    ): array {
        $notes = [];
        $seen = [];

        foreach ($rows as $index => $row) {
            if (is_string($row)) {
                $text = self::nullableScalar($row);
                if ($text === null) {
                    continue;
                }
                throw new GenerationFailedException("{$label}[{$index}] must be an object, not a bare string.");
            }

            if (! is_array($row)) {
                throw new GenerationFailedException("{$label}[{$index}] must be an object.");
            }

            $idKey = match ($label) {
                'internal_narration' => 'narration_id',
                'flashbacks' => 'flashback_id',
                'delayed_reveals' => 'reveal_id',
                'motifs' => 'motif_id',
                'must_preserve_elements' => 'element_id',
                'unknown_details' => 'unknown_id',
                'ambiguities' => 'ambiguity_id',
                'contradictions' => 'contradiction_id',
                default => 'id',
            };

            $id = trim((string) ($row[$idKey] ?? $row['id'] ?? ''));
            if ($id === '') {
                $id = sprintf('%s_%03d', rtrim($idKey, '_id'), $index + 1);
            }
            self::assertUnique($seen, $id, $idKey);
            $seen[$id] = true;

            $characterId = self::nullableScalar($row['character_id'] ?? null);
            if ($characterId !== null && $characterIds !== [] && ! in_array($characterId, $characterIds, true)) {
                throw new GenerationFailedException("{$label}[{$index}] references unknown character_id \"{$characterId}\".");
            }

            $beatId = self::nullableScalar($row['beat_id'] ?? $row['revealed_at_beat_id'] ?? null);
            if ($beatId !== null && $beatIds !== [] && ! in_array($beatId, $beatIds, true)) {
                throw new GenerationFailedException("{$label}[{$index}] references unknown beat_id \"{$beatId}\".");
            }

            $timelineId = self::nullableScalar($row['timeline_id'] ?? null);
            if ($timelineId !== null && $timelineIds !== [] && ! in_array($timelineId, $timelineIds, true)) {
                throw new GenerationFailedException("{$label}[{$index}] references unknown timeline_id \"{$timelineId}\".");
            }

            $text = self::nullableScalar($row['text'] ?? $row['summary'] ?? $row['description'] ?? $row['detail'] ?? null);
            if ($text === null) {
                throw new GenerationFailedException("{$label}[{$index}] is missing text.");
            }

            $notes[] = [
                $idKey => $id,
                'text' => $text,
                'character_id' => $characterId,
                'beat_id' => $beatId,
                'timeline_id' => $timelineId,
                'source_ids' => $requireSources
                    ? self::requiredSourceIds($row['source_ids'] ?? [], $sourceIds, "{$label}[{$index}]")
                    : self::sourceIds($row['source_ids'] ?? [], $sourceIds, "{$label}[{$index}]", required: false),
            ];
        }

        return $notes;
    }

    /**
     * @param  list<array<string, mixed>>  $characters
     * @param  list<array<string, mixed>>  $timeExpressions
     * @return list<array<string, mixed>>
     */
    private static function clearClockTimeAgeCollisions(array $characters, array $timeExpressions): array
    {
        $clockHours = [];
        $explicitAges = [];

        foreach ($timeExpressions as $expression) {
            $hours = self::hoursFromExpression($expression);
            if (($expression['meaning_type'] ?? '') === 'age') {
                foreach ($hours as $hour) {
                    $explicitAges[$hour] = true;
                }

                continue;
            }

            if (($expression['meaning_type'] ?? '') === 'clock_time') {
                foreach ($hours as $hour) {
                    $clockHours[$hour] = true;
                }
            }
        }

        foreach ($characters as $index => $character) {
            $age = $character['age'];
            if ($age === null) {
                continue;
            }

            $numeric = self::ageAsInteger($age);
            if ($numeric === null) {
                continue;
            }

            if (isset($clockHours[$numeric]) && ! isset($explicitAges[$numeric])) {
                $characters[$index]['age'] = null;
            }
        }

        return $characters;
    }

    /**
     * @param  array<string, mixed>  $expression
     * @return list<int>
     */
    private static function hoursFromExpression(array $expression): array
    {
        $blob = strtolower(trim(
            (string) ($expression['text'] ?? '').' '.(string) ($expression['interpretation'] ?? ''),
        ));
        if ($blob === '') {
            return [];
        }

        $hours = [];
        if (preg_match_all('/\b([01]?\d|2[0-3])(?::[0-5]\d)?\b/', $blob, $matches)) {
            foreach ($matches[1] as $hour) {
                $hours[] = (int) $hour;
            }
        }

        foreach (self::CLOCK_WORDS as $word => $value) {
            if (preg_match('/\b'.preg_quote($word, '/').'\b/', $blob)) {
                $hours[] = $value;
            }
        }

        return array_values(array_unique($hours));
    }

    private static function ageAsInteger(int|string $age): ?int
    {
        if (is_int($age)) {
            return $age;
        }

        $trimmed = strtolower(trim($age));
        if (preg_match('/^\d+$/', $trimmed)) {
            return (int) $trimmed;
        }

        return self::CLOCK_WORDS[$trimmed] ?? null;
    }

    /**
     * @param  list<mixed>  $rows
     * @param  list<string>  $sourceIds
     * @return list<array{fact: string, source_ids: list<string>}>
     */
    private static function normalizeFactList(mixed $rows, array $sourceIds, string $label, bool $required = true): array
    {
        if (! is_array($rows)) {
            throw new GenerationFailedException("{$label} must be an array.");
        }

        $facts = [];
        foreach ($rows as $index => $row) {
            if (is_string($row)) {
                $fact = self::nullableScalar($row);
                if ($fact === null) {
                    continue;
                }
                throw new GenerationFailedException("{$label}[{$index}] must be an object with source_ids.");
            }

            if (! is_array($row)) {
                throw new GenerationFailedException("{$label}[{$index}] must be an object.");
            }

            $fact = self::nullableScalar($row['fact'] ?? $row['text'] ?? $row['detail'] ?? null);
            if ($fact === null) {
                throw new GenerationFailedException("{$label}[{$index}] is missing fact text.");
            }

            $facts[] = [
                'fact' => $fact,
                'source_ids' => $required
                    ? self::requiredSourceIds($row['source_ids'] ?? [], $sourceIds, "{$label}[{$index}]")
                    : self::sourceIds($row['source_ids'] ?? [], $sourceIds, "{$label}[{$index}]", required: false),
            ];
        }

        return $facts;
    }

    /**
     * @param  list<mixed>  $refs
     * @param  list<string>  $characterIds
     * @return list<string>
     */
    private static function characterRefs(mixed $refs, array $characterIds, string $label): array
    {
        if (! is_array($refs)) {
            throw new GenerationFailedException("{$label} must be an array.");
        }

        $resolved = [];
        foreach ($refs as $ref) {
            $id = trim((string) $ref);
            if ($id === '') {
                continue;
            }
            if (! in_array($id, $characterIds, true)) {
                throw new GenerationFailedException("{$label} references unknown character_id \"{$id}\".");
            }
            $resolved[] = $id;
        }

        return array_values(array_unique($resolved));
    }

    /**
     * @param  array<string, true>  $seen
     */
    private static function assertUnique(array $seen, string $id, string $label): void
    {
        if (isset($seen[$id])) {
            throw new GenerationFailedException("Story analysis {$label} \"{$id}\" is duplicated.");
        }
    }

    /**
     * @param  list<string>  $allowed
     */
    private static function enum(mixed $value, array $allowed, ?string $fallback, string $label): string
    {
        $normalized = strtolower(trim((string) $value));
        $normalized = str_replace([' ', '-'], '_', $normalized);
        if (in_array($normalized, $allowed, true)) {
            return $normalized;
        }

        if ($fallback !== null && ($normalized === '' || self::isPlaceholder($normalized))) {
            return $fallback;
        }

        throw new GenerationFailedException("{$label} has an invalid value.");
    }

    /**
     * @param  list<string>  $known
     * @return list<string>
     */
    private static function requiredSourceIds(mixed $value, array $known, string $label): array
    {
        $ids = self::sourceIds($value, $known, $label, required: true);
        if ($ids === []) {
            throw new GenerationFailedException("{$label} must include one or more supporting source_ids.");
        }

        return $ids;
    }

    /**
     * @param  list<string>  $known
     * @return list<string>
     */
    private static function sourceIds(mixed $value, array $known, string $label, bool $required): array
    {
        if ($value === null && ! $required) {
            return [];
        }

        if (is_string($value)) {
            $value = preg_split('/[,;]+/', $value) ?: [];
        }

        if (! is_array($value)) {
            throw new GenerationFailedException("{$label}.source_ids must be an array.");
        }

        $ids = [];
        foreach ($value as $item) {
            $id = trim((string) $item);
            if ($id === '') {
                continue;
            }
            if (! in_array($id, $known, true)) {
                throw new GenerationFailedException("{$label} references unknown source_id \"{$id}\".");
            }
            $ids[] = $id;
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            $text = self::nullableScalar($item);
            if ($text !== null) {
                $items[] = $text;
            }
        }

        return array_values(array_unique($items));
    }

    private static function requiredId(mixed $value, string $label, int $index): string
    {
        $id = trim((string) $value);
        if ($id === '' || self::isPlaceholder($id)) {
            throw new GenerationFailedException("{$label} is missing on item {$index}.");
        }

        return $id;
    }

    private static function requiredString(mixed $value, string $label): string
    {
        $text = trim((string) $value);
        if ($text === '' || self::isPlaceholder($text)) {
            throw new GenerationFailedException("{$label} is required.");
        }

        return $text;
    }

    private static function nullableScalar(mixed $value): ?string
    {
        if ($value === null || is_array($value)) {
            return null;
        }

        $text = trim((string) $value);
        if ($text === '' || self::isPlaceholder($text)) {
            return null;
        }

        return $text;
    }

    private static function nullableAge(mixed $value): int|string|null
    {
        if ($value === null || is_array($value)) {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            $number = (int) $value;
            if ($number <= 0) {
                return null;
            }

            return $number;
        }

        $text = self::nullableScalar($value);

        return $text;
    }

    private static function nullableConfidence(mixed $value): ?float
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        $number = (float) $value;
        if ($number < 0 || $number > 1) {
            throw new GenerationFailedException('time_expressions confidence must be between 0 and 1.');
        }

        return $number;
    }

    private static function boolean(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private static function isPlaceholder(string $value): bool
    {
        $normalized = strtolower(trim($value));

        return in_array($normalized, [
            'unknown',
            'unspecified',
            'n/a',
            'na',
            'none',
            'null',
            'tbd',
            '-',
            '—',
        ], true);
    }
}
