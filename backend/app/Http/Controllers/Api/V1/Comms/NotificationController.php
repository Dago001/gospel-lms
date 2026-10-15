<?php

namespace App\Http\Controllers\Api\V1\Comms;

use App\Http\Controllers\Controller;
use App\Listeners\ApplyNotificationPreferences;
use App\Models\NotificationPreference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = $request->user()->notifications()->when($request->boolean('unread'), fn ($q) => $q->whereNull('read_at'))->latest();
        $p = $q->paginate(20);

        return response()->json(['data' => $p->getCollection()->map(fn ($n) => ['id' => $n->id, 'data' => $n->data, 'read' => (bool) $n->read_at, 'created_at' => $n->created_at->toIso8601String()])->values(),
            'meta' => ['current_page' => $p->currentPage(), 'last_page' => $p->lastPage(), 'total' => $p->total(), 'unread' => $request->user()->unreadNotifications()->count()]]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json(['data' => ['unread' => $request->user()->unreadNotifications()->count()]]);
    }

    /** Scoped to the signed-in user's own notifications: another user's id is simply "not found". */
    public function read(Request $request, string $id): JsonResponse
    {
        $request->user()->notifications()->whereKey($id)->firstOrFail()->markAsRead();

        return response()->json(['message' => 'Marked as read.']);
    }

    public function readAll(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['message' => 'All marked as read.']);
    }

    public function preferences(Request $request): JsonResponse
    {
        $saved = NotificationPreference::where('user_id', $request->user()->id)->get()->keyBy('type');

        return response()->json(['data' => collect(ApplyNotificationPreferences::keys())->map(fn ($k) => ['type' => $k, 'email' => (bool) ($saved[$k]->email ?? true), 'in_app' => (bool) ($saved[$k]->in_app ?? true)])->values(),
            'mandatory' => ['Security alerts', 'Payment confirmations', 'Account verification and password reset']]);
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        $d = $request->validate(['preferences' => ['required', 'array', 'max:20'], 'preferences.*.type' => ['required', 'in:'.implode(',', ApplyNotificationPreferences::keys())],
            'preferences.*.email' => ['required', 'boolean'], 'preferences.*.in_app' => ['required', 'boolean']]);
        foreach ($d['preferences'] as $p) {
            NotificationPreference::updateOrCreate(['user_id' => $request->user()->id, 'type' => $p['type']], ['email' => $p['email'], 'in_app' => $p['in_app']]);
        }

        return $this->preferences($request);
    }
}
