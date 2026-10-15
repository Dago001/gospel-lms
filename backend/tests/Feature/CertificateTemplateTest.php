<?php

use App\Models\Certificate;
use App\Models\Course;
use App\Services\CertificateService;
use App\Services\CertificateTemplate;
use App\Services\Settings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function fakeTemplate(int $w = 2000, int $h = 1414, string $type = 'png'): UploadedFile
{
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, 250, 246, 235));
    imagerectangle($im, 40, 40, $w - 40, $h - 40, imagecolorallocate($im, 32, 112, 39));
    $path = tempnam(sys_get_temp_dir(), 'tpl').'.'.$type;
    $type === 'png' ? imagepng($im, $path) : imagejpeg($im, $path);

    return new UploadedFile($path, 'template.'.$type, $type === 'png' ? 'image/png' : 'image/jpeg', null, true);
}

beforeEach(fn () => Storage::fake('private'));

it('lets an administrator upload a template and rejects unsuitable files', function () {
    $this->actingAs($this->makeUser('admin'))->withSession(['mfa_verified' => true])->postJson('/api/v1/admin/certificate-template', ['template' => fakeTemplate()], [])->assertCreated()
        ->assertJsonPath('data.has_template', true)->assertJsonPath('data.meta.width', 2000);
    expect(Storage::disk('private')->allFiles('certificate-templates'))->toHaveCount(1);
    expect(\Illuminate\Support\Facades\DB::table('audit_logs')->where('action', 'certificate.template_uploaded')->count())->toBe(1);

    $php = UploadedFile::fake()->createWithContent('evil.png', '<?php system($_GET["c"]); ?>');
    $this->postJson('/api/v1/admin/certificate-template', ['template' => $php])->assertStatus(422)->assertJsonPath('code', 'bad_template_type');
    $pdf = UploadedFile::fake()->createWithContent('t.png', "%PDF-1.4\n1 0 obj<<>>endobj");
    $this->postJson('/api/v1/admin/certificate-template', ['template' => $pdf])->assertStatus(422);
    $this->postJson('/api/v1/admin/certificate-template', ['template' => fakeTemplate(800, 566)])->assertStatus(422)->assertJsonPath('code', 'bad_template_size');
    $this->postJson('/api/v1/admin/certificate-template', ['template' => fakeTemplate(2000, 2000)])->assertStatus(422)->assertJsonPath('code', 'bad_template_ratio');
    $this->postJson('/api/v1/admin/certificate-template', [])->assertStatus(422);
    expect(Storage::disk('private')->allFiles('certificate-templates'))->toHaveCount(1);
});

it('only people with certificates.manage can change or see the template', function () {
    $this->getJson('/api/v1/admin/certificate-template')->assertUnauthorized();
    foreach (['student', 'lecturer'] as $role) {
        $this->actingAs($this->makeUser($role))->getJson('/api/v1/admin/certificate-template')->assertForbidden();
        $this->postJson('/api/v1/admin/certificate-template', ['template' => fakeTemplate()])->assertForbidden();
        $this->putJson('/api/v1/admin/certificate-template/layout', [])->assertForbidden();
    }
    $this->assertNull(Settings::get('certificate.template_path'));
});

it('validates and saves the field layout, ignoring anything unknown', function () {
    $this->actingAs($this->makeUser('admin'))->withSession(['mfa_verified' => true]);
    $this->putJson('/api/v1/admin/certificate-template/layout', ['holder_name' => ['x' => 45, 'y' => 38.5, 'size' => 40, 'color' => '#112233', 'font' => 'times', 'visible' => true], 'qr' => ['x' => 90, 'size' => 12]])
        ->assertOk()->assertJsonPath('data.layout.holder_name.size', 40)->assertJsonPath('data.layout.holder_name.font', 'times')->assertJsonPath('data.layout.qr.x', 90)
        ->assertJsonPath('data.layout.course_title.y', 58);                        // untouched fields keep their defaults
    $this->putJson('/api/v1/admin/certificate-template/layout', ['holder_name' => ['x' => 150]])->assertStatus(422);
    $this->putJson('/api/v1/admin/certificate-template/layout', ['holder_name' => ['color' => 'red;position:fixed']])->assertStatus(422);
    $this->putJson('/api/v1/admin/certificate-template/layout', ['holder_name' => ['font' => 'Comic Sans']])->assertStatus(422);
    $this->putJson('/api/v1/admin/certificate-template/layout', ['holder_name' => ['size' => 500]])->assertStatus(422);
});

