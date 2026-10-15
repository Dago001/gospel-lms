<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\NotificationLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NotificationAdminController extends Controller
{
    public function log(Request $request): JsonResponse
    {
        $f = $request->validate(['status' => ['nullable', 'in:sent,failed,suppressed'], 'notification' => ['nullable', 'string', 'max:80']]);
        $p = NotificationLog::query()->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))->when($f['notification'] ?? null, fn ($q, $v) => $q->where('notification', $v))->orderByDesc('created_at')->paginate(50);

        return response()->json(['data' => $p->getCollection()->map(fn ($l) => ['id' => $l->id, 'notification' => $l->notification, 'channel' => $l->channel, 'status' => $l->status, 'error' => $l->error, 'created_at' => $l->created_at->toIso8601String()])->values(),
            'meta' => ['current_page' => $p->currentPage(), 'last_page' => $p->lastPage(), 'total' => $p->total()]]);
    }

    /** Delivery health for the last 7 days plus the queue's own failed-job count. */
    public function summary(): JsonResponse
    {
        return response()->json(['data' => [
            'last_7_days' => NotificationLog::where('created_at', '>=', now()->subDays(7))->select('status', DB::raw('count(*) as count'))->groupBy('status')->pluck('count', 'status'),
            'failed_jobs' => DB::table('failed_jobs')->count(), 'pending_jobs' => DB::table('jobs')->count(),
        ]]);
    }
}
