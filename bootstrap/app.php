<?php

// Composer's vendor-dir is build/vendor (composer.json) – tell Laravel's package manifest where to find it.
if (! getenv('COMPOSER_VENDOR_DIR')) {
    putenv('COMPOSER_VENDOR_DIR='.dirname(__DIR__).'/build/vendor');
    $_ENV['COMPOSER_VENDOR_DIR'] = $_SERVER['COMPOSER_VENDOR_DIR'] = dirname(__DIR__).'/build/vendor';
}

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
        $middleware->alias([
            'tenant' => \App\Http\Middleware\SetTenant::class,
            'perm' => \App\Http\Middleware\RequirePermission::class,
            'twofactor' => \App\Http\Middleware\RequireTwoFactor::class,
            'api.client' => \App\Http\Middleware\ApiAuthenticate::class,   // v4.0 REST API
            'api.scope' => \App\Http\Middleware\ApiScope::class,
            'partner' => \App\Http\Middleware\PartnerAuth::class,      // v7.4 partner portal session (no route-model binding on its routes)
            'organiser' => \App\Http\Middleware\OrganiserAuth::class,  // v7.5 organiser portal session (no route-model binding on its routes)
        ]);
        // Tenant must be resolved BEFORE route-model binding so bound models are hotel-scoped (prevents IDOR)
        $middleware->prependToPriorityList(
            before: \Illuminate\Routing\Middleware\SubstituteBindings::class,
            prepend: \App\Http\Middleware\SetTenant::class,
        );
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => auth()->user() ? \App\Http\Controllers\Auth\LoginController::home(auth()->user()) : route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->dontFlash(['current_password', 'password', 'password_confirmation', 'sms_twilio_token', 'sms_hubtel_client_secret', 'sms_http_token',
            'pay_paystack_secret', 'pay_stripe_secret', 'pay_stripe_webhook_secret', 'pay_razorpay_key_secret', 'pay_razorpay_webhook_secret']);
        // v4.0: /api/v1 always answers {"error": {"code", "message", "fields"?}, "request_id"} – never HTML, never a stack trace
        $exceptions->render(function (\Throwable $e, Request $request) {
            if (! $request->is('api/v1', 'api/v1/*')) {
                return null;
            }
            $err = fn (string $code, string $msg, int $status, array $extra = []) => \App\Http\Middleware\ApiAuthenticate::error($code, $msg, $status, [], $extra);

            return match (true) {
                $e instanceof \Illuminate\Validation\ValidationException => $err('validation_failed', $e->validator->errors()->first() ?: 'The request is not valid.', 422, ['fields' => $e->errors()]),
                $e instanceof \App\Services\ApiConflict => $err('conflict', $e->getMessage(), 409, $e->reservation ? ['reservation_no' => $e->reservation->reservation_no] : []),
                $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException,
                $e instanceof \Symfony\Component\HttpKernel\Exception\NotFoundHttpException => $err('not_found', 'No such resource.', 404),
                $e instanceof \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException => $err('method_not_allowed', 'Method not allowed on this path.', 405),
                $e instanceof \Illuminate\Http\Exceptions\ThrottleRequestsException => $err('rate_limited', 'Too many requests.', 429),
                $e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface => $err('http_'.$e->getStatusCode(), $e->getMessage() ?: 'Request refused.', $e->getStatusCode()),
                get_class($e) === \RuntimeException::class => $err('unprocessable', $e->getMessage(), 422),
                default => $err('server_error', 'Something went wrong on our side – quote the request_id to the hotel.', 500),
            };
        });
        // Business-rule violations become friendly flash messages
        $exceptions->render(function (\RuntimeException $e, Request $request) {
            if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
                return null;
            }
            if (! $request->expectsJson() && $request->user() && get_class($e) === \RuntimeException::class) {
                return back()->withInput()->with('error', $e->getMessage());
            }
        });
    })->create();
