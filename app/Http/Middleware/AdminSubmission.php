<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

class AdminSubmission
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->input('_submission_id');
        if (! $request->is('admin/*') || $request->isMethodSafe() || $request->header('X-Admin-Upload') !== '1'
            || ! $request->user() || ! is_string($token) || ! Str::isUuid($token)) {
            return $next($request);
        }
        $key = 'admin-submission:'.hash('sha256', $request->user()->id.'|'.$request->method().'|'.$request->path().'|'.$token);
        try {
            return Cache::lock($key.':lock', 600)->block(1, function () use ($key, $request, $next) {
                if ($saved = Cache::get($key)) {
                    if ($saved['success']) {
                        $request->session()->flash('success', $saved['success']);
                    }

                    return redirect($saved['redirect']);
                }
                $response = $next($request);
                if ($response instanceof RedirectResponse && ! in_array('error', $request->session()->get('_flash.new', []), true)
                    && ! in_array('errors', $request->session()->get('_flash.new', []), true)) {
                    Cache::put($key, ['redirect' => $response->getTargetUrl(), 'success' => $request->session()->get('success')], now()->addDay());
                }

                return $response;
            });
        } catch (LockTimeoutException $e) {
            return response()->json(['message' => 'Cet enregistrement est encore en cours. Patientez avant de réessayer.'], 409);
        }
    }
}
