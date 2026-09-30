<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Langue des réponses de l'API : celle annoncée par l'application
 * (Accept-Language), à défaut celle enregistrée sur le compte, sinon le
 * français.
 */
class SetLocale
{
    public const SUPPORTED = ['fr', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        App::setLocale($this->resolve($request));

        return $next($request);
    }

    private function resolve(Request $request): string
    {
        if (filled($request->headers->get('Accept-Language'))) {
            return $request->getPreferredLanguage(self::SUPPORTED) ?? 'fr';
        }

        $user = $request->user('sanctum');
        if ($user && in_array($user->locale, self::SUPPORTED, true)) {
            return $user->locale;
        }

        return config('app.locale', 'fr');
    }
}
