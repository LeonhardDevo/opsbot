<?php

declare(strict_types=1);

use App\Billing\PlanLimitExceeded;
use App\Domain\Opsbot\OpsbotException;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureFakeBilling;
use App\Http\Middleware\EnsureHasWorkspace;
use App\Http\Middleware\EnsureInternalCaller;
use App\Http\Middleware\EnsureLegalAccepted;
use App\Http\Middleware\EnsureWithinPlanLimit;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\VerifyInternalSignature;
use App\Locale\WithLocale;
use App\Support\Flash;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::middleware('internal')
                ->prefix('internal/v1')
                ->name('internal.')
                ->group(base_path('routes/internal.php'));

            Route::middleware('web')->group(base_path('routes/share.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state', 'locale']);

        $middleware->prepend(AssignRequestId::class);
        $middleware->append(SecurityHeaders::class);

        $middleware->web(append: [
            SetLocale::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            EnsureLegalAccepted::class,
        ]);

        $middleware->validateCsrfTokens(except: ['email/opt-out/*']);

        $middleware->group('internal', [
            VerifyInternalSignature::class,
            'throttle:internal',
            SubstituteBindings::class,
        ]);

        $middleware->alias([
            'workspace' => EnsureHasWorkspace::class,
            'plan.limit' => EnsureWithinPlanLimit::class,
            'fake.billing' => EnsureFakeBilling::class,
            'internal.caller' => EnsureInternalCaller::class,
        ]);

        $middleware->redirectUsersTo(fn (): string => route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*', 'internal/*') || $request->expectsJson(),
        );

        $exceptions->dontFlash(['current_password', 'password', 'password_confirmation', 'api_key', 'token']);

        $exceptions->render(function (OpsbotException $exception, Request $request) {
            if ($request->is('internal/*') || ($request->expectsJson() && ! $request->header('X-Inertia'))) {
                return response()->json([
                    'ok' => false,
                    'error' => $exception->key,
                    'message' => $exception->userMessage(),
                ], $exception->status);
            }

            if ($exception->status === 404 || ($request->isMethod('GET') && $exception->status === 403)) {
                abort($exception->status, $exception->userMessage());
            }

            Flash::errorMessage($exception->userMessage());

            return back();
        });

        $exceptions->render(function (ThrottleRequestsException $exception, Request $request) {
            if (! $request->header('X-Inertia') || $request->is('internal/*') || $request->isMethod('GET')) {
                return null;
            }

            $seconds = (int) ($exception->getHeaders()['Retry-After'] ?? 60);
            $login = $request->routeIs('login', 'login.store');
            $message = (string) WithLocale::run(
                SetLocale::resolve($request),
                fn (): string => (string) __($login ? 'auth.throttle' : 'errors.too_many_requests', ['seconds' => $seconds]),
            );

            Flash::errorMessage($message);

            return back()
                ->withErrors($login ? ['email' => $message] : [])
                ->withInput($request->except(['password', 'password_confirmation', 'current_password']));
        });

        $exceptions->render(function (PlanLimitExceeded $exception, Request $request) {
            if ($request->is('internal/*') || ($request->expectsJson() && ! $request->header('X-Inertia'))) {
                return response()->json([
                    'ok' => false,
                    'error' => $exception->errorKey(),
                    'limit' => $exception->limitKey,
                    'message' => $exception->userMessage(),
                ], 402);
            }

            Flash::limit($exception->limitKey, $exception->userMessage());

            return back();
        });

        $exceptions->respond(function (SymfonyResponse $response, Throwable $exception, Request $request): SymfonyResponse {
            $status = $response->getStatusCode();

            if (! in_array($status, [419, 429, 503], true) || $request->header('X-Inertia') === null || $request->is('api/*', 'internal/*')) {
                return $response;
            }

            $retryAfter = (int) ($response->headers->get('Retry-After') ?? 60);
            Flash::errorMessage((string) __('errors.http.'.$status, ['seconds' => max(1, $retryAfter)]));

            return redirect()->back(303);
        });
    })->create();
