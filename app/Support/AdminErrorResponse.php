<?php

namespace App\Support;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class AdminErrorResponse
{
    public static function render(Response $response, Throwable $exception, Request $request): Response
    {
        $status = $response->getStatusCode();
        if (! $request->is('admin/*') || $status < 400 || $exception instanceof \Illuminate\Validation\ValidationException) {
            return $response;
        }
        if ($exception instanceof \Illuminate\Http\Exceptions\HttpResponseException && $status < 500) {
            return $response;
        }
        $message = match ($status) {
            401, 419 => 'Votre session a expiré. Reconnectez-vous pour continuer.',
            403 => 'Vous n’avez pas les droits nécessaires pour cette action.',
            404 => 'Cet élément est introuvable ou n’est plus disponible.',
            405 => 'Cette action n’est pas disponible à cette adresse.',
            409 => 'Cet élément a changé. Actualisez la page avant de réessayer.',
            413 => 'Les fichiers dépassent la taille autorisée par le serveur.',
            429 => 'Trop de tentatives. Patientez avant de réessayer.',
            503 => 'Le service est temporairement indisponible. Réessayez plus tard.',
            default => 'L’action n’a pas pu être terminée. Vérifiez son état avant de réessayer.',
        };
        $headers = ['Cache-Control' => 'no-store, private'];
        if ($response->headers->has('Retry-After')) {
            $headers['Retry-After'] = $response->headers->get('Retry-After');
        }

        return $request->expectsJson()
            ? response()->json(['success' => false, 'message' => $message], $status, $headers)
            : response()->view('errors.admin', compact('status', 'message'), $status, $headers);
    }
}
