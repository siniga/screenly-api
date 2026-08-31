<?php

use App\Exceptions\GenerationFailedException;
use App\Services\SystemErrorLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (Throwable $e, $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            if (
                $e instanceof ValidationException
                || $e instanceof AuthenticationException
                || $e instanceof AuthorizationException
                || $e instanceof HttpResponseException
                || $e instanceof ModelNotFoundException
                || $e instanceof HttpExceptionInterface
            ) {
                return null;
            }

            $status = $e instanceof GenerationFailedException ? $e->status : 500;
            $message = app(SystemErrorLogger::class)->log($e, [
                'source' => 'api',
                'status' => $status,
            ]);

            return response()->json([
                'success' => false,
                'message' => $message,
            ], $status);
        });
    })->create();
