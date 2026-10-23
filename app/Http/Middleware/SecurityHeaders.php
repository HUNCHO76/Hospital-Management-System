<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds baseline HTTP security headers to every response.
 *
 * A strict Content-Security-Policy is intentionally NOT set here: the Vite/
 * Inertia front end would need a tested, nonce-based policy, and shipping a
 * broken CSP is worse than none. That is tracked as future hardening in
 * docs/security.md. HSTS is only emitted over HTTPS so local http development
 * is unaffected.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = $response->headers;

        // Prevent MIME-type sniffing.
        $headers->set('X-Content-Type-Options', 'nosniff');

        // Disallow framing by other origins (clickjacking protection).
        $headers->set('X-Frame-Options', 'SAMEORIGIN');

        // Limit how much referrer information is sent cross-origin.
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Disable powerful browser features the HMS does not use.
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');

        // Disable the legacy XSS auditor (OWASP-recommended for modern browsers).
        $headers->set('X-XSS-Protection', '0');

        // Content Security Policy (SEC-08)
        $csp = env('CONTENT_SECURITY_POLICY');
        if ($csp === null) {
            $isProduction = app()->environment('production');

            $scriptSrc = $isProduction
                ? "script-src 'self' 'unsafe-inline' 'unsafe-eval'"
                : "script-src 'self' 'unsafe-inline' 'unsafe-eval' http: https:";

            $styleSrc = $isProduction
                ? "style-src 'self' 'unsafe-inline' https://fonts.bunny.net https://fonts.googleapis.com"
                : "style-src 'self' 'unsafe-inline' https://fonts.bunny.net https://fonts.googleapis.com http:";

            $connectSrc = $isProduction
                ? "connect-src 'self' ws: wss:"
                : "connect-src 'self' http: https: ws: wss:";

            $imgSrc = "img-src 'self' data: blob:".($isProduction ? '' : ' http: https:');

            $csp = implode('; ', [
                "default-src 'self'",
                $scriptSrc,
                $styleSrc,
                "font-src 'self' data: https://fonts.bunny.net https://fonts.gstatic.com",
                $imgSrc,
                $connectSrc,
                "object-src 'none'",
                "base-uri 'self'",
                "frame-ancestors 'self'",
            ]);
        }

        if (filled($csp)) {
            $headers->set('Content-Security-Policy', $csp);
        }

        // Enforce HTTPS for a year — only when the request is already secure,
        // so it never interferes with local http development.
        if ($request->secure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