it('previews with sample details and serves the artwork only to authorised staff', function () {
    $this->actingAs($this->makeUser('admin'))->withSession(['mfa_verified' => true]);
    $this->getJson('/api/v1/admin/certificate-template/preview')->assertNotFound();
    $this->postJson('/api/v1/admin/certificate-template', ['template' => fakeTemplate(2000, 1414, 'jpg')])->assertCreated();
    $pdf = $this->get('/api/v1/admin/certificate-template/preview')->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(substr($pdf->getContent(), 0, 4))->toBe('%PDF');
    $this->get('/api/v1/admin/certificate-template/image')->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    $this->actingAs($this->makeUser('student'))->get('/api/v1/admin/certificate-template/image')->assertForbidden();
});

it('prints each student\'s own details on the template, escaped and fitted', function () {
    $layout = CertificateTemplate::layout([]);
    $placed = CertificateTemplate::placed($layout, ['holder_name' => 'Ada <script>alert(1)</script> Okafor', 'course_title' => 'Romans', 'issued_on' => 'Issued on 1 May 2027', 'grade' => '', 'duration' => '', 'signatory' => "Dr A\nRegistrar", 'number' => 'GL-1', 'verify_url' => 'Verify at x']);
    expect($placed)->toHaveKeys(['holder_name', 'course_title', 'issued_on', 'signatory', 'number', 'verify_url'])->not->toHaveKeys(['grade', 'duration', 'qr']);
    foreach ($placed as $f) {
        expect($f['left'])->toBeGreaterThanOrEqual(0)->and($f['left'] + $f['width'])->toBeLessThanOrEqual(CertificateTemplate::PAGE_W + 0.01);
    }
    $long = CertificateTemplate::placed($layout, ['holder_name' => str_repeat('Chukwuemeka ', 8)])['holder_name'];
    expect($long['size'])->toBeLessThan(34);                                          // shrinks instead of overflowing
    $html = view('pdf.certificate-template', ['bg' => 'data:image/png;base64,', 'w' => 841.89, 'h' => 595.28, 'fields' => $placed, 'qrBox' => null, 'qr' => '', 'number' => 'GL-1'])->render();
    expect($html)->toContain('Ada &lt;script&gt;')->not->toContain('<script>alert');
});

it('snapshots the template at issue time so earlier certificates never change', function () {
    $admin = $this->makeUser('admin');
    $this->actingAs($admin)->withSession(['mfa_verified' => true])->postJson('/api/v1/admin/certificate-template', ['template' => fakeTemplate()])->assertCreated();
    $first = Settings::get('certificate.template_path');

    $lect = $this->makeUser('lecturer');
    $course = Course::factory()->published()->withCurriculum(1, $lect)->create(['title' => 'Snapshot Course']);
    $student = $this->makeUser('student', ['name' => 'Ngozi Bello']);
    $svc = app(CertificateService::class);
    $make = fn ($user) => (new ReflectionMethod($svc, 'create'))->invoke($svc, $user, $course, 88.0, null);
    $c1 = $make($student);
    expect($c1->template_path)->toBe($first)->and($c1->layout['holder_name']['size'])->toBe(34);

    $this->postJson('/api/v1/admin/certificate-template', ['template' => fakeTemplate(2400, 1697)])->assertCreated();
    expect(Settings::get('certificate.template_path'))->not->toBe($first);
    $fresh = Certificate::find($c1->id);
    expect($fresh->template_path)->toBe($first);                                       // unchanged
    expect(substr($svc->pdf($fresh), 0, 4))->toBe('%PDF');

    $this->deleteJson('/api/v1/admin/certificate-template')->assertOk()->assertJsonPath('data.has_template', false);
    expect(substr($svc->pdf(Certificate::find($c1->id)), 0, 4))->toBe('%PDF');         // old certificate still renders with its own artwork
    $other = $this->makeUser('student');
    expect($make($other)->template_path)->toBeNull();                                  // no template now: built-in design
});

it('students download a PDF made from the template with their own name', function () {
    $this->actingAs($this->makeUser('admin'))->withSession(['mfa_verified' => true])->postJson('/api/v1/admin/certificate-template', ['template' => fakeTemplate()])->assertCreated();
    $lect = $this->makeUser('lecturer');
    $course = Course::factory()->published()->withCurriculum(1, $lect)->create();
    $student = $this->makeUser('student', ['name' => 'Ngozi Bello']);
    $svc = app(CertificateService::class);
    $cert = (new ReflectionMethod($svc, 'create'))->invoke($svc, $student, $course, 90.0, null);
    $this->actingAs($student)->get("/api/v1/learn/certificates/{$cert->id}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->actingAs($this->makeUser('student'))->get("/api/v1/learn/certificates/{$cert->id}/pdf")->assertNotFound();   // someone else's certificate is indistinguishable from a missing one
});
