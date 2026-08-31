<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

class ProjectImageStore
{
    /**
     * @return array{binary: string, mime: string}|null
     */
    public function readPublicUrl(?string $url): ?array
    {
        $path = $this->pathFromUrl($url);
        if ($path === null || ! Storage::disk('public')->exists($path)) {
            return null;
        }

        $binary = Storage::disk('public')->get($path);
        if (! is_string($binary) || $binary === '') {
            return null;
        }

        return [
            'binary' => $binary,
            'mime' => Storage::disk('public')->mimeType($path) ?: 'image/png',
        ];
    }

    public function put(int $projectId, string $folder, string $name, string $binary, string $mime): string
    {
        $path = sprintf(
            'projects/%d/%s/%s.%s',
            $projectId,
            trim($folder, '/'),
            $name,
            $this->extension($mime),
        );

        Storage::disk('public')->put($path, $binary);

        return '/storage/'.$path;
    }

    private function pathFromUrl(?string $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            $path = $url;
        }

        $path = ltrim($path, '/');
        if (str_starts_with($path, 'storage/')) {
            $path = substr($path, strlen('storage/'));
        }

        return $path !== '' ? $path : null;
    }

    private function extension(string $mime): string
    {
        return match (strtolower($mime)) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/webp' => 'webp',
            default => 'png',
        };
    }
}
