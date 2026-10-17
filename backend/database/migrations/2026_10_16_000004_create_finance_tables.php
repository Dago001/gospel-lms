<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('code', 40);
            $t->string('description')->nullable();
            $t->string('type', 10);                       // percent | fixed
            $t->unsignedBigInteger('value');              // percent (1-100) or kobo
            $t->unsignedInteger('max_redemptions')->nullable();
            $t->unsignedSmallInteger('per_user_limit')->default(1);
            $t->jsonb('course_ids')->nullable();          // null = any course
            $t->timestamp('starts_at')->nullable();
            $t->timestamp('ends_at')->nullable();
            $t->boolean('active')->default(true);
            $t->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
        if (\App\Support\DbCompat::mysql()) {
            DB::statement('CREATE UNIQUE INDEX coupons_code_unique ON coupons (code)');   // the default collation is case-insensitive
        } else {
            DB::statement('CREATE UNIQUE INDEX coupons_code_unique ON coupons (lower(code))');
        }
        DB::statement("ALTER TABLE coupons ADD CONSTRAINT coupons_type_valid CHECK (type IN ('percent','fixed'))");
        DB::statement("ALTER TABLE coupons ADD CONSTRAINT coupons_value_valid CHECK (value > 0 AND (type <> 'percent' OR value <= 100))");

        Schema::create('orders', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('number', 32)->unique();
            $t->foreignUuid('user_id')->constrained()->restrictOnDelete();
            $t->string('status', 20)->default('pending')->index(); // pending|paid|failed|expired|refunded|partially_refunded
            $t->char('currency', 3);
            $t->unsignedBigInteger('subtotal_minor');
            $t->unsignedBigInteger('discount_minor')->default(0);
            $t->unsignedBigInteger('total_minor');
            $t->foreignUuid('coupon_id')->nullable()->constrained('coupons')->nullOnDelete();
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->timestamps();
            $t->index(['user_id', 'status']);
        });
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_totals_valid CHECK (total_minor = subtotal_minor - discount_minor AND discount_minor <= subtotal_minor)');
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_valid CHECK (status IN ('pending','paid','failed','expired','refunded','partially_refunded'))");

        Schema::create('order_items', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('order_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('course_id')->constrained()->restrictOnDelete();
            $t->string('title', 200);                     // snapshot at purchase time
            $t->unsignedBigInteger('unit_price_minor');
            $t->unsignedBigInteger('discount_minor')->default(0);
            $t->unsignedBigInteger('total_minor');
            $t->timestamps();
            $t->unique(['order_id', 'course_id']);
        });

        Schema::table('course_enrollments', function (Blueprint $t) {
            $t->foreign('order_item_id')->references('id')->on('order_items')->nullOnDelete();
        });

        Schema::create('payments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('order_id')->constrained()->restrictOnDelete();
            $t->string('provider', 20)->default('paystack');
            $t->string('reference', 64)->unique();         // our unique payment reference
            $t->unsignedBigInteger('amount_minor');
            $t->char('currency', 3);
            $t->string('status', 20)->default('initialized')->index(); // initialized|success|failed|abandoned|mismatch|free
            $t->string('provider_transaction_id', 64)->nullable();
            $t->string('gateway_response')->nullable();
            $t->string('failure_reason')->nullable();
            $t->text('authorization_url')->nullable();
            $t->string('access_code', 64)->nullable();
            $t->string('channel', 30)->nullable();
            $t->jsonb('verification')->nullable();         // sanitised gateway verification payload
            $t->timestamp('paid_at')->nullable();
            $t->timestamp('last_verified_at')->nullable();
            $t->timestamps();
            $t->index(['status', 'created_at']);
        });
        // Duplicate processing guard: one provider transaction can settle at most one payment.
        \App\Support\DbCompat::partialUnique('payments', 'payments_provider_txn_unique', ['provider', 'provider_transaction_id'], 'provider_transaction_id IS NOT NULL');
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_status_valid CHECK (status IN ('initialized','success','failed','abandoned','mismatch','free'))");
        // At most one successful payment per order.
        \App\Support\DbCompat::partialUnique('payments', 'payments_one_success_per_order', ['order_id'], "status IN ('success','free')");

        Schema::create('payment_webhook_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('provider', 20)->default('paystack');
            $t->string('event_key', 128);                  // deterministic idempotency key
            $t->string('event_type', 60)->index();
            $t->string('reference', 64)->nullable()->index();
            $t->jsonb('payload');
            $t->boolean('signature_valid');
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->timestamp('processed_at')->nullable();
            $t->text('processing_error')->nullable();
            $t->timestamp('received_at')->useCurrent();
            $t->unique(['provider', 'event_key']);
        });

        Schema::create('refunds', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('payment_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('order_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('amount_minor');
            $t->char('currency', 3);
            $t->string('status', 16)->default('requested');   // requested|pending|processed|failed|review
            $t->string('reason', 255);
            $t->boolean('revoke_access')->default(false);
            $t->foreignUuid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('idempotency_key', 64)->unique();
            $t->string('provider_refund_id', 64)->nullable()->unique();
            $t->string('failure_reason')->nullable();
            $t->timestamp('processed_at')->nullable();
            $t->timestamps();
            $t->index(['payment_id', 'status']);
        });
        DB::statement('ALTER TABLE refunds ADD CONSTRAINT refunds_amount_positive CHECK (amount_minor > 0)');

        Schema::create('coupon_redemptions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('coupon_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('user_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('order_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('discount_minor');
            $t->string('status', 10)->default('reserved');    // reserved | redeemed | released
            $t->timestamps();
            $t->unique('order_id');                            // one coupon per order
            $t->index(['coupon_id', 'status']);
        });

        Schema::create('receipts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('number', 32)->unique();
            $t->foreignUuid('payment_id')->unique()->constrained()->restrictOnDelete(); // one receipt per payment
            $t->foreignUuid('user_id')->constrained()->restrictOnDelete();
            $t->jsonb('data');                                  // immutable snapshot used to render the PDF
            $t->timestamp('issued_at');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::table('course_enrollments', fn (Blueprint $t) => $t->dropForeign(['order_item_id']));
        foreach (['receipts','coupon_redemptions','refunds','payment_webhook_events','payments','order_items','orders','coupons'] as $tbl) {
            Schema::dropIfExists($tbl);
        }
    }
};
