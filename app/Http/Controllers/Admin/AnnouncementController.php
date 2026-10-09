<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\User;
use App\Services\FirebaseMessagingService;
use App\Support\Translation\ContentLocale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AnnouncementController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $announcements = Announcement::with('user')
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        return view('admin.announcements.index', compact('announcements'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $users = User::orderBy('name')->get();
        return view('admin.announcements.create', compact('users'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'message' => 'required|string',
            'channel' => 'required|in:sms,whatsapp,email,push',
            'target_type' => 'required|in:all,specific',
            'user_id' => 'nullable|exists:users,id|required_if:target_type,specific',
            'scheduled_at' => 'nullable|date|after:now',
        ] + ContentLocale::rules(['title' => 'string|max:255', 'message' => 'string']));

        try {
            // Déterminer le statut
            if ($request->filled('scheduled_at')) {
                $validated['status'] = 'scheduled';
            } else {
                $validated['status'] = 'draft';
            }

            $announcement = Announcement::create($validated);
            $announcement->syncTranslations($validated['translations'] ?? null);

            return redirect()->route('admin.announcements.index')
                ->with('success', 'Annonce créée avec succès');
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'Erreur lors de la création: ' . $e->getMessage())
                ->withInput();
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Announcement $announcement)
    {
        $announcement->load('user');
        return view('admin.announcements.show', compact('announcement'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Announcement $announcement)
    {
        $users = User::orderBy('name')->get();
        return view('admin.announcements.edit', compact('announcement', 'users'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Announcement $announcement)
    {
        // Ne pas permettre la modification d'une annonce déjà envoyée
        if ($announcement->status === 'sent') {
            return redirect()->back()
                ->with('error', 'Impossible de modifier une annonce déjà envoyée');
        }

        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'message' => 'required|string',
            'channel' => 'required|in:sms,whatsapp,email,push',
            'target_type' => 'required|in:all,specific',
            'user_id' => 'nullable|exists:users,id|required_if:target_type,specific',
            'scheduled_at' => 'nullable|date|after:now',
        ] + ContentLocale::rules(['title' => 'string|max:255', 'message' => 'string']));

        try {
            // Déterminer le statut
            if ($request->filled('scheduled_at')) {
                $validated['status'] = 'scheduled';
            } else {
                $validated['status'] = 'draft';
            }

            $announcement->update($validated);
            $announcement->syncTranslations($validated['translations'] ?? null);

            return redirect()->route('admin.announcements.index')
                ->with('success', 'Annonce mise à jour avec succès');
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'Erreur lors de la mise à jour: ' . $e->getMessage())
                ->withInput();
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Announcement $announcement)
    {
        try {
            $announcement->delete();

            return redirect()->route('admin.announcements.index')
                ->with('success', 'Annonce supprimée avec succès');
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'Erreur lors de la suppression: ' . $e->getMessage());
        }
    }

    /**
     * Send the announcement immediately.
     */
    public function send(Announcement $announcement)
    {
        // Ne pas permettre l'envoi d'une annonce déjà envoyée
        if ($announcement->status === 'sent') {
            return redirect()->back()
                ->with('error', 'Cette annonce a déjà été envoyée');
        }

        try {
            $successCount = 0;
            $failureCount = 0;
            $recipientCount = 0;

            // Logique d'envoi selon le canal
            if ($announcement->channel === 'push') {
                $fcmService = app(FirebaseMessagingService::class);

                // Titre et message dans chaque langue (repli : le français saisi).
                $texts = $this->texts($announcement);
                [$title, $body] = $texts['fr'];
                $data = [
                    'type' => 'announcement',
                    'announcement_id' => $announcement->id,
                    'created_at' => $announcement->created_at->toIso8601String(),
                ];

                if ($announcement->target_type === 'all') {
                    Log::info("Envoi de notification push à tous les utilisateurs via topic");

                    // Envoi via topic pour tous les utilisateurs (plus efficace)
                    $result = $fcmService->sendToAllLocalized($texts, $data);

                    if ($result['success']) {
                        $successCount = User::count(); // Estimation pour le topic
                        $recipientCount = $successCount;
                        Log::info("Notification push envoyée avec succès à tous les utilisateurs");
                    } else {
                        // Si le topic échoue, utiliser l'envoi par batch
                        Log::warning("Échec de l'envoi via topic, utilisation de l'envoi par batch");
                        $batchResult = $fcmService->sendToAllByBatch($title, $body, $data);

                        if ($batchResult['success']) {
                            $successCount = $batchResult['success_count'];
                            $failureCount = $batchResult['failure_count'];
                            $recipientCount = $successCount + $failureCount;
                        }
                    }
                } else {
                    // Envoi à un utilisateur spécifique
                    $user = User::find($announcement->user_id);

                    if ($user) {
                        Log::info("Envoi de notification push à l'utilisateur {$user->id}");
                        [$userTitle, $userBody] = $texts[$user->preferredLocale()] ?? $texts['fr'];
                        $result = $fcmService->sendToUser($user, $userTitle, $userBody, $data);

                        if ($result['success']) {
                            $successCount = $result['success_count'] ?? 1;
                            $failureCount = $result['failure_count'] ?? 0;
                            $recipientCount = 1;
                        }
                    }
                }
            } else {
                // Pour les autres canaux (SMS, WhatsApp, Email), à implémenter plus tard
                Log::info("Canal {$announcement->channel} pas encore implémenté");

                // Calculer le nombre de destinataires
                if ($announcement->target_type === 'all') {
                    $recipientCount = User::count();
                } else {
                    $recipientCount = 1;
                }

                $successCount = $recipientCount;
            }

            // Mettre à jour le statut de l'annonce
            $announcement->update([
                'status' => 'sent',
                'sent_at' => now(),
                'sent_count' => $successCount,
                'failed_count' => $failureCount,
            ]);

            $message = 'Annonce envoyée avec succès';
            if ($announcement->channel === 'push') {
                $message .= " - {$successCount} envoi(s) réussi(s)";
                if ($failureCount > 0) {
                    $message .= ", {$failureCount} échec(s)";
                }
            } else {
                $message .= " à {$recipientCount} utilisateur(s)";
            }

            return redirect()->route('admin.announcements.index')
                ->with('success', $message);
        } catch (\Exception $e) {
            Log::error('Erreur lors de l\'envoi de l\'annonce: ' . $e->getMessage());
            return redirect()->back()
                ->with('error', 'Erreur lors de l\'envoi: ' . $e->getMessage());
        }
    }

    /** @return array<string, array{0: string, 1: string}> langue => [titre, message] */
    private function texts(Announcement $announcement): array
    {
        $texts = [];
        foreach (\App\Http\Middleware\SetLocale::SUPPORTED as $locale) {
            $texts[$locale] = [
                $announcement->getTranslation('title', $locale)
                    ?? $announcement->getTranslation('title', 'fr')
                    ?? __('notifications.announcement_default_title', [], $locale),
                $announcement->getTranslation('message', $locale) ?? $announcement->getTranslation('message', 'fr'),
            ];
        }

        return $texts;
    }
}
