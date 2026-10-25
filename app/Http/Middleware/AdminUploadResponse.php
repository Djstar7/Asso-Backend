<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

class AdminUploadResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Let the browser perform the redirect once, so flashed messages survive.
        if ($request->is('admin/*') && ! $request->isMethodSafe()
            && $request->header('X-Admin-Upload') === '1'
            && $response instanceof RedirectResponse) {
            if (in_array('error', $request->session()->get('_flash.new', []), true)) {
                $message = (string) $request->session()->get('error');
                $request->session()->forget('error');

                return new JsonResponse(['message' => strip_tags($message)], 422);
            }
            $json = new JsonResponse(['redirect' => $response->getTargetUrl()]);
            foreach ($response->headers->getCookies() as $cookie) {
                $json->headers->setCookie($cookie);
            }

            return $json;
        }

        return $response;
    }
}
