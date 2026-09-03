<?php

namespace Tests\Unit;

use App\Exceptions\GenerationFailedException;
use App\Support\StoryAnalysisValidator;
use App\Support\StorySourceBlocks;
use PHPUnit\Framework\TestCase;

class StoryAnalysisValidatorTest extends TestCase
{
    public function test_it_rejects_unknown_source_ids(): void
    {
        $blocks = StorySourceBlocks::fromStory("A woman waits.\n\nSomeone knocks.");
        $payload = $this->validPayload(['source_001']);
        $payload['characters'][0]['source_ids'] = ['source_999'];

        try {
            StoryAnalysisValidator::normalize($payload, $blocks);
            $this->fail('Expected unknown source_id to be rejected.');
        } catch (GenerationFailedException $exception) {
            $this->assertStringContainsString('source_999', $exception->getMessage());
        }
    }

    public function test_it_rejects_missing_required_arrays(): void
    {
        $blocks = StorySourceBlocks::fromStory('A woman waits at the door.');
        $payload = $this->validPayload(['source_001']);
        unset($payload['contradictions']);

        $this->expectException(GenerationFailedException::class);
        $this->expectExceptionMessage('contradictions');

        StoryAnalysisValidator::normalize($payload, $blocks);
    }

    public function test_it_rejects_unknown_beat_timeline_references(): void
    {
        $blocks = StorySourceBlocks::fromStory('A woman waits at the door.');
        $payload = $this->validPayload(['source_001']);
        $payload['beats'][0]['timeline_id'] = 'timeline_missing';

        $this->expectException(GenerationFailedException::class);
        $this->expectExceptionMessage('timeline_missing');

        StoryAnalysisValidator::normalize($payload, $blocks);
    }

    public function test_it_does_not_treat_clock_time_as_a_character_age(): void
    {
        $story = 'At eleven, they brought in the white dress.';
        $blocks = StorySourceBlocks::fromStory($story);
        $payload = $this->validPayload(['source_001']);
        $payload['characters'][0]['name'] = 'They';
        $payload['characters'][0]['age'] = 11;
        $payload['time_expressions'] = [[
            'text' => 'At eleven',
            'interpretation' => '11:00',
            'meaning_type' => 'clock_time',
            'confidence' => 0.99,
            'source_ids' => ['source_001'],
        ]];
        $payload['beats'][0]['summary'] = 'They brought in the white dress.';

        $analysis = StoryAnalysisValidator::normalize($payload, $blocks);

        $this->assertSame('clock_time', $analysis['time_expressions'][0]['meaning_type']);
        $this->assertNull($analysis['characters'][0]['age']);
        $this->assertNull($analysis['characters'][0]['age_range']);
        $this->assertSame('source_001', $analysis['source_blocks'][0]['source_id']);
        $this->assertSame($story, $analysis['source_blocks'][0]['text']);
    }

    public function test_it_keeps_an_age_when_the_source_states_age_explicitly(): void
    {
        $blocks = StorySourceBlocks::fromStory('Mira is eleven. At noon they leave.');
        $payload = $this->validPayload(['source_001']);
        $payload['characters'][0]['name'] = 'Mira';
        $payload['characters'][0]['age'] = 11;
        $payload['time_expressions'] = [
            [
                'text' => 'eleven',
                'interpretation' => '11 years old',
                'meaning_type' => 'age',
                'confidence' => 0.9,
                'source_ids' => ['source_001'],
            ],
            [
                'text' => 'At noon',
                'interpretation' => '12:00',
                'meaning_type' => 'clock_time',
                'confidence' => 0.9,
                'source_ids' => ['source_001'],
            ],
        ];

        $analysis = StoryAnalysisValidator::normalize($payload, $blocks);

        $this->assertSame(11, $analysis['characters'][0]['age']);
    }

    /**
     * @param  list<string>  $sourceIds
     * @return array<string, mixed>
     */
    private function validPayload(array $sourceIds): array
    {
        $source = $sourceIds[0];

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
                'source_ids' => [$source],
            ]],
            'locations' => [],
            'time_expressions' => [],
            'timelines' => [[
                'timeline_id' => 'timeline_present',
                'type' => 'present',
                'description' => 'Current narrative timeline.',
                'source_ids' => [$source],
            ]],
            'beats' => [[
                'beat_id' => 'beat_001',
                'order' => 1,
                'timeline_id' => 'timeline_present',
                'type' => 'action',
                'summary' => 'A woman waits.',
                'characters' => ['character_001'],
                'location_id' => null,
                'importance' => 'critical',
                'must_appear' => true,
                'source_ids' => [$source],
            ]],
            'dialogue' => [],
            'internal_narration' => [],
            'flashbacks' => [],
            'delayed_reveals' => [],
            'motifs' => [],
            'must_preserve_elements' => [],
            'unknown_details' => [],
            'ambiguities' => [],
            'contradictions' => [],
        ];
    }
}
