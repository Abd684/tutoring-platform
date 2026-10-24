<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class AdminLocale
{
    public function handle(Request $request, Closure $next)
    {
        $previous = app()->getLocale();
        app()->setLocale('ar');
        try {
            $response = $next($request);
            $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
            $response->headers->set('X-Content-Type-Options', 'nosniff');
            $response->headers->set('X-Frame-Options', 'DENY');
            $response->headers->set('Referrer-Policy', 'same-origin');
            $response->headers->set('Content-Security-Policy', "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self'; object-src 'self'; frame-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");

            return $response;
        } finally {
            app()->setLocale($previous);
        }
    }
}
