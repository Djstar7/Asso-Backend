<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Notification;
use App\Services\FirebaseMessagingService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class NotificationController extends Controller
{
    protected $fcmService;

    public function __construct(FirebaseMessagingService $fcmService)
    {
        $this->fcmService = $fcmService;
    }

    /**
     * Envoyer une notification à un utilisateur spécifique
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function sendToUser(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
            'title' => 'required|string|max:255',
            'body' => 'required|string',
            'data' => 'nullable|array',
            'platform' => 'nullable|in:android,ios,web,macos,windows',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = User::find($request->user_id);

        $result = $this->fcmService->sendToUser(
            $user,
            $request->title,
            $request->body,
            $request->data ?? [],
            $request->platform
        );

        return response()->json($result);
    }

    /**
     * Envoyer une notification à plusieurs utilisateurs
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function sendToUsers(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_ids' => 'required|array',
            'user_ids.*' => 'exists:users,id',
            'title' => 'required|string|max:255',
            'body' => 'required|string',
            'data' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $result = $this->fcmService->sendToUsers(
            $request->user_ids,
            $request->title,
            $request->body,
            $request->data ?? []
        );

        return response()->json($result);
    }

    /**
     * Envoyer une notification à tous les utilisateurs
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function sendToAll(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'body' => 'required|string',
            'data' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $result = $this->fcmService->sendToAll(
            $request->title,
            $request->body,
            $request->data ?? []
        );

        return response()->json($result);
    }

    /**
     * Envoyer une notification à un topic
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function sendToTopic(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'topic' => 'required|string',
            'title' => 'required|string|max:255',
            'body' => 'required|string',
            'data' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $result = $this->fcmService->sendToTopic(
            $request->topic,
            $request->title,
            $request->body,
            $request->data ?? []
        );

        return response()->json($result);
    }

    /**
     * Envoyer une notification de test à l'utilisateur authentifié
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function sendTestNotification(Request $request): JsonResponse
    {
        $user = $request->user();

        $result = $this->fcmService->sendToUser(
            $user,
            'Test Notification',
            'Ceci est une notification de test depuis Asso!',
            [
                'type' => 'test',
                'timestamp' => now()->toIso8601String(),
            ]
        );

        return response()->json($result);
    }

    /**
     * Récupérer toutes les notifications de l'utilisateur
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $perPage = $request->input('per_page', 20);

        $notifications = $user->notifications()
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'notifications' => $notifications->items(),
            'unread_count' => $user->notifications()->unread()->count(),
            'pagination' => [
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
                'per_page' => $notifications->perPage(),
                'total' => $notifications->total(),
            ],
        ]);
    }

    /**
     * Récupérer uniquement les notifications non lues
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function unread(Request $request): JsonResponse
    {
        $user = $request->user();

        $notifications = $user->notifications()
            ->unread()
            ->get();

        return response()->json([
            'success' => true,
            'notifications' => $notifications,
            'count' => $notifications->count(),
        ]);
    }

    /**
     * Marquer une notification comme lue
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function markAsRead(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $notification = Notification::where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (!$notification) {
            return response()->json([
                'success' => false,
                'message' => 'Notification not found',
            ], 404);
        }

        $notification->markAsRead();

        return response()->json([
            'success' => true,
            'message' => 'Notification marked as read',
            'notification' => $notification,
        ]);
    }

    /**
     * Marquer toutes les notifications comme lues
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        $user = $request->user();

        $updated = $user->notifications()
            ->unread()
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);

        return response()->json([
            'success' => true,
            'message' => 'All notifications marked as read',
            'updated_count' => $updated,
        ]);
    }

    /**
     * Supprimer une notification
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $notification = Notification::where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (!$notification) {
            return response()->json([
                'success' => false,
                'message' => 'Notification not found',
            ], 404);
        }

        $notification->delete();

        return response()->json([
            'success' => true,
            'message' => 'Notification deleted',
        ]);
    }

    /**
     * Supprimer toutes les notifications
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function destroyAll(Request $request): JsonResponse
    {
        $user = $request->user();

        $deleted = $user->notifications()->delete();

        return response()->json([
            'success' => true,
            'message' => 'All notifications deleted',
            'deleted_count' => $deleted,
        ]);
    }

    /**
     * Compter les notifications non lues
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $user = $request->user();

        $count = $user->notifications()->unread()->count();

        return response()->json([
            'success' => true,
            'unread_count' => $count,
        ]);
    }
}
