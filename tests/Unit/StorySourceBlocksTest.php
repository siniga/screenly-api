<?php

namespace Tests\Unit;

use App\Support\StorySourceBlocks;
use PHPUnit\Framework\TestCase;

class StorySourceBlocksTest extends TestCase
{
    public function test_it_splits_paragraphs_into_numbered_source_blocks(): void
    {
        $story = "First paragraph stays intact.\n\nSecond paragraph has \"quoted dialogue\" inside it.\n\n\nThird paragraph.";

        $blocks = StorySourceBlocks::fromStory($story);

        $this->assertSame([
            [
                'source_id' => 'source_001',
                'text' => 'First paragraph stays intact.',
            ],
            [
                'source_id' => 'source_002',
                'text' => 'Second paragraph has "quoted dialogue" inside it.',
            ],
            [
                'source_id' => 'source_003',
                'text' => 'Third paragraph.',
            ],
        ], $blocks);
    }

    public function test_it_ignores_empty_blocks_and_keeps_a_single_paragraph(): void
    {
        $story = "\n\nAt eleven, they brought in the white dress.\n\n\n";

        $blocks = StorySourceBlocks::fromStory($story);

        $this->assertCount(1, $blocks);
        $this->assertSame('source_001', $blocks[0]['source_id']);
        $this->assertSame('At eleven, they brought in the white dress.', $blocks[0]['text']);
    }
}
