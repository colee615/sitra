<?php

use Illuminate\Auth\AuthenticationException;
use App\Http\Support\CustomsApiError;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\ConnectionException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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
        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if ($request->is('api/v1/postal', 'api/v1/postal/*')) {
                return CustomsApiError::response(401, 'No autenticado. Envía un token Bearer válido.');
            }

            if ($request->is('api/*')) {
                return response()->json(['message' => 'No autenticado. Envía un token Bearer válido.'], 401);
            }
        });
        $exceptions->render(function (Throwable $exception, Request $request) {
            if (!$request->is('api/v1/postal', 'api/v1/postal/*')) {
                return null;
            }

            if ($exception instanceof ValidationException) {
                return CustomsApiError::response(
                    422,
                    'Los parámetros de consulta no son válidos.',
                    $exception->errors(),
                );
            }

            if ($exception instanceof AuthorizationException) {
                return CustomsApiError::response(403, 'La credencial no está autorizada para esta operación.');
            }

            if ($exception instanceof HttpExceptionInterface) {
                $status = $exception->getStatusCode();
                $message = match ($status) {
                    403 => 'La credencial no está autorizada para esta operación.',
                    404 => 'No se encontró la ruta solicitada.',
                    405 => 'El método HTTP no está permitido para esta ruta.',
                    429 => 'Se superó el límite de solicitudes.',
                    500 => 'No se pudo completar la consulta aduanera.',
                    503 => 'Una fuente de datos necesaria no está disponible temporalmente.',
                    default => 'No se pudo completar la solicitud. Revisa el código HTTP.',
                };

                return CustomsApiError::response($status, $message, headers: $exception->getHeaders());
            }

            $requestId = (string) Str::uuid();
            $status = $exception instanceof ConnectionException ? 503 : 500;
            Log::log($status === 503 ? 'warning' : 'error', 'Customs API middleware failed.', [
                'request_id' => $requestId,
                'exception_type' => $exception::class,
            ]);

            return CustomsApiError::response(
                $status,
                $status === 503
                    ? 'Una fuente de datos necesaria no está disponible temporalmente.'
                    : 'No se pudo completar la consulta aduanera.',
                requestId: $requestId,
            );
        });
    })->create();
