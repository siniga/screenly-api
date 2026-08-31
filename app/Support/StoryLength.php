<?php

namespace App\Support;

class StoryLength
{
    public const MAX_SINGLE_SCREENPLAY_WORDS = 2000;

    public const WORDS_PER_EPISODE = 900;

    public static function wordCount(string $text): int
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return 0;
        }

        $parts = preg_split('/\s+/u', $trimmed, -1, PREG_SPLIT_NO_EMPTY);

        return is_array($parts) ? count($parts) : 0;
    }

    public static function needsEpisodes(string $text): bool
    {
        return self::wordCount($text) > self::MAX_SINGLE_SCREENPLAY_WORDS;
    }

    public static function estimatedEpisodeCount(string $text): int
    {
        $words = self::wordCount($text);
        if ($words <= self::MAX_SINGLE_SCREENPLAY_WORDS) {
            return 1;
        }

        return max(2, min(12, (int) ceil($words / self::WORDS_PER_EPISODE)));
    }
}
