<?php

namespace App\Services;

use App\Exceptions\GenerationFailedException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class GeminiClient
{
    public function generateText(string $prompt, int $maxOutputTokens = 8192): string
    {
        return $this->generate($prompt, $maxOutputTokens, false);
    }

    public function generateJson(string $prompt, int $maxOutputTokens = 8192): array
    {
        $text = $this->generate($prompt, $maxOutputTokens, true);
        $decoded = $this->decodeJson($text);
        if (! is_array($decoded)) {
            throw new GenerationFailedException('Gemini did not return valid JSON.');
        }

        return $decoded;
    }

    /**
     * @param  list<array{binary: string, mime: string}>  $references
     * @return array{binary: string, mime: string}
     */
    public function generateImage(string $prompt, array $references = [], ?string $aspectRatio = null): array
    {
        $apiKey = (string) config('services.gemini.api_key');
        $model = (string) config('services.gemini.image_model', 'gemini-2.5-flash-image');

        if ($apiKey === '') {
            throw new GenerationFailedException(
                'Gemini API key is not configured.',
                503,
            );
        }

        $url = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent',
            urlencode($model),
        );

        $parts = [['text' => $prompt]];
        foreach (array_slice($references, 0, 4) as $reference) {
            $binary = $reference['binary'] ?? '';
            if ($binary === '') {
                continue;
            }

            $parts[] = [
                'inlineData' => [
                    'mimeType' => $reference['mime'] ?? 'image/png',
                    'data' => base64_encode($binary),
                ],
            ];
        }

        $generationConfig = [
            'responseModalities' => ['TEXT', 'IMAGE'],
        ];
        if (is_string($aspectRatio) && $aspectRatio !== '') {
            $generationConfig['imageConfig'] = [
                'aspectRatio' => $aspectRatio,
            ];
        }

        $response = Http::timeout(120)
            ->acceptJson()
            ->asJson()
            ->withQueryParameters(['key' => $apiKey])
            ->post($url, [
                'contents' => [
                    [
                        'role' => 'user',
                        'parts' => $parts,
                    ],
                ],
                'generationConfig' => $generationConfig,
            ]);

        if ($response->failed()) {
            throw new GenerationFailedException(
                $this->errorMessage($response),
                $this->statusFor($response),
            );
        }

        $payload = $response->json();
        $image = $this->extractImage($payload);
        if ($image === null) {
            throw new GenerationFailedException($this->missingImageMessage($payload));
        }

        return $image;
    }

    private function generate(string $prompt, int $maxOutputTokens, bool $json): string
    {
        $apiKey = (string) config('services.gemini.api_key');
        $model = (string) config('services.gemini.text_model', 'gemini-2.5-flash');

        if ($apiKey === '') {
            throw new GenerationFailedException(
                'Gemini API key is not configured.',
                503,
            );
        }

        $url = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent',
            urlencode($model),
        );

        $generationConfig = [
            'temperature' => $json ? 0.4 : 0.85,
            'maxOutputTokens' => $maxOutputTokens,
            'thinkingConfig' => [
                'thinkingBudget' => 0,
            ],
        ];

        if ($json) {
            $generationConfig['responseMimeType'] = 'application/json';
        }

        $response = Http::timeout(120)
            ->acceptJson()
            ->asJson()
            ->withQueryParameters(['key' => $apiKey])
            ->post($url, [
                'contents' => [
                    [
                        'role' => 'user',
                        'parts' => [
                            ['text' => $prompt],
                        ],
                    ],
                ],
                'generationConfig' => $generationConfig,
            ]);

        if ($response->failed()) {
            throw new GenerationFailedException(
                $this->errorMessage($response),
                $this->statusFor($response),
            );
        }

        $text = $this->extractText($response->json());
        if ($text === '') {
            throw new GenerationFailedException('Gemini returned an empty response.');
        }

        return $text;
    }

    private function decodeJson(string $text): mixed
    {
        $trimmed = trim($text);
        if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/', $trimmed, $matches)) {
            $trimmed = trim($matches[1]);
        }

        $decoded = json_decode($trimmed, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        if (preg_match('/\[[\s\S]*\]/', $trimmed, $matches)) {
            $decoded = json_decode($matches[0], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        return null;
    }

    private function extractText(mixed $payload): string
    {
        $parts = data_get($payload, 'candidates.0.content.parts', []);
        if (! is_array($parts)) {
            return '';
        }

        $chunks = [];
        foreach ($parts as $part) {
            if (! is_array($part)) {
                continue;
            }
            if (! empty($part['thought'])) {
                continue;
            }
            $text = trim((string) ($part['text'] ?? ''));
            if ($text !== '') {
                $chunks[] = $text;
            }
        }

        $combined = trim(implode("\n", $chunks));

        if (preg_match('/^```(?:[a-zA-Z0-9_-]+)?\s*([\s\S]*?)\s*```$/', $combined, $matches)) {
            return trim($matches[1]);
        }

        return $combined;
    }

    /**
     * @return array{binary: string, mime: string}|null
     */
    private function extractImage(mixed $payload): ?array
    {
        $candidates = data_get($payload, 'candidates', []);
        if (! is_array($candidates)) {
            return null;
        }

        foreach ($candidates as $candidate) {
            $parts = data_get($candidate, 'content.parts', []);
            if (! is_array($parts)) {
                continue;
            }

            foreach ($parts as $part) {
                if (! is_array($part)) {
                    continue;
                }

                $inline = $part['inlineData'] ?? $part['inline_data'] ?? null;
                if (! is_array($inline)) {
                    continue;
                }

                $data = $inline['data'] ?? '';
                if (! is_string($data) || $data === '') {
                    continue;
                }

                $binary = base64_decode($data, true);
                if ($binary === false || $binary === '') {
                    continue;
                }

                $mime = $inline['mimeType'] ?? $inline['mime_type'] ?? 'image/png';

                return [
                    'binary' => $binary,
                    'mime' => is_string($mime) && $mime !== '' ? $mime : 'image/png',
                ];
            }
        }

        return null;
    }

    private function missingImageMessage(mixed $payload): string
    {
        $reason = data_get($payload, 'candidates.0.finishReason')
            ?? data_get($payload, 'promptFeedback.blockReason');
        $text = $this->extractText($payload);

        if (is_string($reason) && preg_match('/safety|blocked|prohibited/i', $reason)) {
            return 'Gemini blocked this image for safety. Retry uses a milder storyboard framing.';
        }

        if ($text !== '') {
            return 'Gemini did not return an image: '.$this->truncate($text, 160);
        }

        if (is_string($reason) && $reason !== '' && ! in_array($reason, ['STOP', 'MAX_TOKENS'], true)) {
            return 'Gemini did not return an image ('.$reason.').';
        }

        return 'Gemini did not return an image.';
    }

    private function truncate(string $text, int $max): string
    {
        $text = preg_replace('/\s+/', ' ', trim($text)) ?? $text;
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $max - 1)).'…';
    }

    private function errorMessage(Response $response): string
    {
        if ($response->status() === 429) {
            return 'Gemini API quota is exhausted. Check billing in Google AI Studio, then try again.';
        }

        $message = data_get($response->json(), 'error.message');
        if (is_string($message) && $message !== '') {
            return 'Gemini API error: '.$message;
        }

        return 'Gemini API request failed.';
    }

    private function statusFor(Response $response): int
    {
        $status = $response->status();

        return $status >= 400 && $status < 600 ? $status : 502;
    }
}
