<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Routes de l'API réservées au compte admin (envoi de notifications à d'autres utilisateurs…). */
class EnsureApiAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json([
                'success' => false,
                'message' => __('common.unauthorized'),
            ], 403);
        }

        return $next($request);
    }
}
