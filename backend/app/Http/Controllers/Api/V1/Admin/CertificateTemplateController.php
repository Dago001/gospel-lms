<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\CertificateService;
use App\Services\CertificateTemplate;
use App\Services\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/** Certificate artwork and layout. Needs `certificates.manage`; every change is audit-logged. */
class CertificateTemplateController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    private function payload(): array
    {
        $c = CertificateTemplate::current();

        return ['has_template' => (bool) $c['path'], 'meta' => $c['meta'], 'layout' => $c['layout'], 'defaults' => CertificateTemplate::layout([]), 'fonts' => array_keys(CertificateTemplate::FONTS),
            'page' => ['width_pt' => CertificateTemplate::PAGE_W, 'height_pt' => CertificateTemplate::PAGE_H]];
    }

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->payload()]);
    }

    public function upload(Request $request): JsonResponse
    {
        $request->validate(['template' => ['required', 'file', 'max:10240']]);   // 10 MB; type, size and ratio are checked on the decoded image
        $stored = CertificateTemplate::store($request->file('template'));
        Settings::put('certificate.template_path', $stored['path'], $request->user()->id);
        Settings::put('certificate.template_meta', $stored['meta'], $request->user()->id);
        $this->audit->log('certificate.template_uploaded', null, new: ['path' => $stored['path'], 'width' => $stored['meta']['width'], 'height' => $stored['meta']['height']], actorId: $request->user()->id);

        return response()->json(['data' => $this->payload()], 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        Settings::put('certificate.template_path', null, $request->user()->id);   // files are kept so already-issued certificates still re-render as issued
        Settings::put('certificate.template_meta', null, $request->user()->id);
        $this->audit->log('certificate.template_removed', null, actorId: $request->user()->id);

        return response()->json(['data' => $this->payload()]);
    }

    public function layout(Request $request): JsonResponse
    {
        $rules = [];
        foreach (CertificateTemplate::DEFAULTS as $k => $def) {
            $rules["$k"] = ['sometimes', 'array'];
            $rules["$k.x"] = ['sometimes', 'numeric', 'between:0,100'];
            $rules["$k.y"] = ['sometimes', 'numeric', 'between:0,100'];
            $rules["$k.size"] = ['sometimes', 'numeric', $k === 'qr' ? 'between:5,30' : 'between:6,72'];
            $rules["$k.visible"] = ['sometimes', 'boolean'];
            if (isset($def['width'])) {
                $rules["$k.width"] = ['sometimes', 'numeric', 'between:10,100'];
                $rules["$k.color"] = ['sometimes', 'regex:/^#[0-9a-fA-F]{6}$/'];
                $rules["$k.font"] = ['sometimes', Rule::in(array_keys(CertificateTemplate::FONTS))];
            }
        }
        $data = $request->validate($rules);
        $layout = CertificateTemplate::layout($data);
        Settings::put('certificate.layout', $layout, $request->user()->id);
        $this->audit->log('certificate.layout_changed', null, new: ['fields' => array_keys($data)], actorId: $request->user()->id);

        return response()->json(['data' => $this->payload()]);
    }

    /** The artwork itself, for the editor's live preview (private storage, authorised, never cached). */
    public function image(): \Symfony\Component\HttpFoundation\Response
    {
        $c = CertificateTemplate::current();
        abort_unless($c['path'], 404);
        $disk = Storage::disk('private');

        return response($disk->get($c['path']), 200, ['Content-Type' => $disk->mimeType($c['path']) ?: 'image/png', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function preview(CertificateService $svc): \Symfony\Component\HttpFoundation\Response
    {
        $c = CertificateTemplate::current();
        abort_unless($c['path'], 404);

        return response($svc->previewPdf($c['path'], $c['layout']), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="certificate-preview.pdf"', 'Cache-Control' => 'private, no-store']);
    }
}
