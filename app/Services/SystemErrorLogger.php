<?php

namespace App\Services;

use App\Exceptions\GenerationFailedException;
use App\Models\Project;
use App\Models\SystemErrorLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

class SystemErrorLogger
{
    public const GENERIC = 'Something went wrong. Please try again.';

    public const GENERATION = 'We could not finish this step right now. Please try again in a moment.';

    public const BUSY = 'Our creative tools are busy right now. Please wait a minute and try again.';

    public const SAFETY = 'This image could not be created because of content guidelines. Try changing the scene and try again.';

    public const IMAGE = 'We could not create this image. Please try again.';

    public function log(Throwable $exception, array $context = []): string
    {
        $userMessage = $this->userMessage($exception);

        $this->persist(
            $exception->getMessage(),
            $userMessage,
            array_merge($context, [
                'exception_class' => $exception::class,
                'code' => (string) $exception->getCode(),
                'file' => $this->relativePath($exception->getFile()),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
                'status' => $context['status'] ?? ($exception instanceof GenerationFailedException ? $exception->status : null),
            ]),
        );

        return $userMessage;
    }

    public function logMessage(string $technicalMessage, array $context = []): string
    {
        $userMessage = $this->userMessageFromString($technicalMessage);
        $this->persist($technicalMessage, $userMessage, $context);

        return $userMessage;
    }

    public function userMessage(Throwable $exception): string
    {
        return $this->userMessageFromString($exception->getMessage());
    }

    public function userMessageFromString(string $message): string
    {
        $trimmed = trim($message);
        if ($trimmed === '') {
            return self::GENERIC;
        }

        $lower = strtolower($trimmed);

        if (
            str_contains($lower, 'api key')
            || str_contains($lower, 'not configured')
        ) {
            return self::GENERATION;
        }

        if (
            str_contains($lower, 'quota')
            || str_contains($lower, 'resource exhausted')
            || str_contains($lower, 'billing')
            || str_contains($lower, 'credits')
            || str_contains($lower, 'overloaded')
            || str_contains($lower, 'high demand')
            || str_contains($lower, 'try again later')
        ) {
            return self::BUSY;
        }

        if (
            str_contains($lower, 'blocked this image')
            || str_contains($lower, 'for safety')
        ) {
            return self::SAFETY;
        }

        if (str_contains($lower, 'did not return an image')) {
            return self::IMAGE;
        }

        if ($this->looksTechnical($trimmed)) {
            return self::GENERATION;
        }

        return $trimmed;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function persist(string $technicalMessage, string $userMessage, array $context): void
    {
        $request = request();

        try {
            SystemErrorLog::create([
                'user_id' => $context['user_id'] ?? Auth::id(),
                'project_id' => $context['project_id'] ?? $this->projectIdFromRequest($request),
                'source' => $context['source'] ?? 'api',
                'level' => $context['level'] ?? 'error',
                'exception_class' => $context['exception_class'] ?? null,
                'message' => $this->truncate($technicalMessage, 2000),
                'code' => isset($context['code']) ? $this->truncate((string) $context['code'], 80) : null,
                'file' => isset($context['file']) ? $this->truncate((string) $context['file'], 255) : null,
                'line' => isset($context['line']) ? (int) $context['line'] : null,
                'trace' => isset($context['trace']) ? $this->truncate((string) $context['trace'], 8000) : null,
                'http_method' => $request instanceof Request ? $request->method() : null,
                'http_path' => $request instanceof Request ? $this->truncate($request->path(), 255) : null,
                'status_code' => isset($context['status']) ? (int) $context['status'] : null,
                'user_message' => $this->truncate($userMessage, 255),
                'context' => $this->safeContext($context),
            ]);
        } catch (Throwable $loggingError) {
            Log::error('Failed to persist system error log', [
                'original' => $technicalMessage,
                'logging' => $loggingError->getMessage(),
            ]);
        }

        Log::error($technicalMessage, [
            'exception_class' => $context['exception_class'] ?? null,
            'project_id' => $context['project_id'] ?? null,
            'source' => $context['source'] ?? 'api',
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    private function safeContext(array $context): ?array
    {
        unset(
            $context['exception_class'],
            $context['code'],
            $context['file'],
            $context['line'],
            $context['trace'],
            $context['status'],
            $context['user_id'],
            $context['project_id'],
            $context['source'],
            $context['level'],
        );

        if ($context === []) {
            return null;
        }

        return $context;
    }

    private function projectIdFromRequest(mixed $request): ?int
    {
        if (! $request instanceof Request) {
            return null;
        }

        $project = $request->route('project');
        if ($project instanceof Project) {
            return $project->id;
        }

        if (is_numeric($project)) {
            return (int) $project;
        }

        return null;
    }

    private function looksTechnical(string $message): bool
    {
        $lower = strtolower($message);
        $needles = [
            'gemini',
            'sqlstate',
            'pdoexception',
            'queryexception',
            'stack trace',
            'google ai',
            'xampp',
            'artisan',
            'curl error',
            'undefined array key',
            'undefined variable',
            'connection: mysql',
            'illuminate\\',
            'vendor/',
            'vendor\\',
            'sql:',
            'insert into',
            'api error',
            'api request failed',
        ];

        foreach ($needles as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return (bool) preg_match('/\b(SQLSTATE|PDOException|QueryException)\b/', $message);
    }

    private function relativePath(string $path): string
    {
        $base = base_path();
        if (str_starts_with($path, $base)) {
            return ltrim(substr($path, strlen($base)), DIRECTORY_SEPARATOR);
        }

        return $path;
    }

    private function truncate(string $value, int $max): string
    {
        if (mb_strlen($value) <= $max) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, $max - 1)).'…';
    }
}
