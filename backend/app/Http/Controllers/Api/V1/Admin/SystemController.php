<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\BackupAuditRecord;
use App\Services\AuditLogger;
use App\Services\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class SystemController extends Controller
{
    public function settings(): JsonResponse
    {
        return response()->json(['data' => collect(Settings::ALLOWED)->map(fn ($meta, $key) => ['key' => $key, 'description' => $meta[1], 'value' => Settings::get($key), 'default' => config('academy.'.str_replace('academy.', '', $key))])->values()]);
    }

    public function updateSettings(Request $request, AuditLogger $audit): JsonResponse
    {
        $in = $request->validate(['settings' => ['required', 'array'], 'settings.*' => ['nullable']])['settings'];
        $errors = [];
        foreach ($in as $k => $v) {
            if (! isset(Settings::ALLOWED[$k])) {
                $errors["settings.$k"] = ['Unknown setting.'];
                continue;
            }
            $v = Validator::make(['v' => $v], ['v' => Settings::ALLOWED[$k][0]]);
            if ($v->fails()) {
                $errors["settings.$k"] = $v->errors()->get('v');
            }
        }
        if ($errors) {
            return response()->json(['message' => 'Invalid settings.', 'errors' => $errors], 422);
        }
        $old = array_intersect_key(Settings::all(), $in);
        foreach ($in as $k => $v) {
            Settings::put($k, $v === '' ? null : $v, $request->user()->id);
        }
        $audit->log('settings.updated', null, $old, $in);

        return $this->settings();
    }

    /** Which integrations are configured. Values are never returned. */
    public function integrations(): JsonResponse
    {
        $set = fn (...$keys) => collect($keys)->every(fn ($k) => filled(config($k)));
        $paystackKey = (string) config('services.paystack.secret_key');
        $rows = [
            ['name' => 'Paystack', 'configured' => $set('services.paystack.secret_key', 'services.paystack.public_key'), 'mode' => config('services.paystack.mode'),
                'warning' => ($paystackKey && ! str_starts_with($paystackKey, config('services.paystack.mode') === 'live' ? 'sk_live_' : 'sk_test_')) ? 'Key type does not match PAYSTACK_MODE' : null],
            ['name' => 'Cloudflare Stream', 'configured' => $set('services.cloudflare_stream.account_id', 'services.cloudflare_stream.api_token', 'services.cloudflare_stream.customer_code', 'services.cloudflare_stream.key_id', 'services.cloudflare_stream.key_pem_b64', 'services.cloudflare_stream.webhook_secret')],
            ['name' => 'Zoom', 'configured' => $set('services.zoom.account_id', 'services.zoom.client_id', 'services.zoom.client_secret', 'services.zoom.webhook_secret_token')],
            ['name' => 'Email delivery', 'configured' => ! in_array(config('mail.default'), ['log', 'array'], true), 'mode' => config('mail.default')],
            ['name' => 'Private object storage', 'configured' => config('filesystems.disks.private.driver') === 's3' && filled(config('filesystems.disks.private.bucket')), 'mode' => config('filesystems.disks.private.driver')],
            ['name' => 'Redis (queue/cache)', 'configured' => config('queue.default') === 'redis' && config('cache.default') === 'redis', 'mode' => config('queue.default').'/'.config('cache.default')],
            ['name' => 'Malware scanning (ClamAV)', 'configured' => (bool) config('services.clamav.enabled')],
            ['name' => 'Error monitoring (Sentry)', 'configured' => filled(config('sentry.dsn'))],
        ];

        return response()->json(['data' => $rows]);
    }

    public function health(): JsonResponse
    {
        $checks = [];
        $t = microtime(true);
        try {
            DB::select('select 1');
            $checks['database'] = ['ok' => true, 'ms' => round((microtime(true) - $t) * 1000, 1)];
        } catch (\Throwable) {
            $checks['database'] = ['ok' => false];
        }
        try {
            Cache::put('health.probe', 1, 10);
            $checks['cache'] = ['ok' => Cache::get('health.probe') === 1, 'driver' => config('cache.default')];
        } catch (\Throwable) {
            $checks['cache'] = ['ok' => false];
        }
        try {
            $disk = Storage::disk('private');
            $disk->put('.health', 'ok');
            $checks['storage'] = ['ok' => $disk->get('.health') === 'ok'];
            $disk->delete('.health');
        } catch (\Throwable) {
            $checks['storage'] = ['ok' => false];
        }
        $oldest = DB::table('jobs')->min('created_at');
        $failed = DB::table('failed_jobs')->count();
        $checks['queue'] = ['ok' => $failed < 20 && (! $oldest || $oldest > now()->subMinutes(15)->timestamp), 'pending' => DB::table('jobs')->count(), 'failed' => $failed];
        $beat = Cache::get('scheduler.heartbeat');
        $checks['scheduler'] = ['ok' => $beat && $beat > now()->subMinutes(5)->timestamp, 'last_beat' => $beat ? date('c', $beat) : null];
        $lastBackup = BackupAuditRecord::where(['type' => 'backup', 'status' => 'ok'])->latest('started_at')->first();
        $lastRestore = BackupAuditRecord::where(['type' => 'restore_test', 'status' => 'ok'])->latest('started_at')->first();
        $checks['backups'] = ['ok' => (bool) ($lastBackup && $lastBackup->started_at->gt(now()->subHours(26))), 'last_backup' => $lastBackup?->started_at->toIso8601String(), 'last_restore_test' => $lastRestore?->started_at->toIso8601String()];

        try {
            $prot = \App\Support\DbCompat::appendOnlyActive();
            $checks['log_protection'] = ['ok' => ! in_array(false, $prot, true), 'tables' => $prot];
        } catch (\Throwable) {
            $checks['log_protection'] = ['ok' => false];
        }

        $status = collect($checks)->every(fn ($c) => $c['ok']) ? 'ok' : (($checks['database']['ok'] ?? false) ? 'degraded' : 'down');

        return response()->json(['data' => ['status' => $status, 'checks' => $checks, 'checked_at' => now()->toIso8601String()]], $status === 'down' ? 503 : 200);
    }

    public function backups(): JsonResponse
    {
        return response()->json(['data' => BackupAuditRecord::latest('started_at')->limit(30)->get()->map(fn ($b) => ['id' => $b->id, 'type' => $b->type, 'status' => $b->status, 'size_bytes' => $b->size_bytes, 'sha256' => $b->sha256,
            'started_at' => $b->started_at->toIso8601String(), 'finished_at' => $b->finished_at?->toIso8601String(), 'error' => $b->error])]);
    }

    public function auditLogs(Request $request): JsonResponse|\Symfony\Component\HttpFoundation\StreamedResponse
    {
        $f = $request->validate(['actor_id' => ['nullable', 'uuid'], 'action' => ['nullable', 'string', 'max:100'], 'subject_type' => ['nullable', 'string', 'max:100'], 'subject_id' => ['nullable', 'string', 'max:64'],
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'format' => ['nullable', 'in:json,csv']]);
        $q = AuditLog::query()->when($f['actor_id'] ?? null, fn ($q, $v) => $q->where('actor_id', $v))->when($f['action'] ?? null, fn ($q, $v) => $q->where('action', 'like', addcslashes($v, '%_\\').'%'))
            ->when($f['subject_type'] ?? null, fn ($q, $v) => $q->where('subject_type', $v))->when($f['subject_id'] ?? null, fn ($q, $v) => $q->where('subject_id', $v))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', $v))->when($f['to'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', $v.' 23:59:59'))->orderByDesc('created_at');
        if (($f['format'] ?? 'json') === 'csv') {
            return \App\Services\ReportService::csv('audit-log-'.now()->format('Ymd').'.csv', ['at', 'actor_id', 'action', 'subject_type', 'subject_id', 'reason', 'ip_address'],
                $q->limit(20000)->get()->map(fn ($l) => [$l->created_at->toIso8601String(), $l->actor_id, $l->action, $l->subject_type, $l->subject_id, $l->reason, $l->ip_address]));
        }
        $p = $q->paginate(50);

        return response()->json(['data' => $p->getCollection()->map(fn ($l) => ['id' => $l->id, 'at' => $l->created_at->toIso8601String(), 'actor_id' => $l->actor_id, 'action' => $l->action, 'subject_type' => $l->subject_type, 'subject_id' => $l->subject_id,
            'old' => $l->old_values, 'new' => $l->new_values, 'reason' => $l->reason, 'ip' => $l->ip_address])->values(), 'meta' => ['current_page' => $p->currentPage(), 'last_page' => $p->lastPage(), 'total' => $p->total()]]);
    }
}
