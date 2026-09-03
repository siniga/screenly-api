<?php

namespace App\Support;

class StorySourceBlocks
{
    /**
     * Split a story into stable numbered source blocks without rewriting the text.
     *
     * @return list<array{source_id: string, text: string}>
     */
    public static function fromStory(string $story): array
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $story);
        $parts = preg_split("/\n\s*\n+/u", $normalized) ?: [];

        $blocks = [];
        $number = 1;
        foreach ($parts as $part) {
            $text = trim((string) $part);
            if ($text === '') {
                continue;
            }

            $blocks[] = [
                'source_id' => sprintf('source_%03d', $number),
                'text' => $text,
            ];
            $number++;
        }

        return $blocks;
    }

    /**
     * @param  list<array{source_id?: string}>  $blocks
     * @return list<string>
     */
    public static function ids(array $blocks): array
    {
        $ids = [];
        foreach ($blocks as $block) {
            $id = trim((string) ($block['source_id'] ?? ''));
            if ($id !== '') {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
