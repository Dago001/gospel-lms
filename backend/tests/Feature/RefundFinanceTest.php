<?php

use App\Models\CourseEnrollment;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => configurePaystack());

/** A fully paid order; returns [Payment, student, course]. */
function paidOrder($test, int $minor = 2500000): array
{
    $course = paidCourse($minor);
    $student = $test->makeUser();
    [$payment] = startCheckout($test, $student, [$course]);
    fakePaystackVerify(gatewayTxn($payment));
    $test->postJson("/api/v1/payments/{$payment->reference}/verify")->assertOk();

    return [$payment->fresh(), $student, $course];
}

function asFinance($test)
{
    $u = $test->makeUser('finance');
    $test->actingAs($u)->withSession(['mfa_verified' => true]);

    return $u;
}

function fakeRefundResponse(array $data = [], int $status = 200): void
{
    Http::swap(new Illuminate\Http\Client\Factory);
    Http::fake(['api.paystack.co/refund' => fn () => Http::response(['status' => $status < 400, 'message' => 'Refund has been queued for processing', 'data' => $data + ['id' => random_int(10_000, 999_999_999), 'status' => 'pending']], $status)]);
}

it('lets finance issue a full refund that is confirmed by webhook and revokes access when asked', function () {
    [$payment, $student, $course] = paidOrder($this);
    asFinance($this);
    fakeRefundResponse(['id' => 7001]);
    $res = $this->postJson("/api/v1/admin/finance/payments/{$payment->id}/refunds", ['reason' => 'Duplicate enrolment request', 'revoke_access' => true])
        ->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.amount_minor', 2500000);
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/refund') && $r['transaction'] === $payment->reference && $r['amount'] === 2500000);
    expect(CourseEnrollment::first()->status)->toBe('active');   // not revoked until the gateway confirms

    signedWebhook($this, ['event' => 'refund.processed', 'data' => ['id' => 7001, 'status' => 'processed', 'transaction_reference' => $payment->reference, 'amount' => 2500000]])->assertOk();
    signedWebhook($this, ['event' => 'refund.processed', 'data' => ['id' => 7001, 'status' => 'processed', 'transaction_reference' => $payment->reference, 'amount' => 2500000]])->assertOk();   // duplicate
    expect(Refund::first()->status)->toBe('processed')->and(Order::first()->status)->toBe('refunded')->and(CourseEnrollment::first()->status)->toBe('cancelled');
    $this->actingAs($student)->getJson("/api/v1/learn/lessons/{$course->lessons()->first()->id}")->assertForbidden();
});

it('keeps access on a partial refund and never refunds more than was paid', function () {
    [$payment] = paidOrder($this);
    asFinance($this);
    fakeRefundResponse();
    $this->postJson("/api/v1/admin/finance/payments/{$payment->id}/refunds", ['amount_minor' => 1000000, 'reason' => 'Goodwill partial refund'])->assertCreated();
    $this->postJson("/api/v1/admin/finance/payments/{$payment->id}/refunds", ['amount_minor' => 1500001, 'reason' => 'Too much'])->assertUnprocessable()->assertJsonPath('code', 'refund_exceeds_balance')->assertJsonPath('details.refundable_minor', 1500000);
    $this->postJson("/api/v1/admin/finance/payments/{$payment->id}/refunds", ['amount_minor' => 500000, 'reason' => 'Revoke on partial', 'revoke_access' => true])->assertUnprocessable()->assertJsonPath('code', 'partial_revoke_not_allowed');
    $this->postJson("/api/v1/admin/finance/payments/{$payment->id}/refunds", ['amount_minor' => 1500000, 'reason' => 'Remainder'])->assertCreated();
    $this->postJson("/api/v1/admin/finance/payments/{$payment->id}/refunds", ['amount_minor' => 1, 'reason' => 'One more kobo'])->assertUnprocessable();
    expect(CourseEnrollment::first()->status)->toBe('active');
});

it('freezes a payment for manual review when the refund outcome is unknown, preventing double refunds', function () {
    [$payment] = paidOrder($this);
    asFinance($this);
    Http::swap(new Illuminate\Http\Client\Factory);
    Http::fake(['api.paystack.co/refund' => fn () => throw new Illuminate\Http\Client\ConnectionException('timeout')]);
    $this->postJson("/api/v1/admin/finance/payments/{$payment->id}/refunds", ['reason' => 'Student request'])->assertCreated()->assertJsonPath('data.status', 'review');
    $this->postJson("/api/v1/admin/finance/payments/{$payment->id}/refunds", ['reason' => 'Try again'])->assertStatus(409)->assertJsonPath('code', 'refund_in_review');
    $this->getJson('/api/v1/admin/finance/exceptions')->assertOk()->assertJsonFragment(['kind' => 'refund_review']);
});

it('refuses to refund payments that were never successfully charged', function () {
    [$payment] = startCheckout($this, $this->makeUser(), [paidCourse()]);
    asFinance($this);
    $this->postJson("/api/v1/admin/finance/payments/{$payment->id}/refunds", ['reason' => 'Nothing was charged'])->assertStatus(409)->assertJsonPath('code', 'not_refundable');
});

