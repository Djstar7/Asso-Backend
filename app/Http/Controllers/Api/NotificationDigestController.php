<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\NotificationDigestItem;
use App\Services\NotificationDigestService;
use Illuminate\Http\Request;

class NotificationDigestController extends Controller
{
    public function show(Request $request, string $date, NotificationDigestService $digests)
    {
        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $date), 404);
        abort_unless(Notification::where('user_id', $request->user()->id)->where('type', 'marketing_digest')->where('data->digest_date', $date)->exists(), 404);
        try {
            [$start, $end] = $digests->range($date);
        } catch (\Throwable $e) {
            abort(404);
        }
        $items = NotificationDigestItem::where('created_at', '>=', $start)->where('created_at', '<', $end)
            ->where('created_at', '>=', $request->user()->created_at)
            ->where(fn ($q) => $q->whereNull('data->recipient_ids')->orWhereJsonContains('data->recipient_ids', $request->user()->id))->orderBy('id')->paginate(30);
        $locale = app()->getLocale();

        return response()->json(['success' => true, 'date' => $date,
            'items' => $items->getCollection()->map(function ($item) use ($locale) {
                $texts = $item->texts[$locale] ?? $item->texts['fr'] ?? (array_values($item->texts)[0] ?? ['', '']);

                return ['id' => $item->id, 'title' => $texts[0], 'body' => $texts[1], 'data' => array_diff_key($item->data, ['recipient_ids' => true])];
            }), 'next_page' => $items->hasMorePages() ? $items->currentPage() + 1 : null]);
    }
}
