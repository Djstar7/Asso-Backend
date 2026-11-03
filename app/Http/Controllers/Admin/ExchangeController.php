<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\ContactClick;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ExchangeController extends Controller
{
    /**
     * Afficher le tableau de bord des échanges
     */
    public function index(Request $request)
    {
        // Période de filtrage (par défaut: 30 derniers jours)
        $startDate = $request->input('start_date', now()->subDays(30)->format('Y-m-d'));
        $endDate = $request->input('end_date', now()->format('Y-m-d'));

        // Statistiques globales
        $totalConversations = Conversation::whereBetween('created_at', [$startDate, $endDate])->count();
        $totalMessages = Message::whereBetween('created_at', [$startDate, $endDate])->count();
        $unreadMessages = Message::unread()->count();

        // Conversations récentes (dernières 20)
        $recentConversations = Conversation::with(['user1', 'user2', 'product', 'latestMessage'])
            ->recent()
            ->take(20)
            ->get();

        // Statistiques par jour pour les graphiques (30 derniers jours)
        $chartStartDate = now()->subDays(30);
        $chartEndDate = now();

        $dailyStats = [];
        for ($date = $chartStartDate->copy(); $date <= $chartEndDate; $date->addDay()) {
            $dateStr = $date->format('Y-m-d');
            $dailyStats[$dateStr] = [
                'conversations' => Conversation::whereDate('created_at', $dateStr)->count(),
                'messages' => Message::whereDate('created_at', $dateStr)->count(),
            ];
        }

        return view('admin.exchanges.index', compact(
            'totalConversations',
            'totalMessages',
            'unreadMessages',
            'recentConversations',
            'dailyStats',
            'startDate',
            'endDate'
        ));
    }

    /**
     * Afficher toutes les conversations
     */
    public function conversations(Request $request)
    {
        $query = Conversation::with(['user1', 'user2', 'product', 'latestMessage'])
            ->recent();

        // Filtres
        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('user1', function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%");
            })->orWhereHas('user2', function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%");
            });
        }

        if ($request->filled('unread') && $request->unread == '1') {
            $query->withUnread();
        }

        $conversations = $query->paginate(20);

        return view('admin.exchanges.conversations', compact('conversations'));
    }

    /**
     * Afficher une conversation spécifique
     */
    public function show(Conversation $conversation)
    {
        $conversation->load(['user1', 'user2', 'product.primaryImage', 'messages.sender']);

        return view('admin.exchanges.show', compact('conversation'));
    }

    /**
     * Afficher les statistiques de clics de contact
     */
    public function contactClicks(Request $request)
    {
        $query = ContactClick::with(['user', 'seller', 'product']);

        // Filtres
        if ($request->filled('contact_type')) {
            $query->where('contact_type', $request->contact_type);
        }

        if ($request->filled('seller_id')) {
            $query->where('seller_id', $request->seller_id);
        }

        if ($request->filled('start_date') && $request->filled('end_date')) {
            $query->whereBetween('created_at', [
                $request->start_date,
                $request->end_date
            ]);
        }

        $clicks = $query->orderBy('created_at', 'desc')->paginate(30);

        // Liste des vendeurs pour le filtre
        $sellers = User::whereHas('contactClicksAsSeller')
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();

        return view('admin.exchanges.contact-clicks', compact('clicks', 'sellers'));
    }
}
