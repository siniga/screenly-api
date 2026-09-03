<?php

namespace App\Support;

use App\Models\Character;
use Illuminate\Support\Collection;

class CharacterNameMatch
{
    /**
     * @param  Collection<int, Character>  $characters
     * @param  list<mixed>  $names
     * @return Collection<int, Character>
     */
    public static function fromNames(Collection $characters, array $names): Collection
    {
        $needles = collect($names)
            ->map(fn ($name) => mb_strtolower(trim((string) $name)))
            ->filter(fn (string $name) => $name !== '')
            ->unique()
            ->values();

        if ($needles->isEmpty()) {
            return collect();
        }

        return $characters
            ->filter(function (Character $character) use ($needles) {
                $name = self::normalize($character->name);
                if ($name === '') {
                    return false;
                }

                return $needles->contains(
                    fn (string $needle) => self::namesAlign($name, $needle)
                );
            })
            ->values();
    }

    /**
     * @param  Collection<int, Character>  $characters
     * @return Collection<int, Character>
     */
    public static function fromHaystack(Collection $characters, string $haystack): Collection
    {
        $haystack = mb_strtolower(trim($haystack));
        if ($haystack === '') {
            return collect();
        }

        return $characters
            ->filter(function (Character $character) use ($haystack) {
                $name = self::normalize($character->name);
                if ($name === '' || mb_strlen($name) < 3) {
                    return false;
                }

                return str_contains($haystack, $name);
            })
            ->values();
    }

    private static function namesAlign(string $characterName, string $needle): bool
    {
        if ($characterName === $needle) {
            return true;
        }

        if (mb_strlen($characterName) < 2 || mb_strlen($needle) < 2) {
            return false;
        }

        return str_contains($needle, $characterName)
            || str_contains($characterName, $needle);
    }

    private static function normalize(?string $name): string
    {
        return mb_strtolower(trim((string) $name));
    }
}
