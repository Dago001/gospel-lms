<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Services\CertificateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CertificateController extends Controller
{
    private function row(Certificate $c): array
    {
        return ['id' => $c->id, 'number' => $c->number, 'status' => $c->status, 'holder_name' => $c->holder_name, 'course_title' => $c->course_title, 'issued_at' => $c->issued_at->toIso8601String(),
            'revocations' => $c->relationLoaded('revocations') ? $c->revocations->map(fn ($r) => ['reason' => $r->reason, 'revoked_by' => $r->revoked_by, 'revoked_at' => $r->revoked_at->toIso8601String(), 'block_reissue' => $r->block_reissue]) : []];
    }

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate(['q' => ['nullable', 'string', 'max:80'], 'status' => ['nullable', 'in:active,revoked']]);
        $p = Certificate::with('revocations')->when($f['q'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('number', \App\Support\DbCompat::like(), '%'.addcslashes($v, '%_\\').'%')->orWhere('holder_name', \App\Support\DbCompat::like(), '%'.addcslashes($v, '%_\\').'%')))
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))->latest('issued_at')->paginate(25);

        return response()->json(['data' => $p->getCollection()->map(fn ($c) => $this->row($c))->values(), 'meta' => ['current_page' => $p->currentPage(), 'last_page' => $p->lastPage(), 'total' => $p->total()]]);
    }

    public function revoke(Request $request, Certificate $certificate, CertificateService $svc): JsonResponse
    {
        $d = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500'], 'block_reissue' => ['sometimes', 'boolean']]);
        $c = $svc->revoke($certificate, $request->user(), $d['reason'], (bool) ($d['block_reissue'] ?? true));

        return response()->json(['data' => $this->row($c->load('revocations'))]);
    }

    public function reissue(Request $request, Certificate $certificate, CertificateService $svc): JsonResponse
    {
        $d = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500']]);
        $new = $svc->reissue($certificate, $request->user(), $d['reason']);

        return response()->json(['data' => $this->row($new->load('revocations'))], 201);
    }
}