it('lets finance refund the extra charge of a duplicate payment', function () {
    $course = paidCourse();
    $student = $this->makeUser();
    [$first] = startCheckout($this, $student, [$course]);
    $second = Payment::create(['order_id' => $first->order_id, 'provider' => 'paystack', 'reference' => 'GLDUP', 'amount_minor' => $first->amount_minor, 'currency' => 'NGN', 'status' => 'initialized']);
    fakePaystackVerify(gatewayTxn($first));
    $this->postJson("/api/v1/payments/{$first->reference}/verify");
    fakePaystackVerify(gatewayTxn($second));
    $this->postJson("/api/v1/payments/{$second->reference}/verify");
    asFinance($this);
    $this->getJson('/api/v1/admin/finance/exceptions')->assertJsonFragment(['kind' => 'payment_mismatch']);
    fakeRefundResponse();
    $this->postJson("/api/v1/admin/finance/payments/{$second->id}/refunds", ['reason' => 'Customer paid twice'])->assertCreated();
    expect(CourseEnrollment::count())->toBe(1);   // original access untouched
});

it('limits financial endpoints by role: students, lecturers get 403; finance cannot reach roles or users', function () {
    [$payment] = paidOrder($this);
    foreach (['student', 'lecturer'] as $role) {
        $this->actingAs($this->makeUser($role))->withSession(['mfa_verified' => true]);
        $this->getJson('/api/v1/admin/finance/payments')->assertForbidden();
        $this->postJson("/api/v1/admin/finance/payments/{$payment->id}/refunds", ['reason' => 'nope nope'])->assertForbidden();
        $this->getJson('/api/v1/admin/finance/summary')->assertForbidden();
    }
    asFinance($this);
    $this->getJson('/api/v1/admin/finance/payments')->assertOk();
    $this->getJson('/api/v1/admin/roles')->assertForbidden();
    $this->getJson('/api/v1/admin/users')->assertForbidden();
    $this->getJson('/api/v1/admin/enrolments')->assertForbidden();
});

it('requires fresh MFA for finance users before any financial data is returned', function () {
    $this->actingAs($this->makeUser('finance'));   // no mfa_verified in session
    $this->getJson('/api/v1/admin/finance/payments')->assertForbidden()->assertJsonPath('code', 'mfa_setup_required');
});

it('filters and summarises payments from real ledger data', function () {
    [$ok] = paidOrder($this, 2500000);
    [$failed] = startCheckout($this, $this->makeUser(), [paidCourse(1000000)]);
    fakePaystackVerify(gatewayTxn($failed, ['status' => 'failed']));
    $this->postJson("/api/v1/payments/{$failed->reference}/verify");
    asFinance($this);
    $this->getJson('/api/v1/admin/finance/payments?status=success')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.reference', $ok->reference);
    $this->getJson('/api/v1/admin/finance/payments?q='.substr($failed->reference, 0, 12))->assertJsonCount(1, 'data');
    $sum = $this->getJson('/api/v1/admin/finance/summary')->assertOk()->json('data');
    $by = collect($sum['payments_by_status'])->keyBy('status');
    expect((int) $by['success']['total_minor'])->toBe(2500000)->and((int) $by['failed']['count'])->toBe(1)->and((int) $sum['daily_revenue'][0]['total_minor'])->toBe(2500000);
});

it('exports transactions as CSV and neutralises spreadsheet formulas', function () {
    $student = $this->makeUser('student', ['email' => '=cmd@example.com']);
    $course = paidCourse(500000);
    [$payment] = startCheckout($this, $student, [$course]);
    fakePaystackVerify(gatewayTxn($payment));
    $this->postJson("/api/v1/payments/{$payment->reference}/verify");
    asFinance($this);
    $csv = $this->get('/api/v1/admin/finance/export')->assertOk()->streamedContent();
    expect($csv)->toContain('reference,order,customer_email')->toContain("'=cmd@example.com")->toContain('5000.00');
});

it('manages coupons for finance and rejects bad definitions', function () {
    asFinance($this);
    $this->postJson('/api/v1/admin/finance/coupons', ['code' => 'LENT25', 'type' => 'percent', 'value' => 25, 'max_redemptions' => 100])->assertCreated();
    $this->postJson('/api/v1/admin/finance/coupons', ['code' => 'lent25', 'type' => 'percent', 'value' => 10])->assertUnprocessable();   // case-insensitive uniqueness
    $this->postJson('/api/v1/admin/finance/coupons', ['code' => 'BAD', 'type' => 'percent', 'value' => 150])->assertUnprocessable();
    $this->postJson('/api/v1/admin/finance/coupons', ['code' => 'bad code', 'type' => 'fixed', 'value' => 100])->assertUnprocessable();
    $this->getJson('/api/v1/admin/finance/coupons')->assertOk()->assertJsonCount(1, 'data');
});

it('lists webhook history including rejected deliveries', function () {
    [$payment] = startCheckout($this, $this->makeUser(), [paidCourse()]);
    $this->postJson('/api/v1/webhooks/paystack', ['event' => 'charge.success'])->assertUnauthorized();
    fakePaystackVerify(gatewayTxn($payment));
    signedWebhook($this, ['event' => 'charge.success', 'data' => ['reference' => $payment->reference, 'id' => 1, 'status' => 'success']]);
    asFinance($this);
    $rows = $this->getJson('/api/v1/admin/finance/webhooks')->assertOk()->json('data');
    expect(collect($rows)->pluck('signature_valid')->all())->toContain(true, false);
    $this->getJson('/api/v1/admin/finance/exceptions')->assertJsonFragment(['kind' => 'invalid_signature']);
});

it('lets finance reconcile a single payment on demand and audits it', function () {
    [$payment] = startCheckout($this, $this->makeUser(), [paidCourse()]);
    fakePaystackVerify(gatewayTxn($payment));
    asFinance($this);
    $this->postJson("/api/v1/admin/finance/payments/{$payment->id}/reconcile")->assertOk()->assertJsonPath('data.status', 'success');
    expect(App\Models\AuditLog::where('action', 'payment.reconciled')->exists())->toBeTrue();
});
