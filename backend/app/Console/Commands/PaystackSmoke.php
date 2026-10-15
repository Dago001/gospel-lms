<?php

namespace App\Console\Commands;

use App\Services\Payments\GatewayException;
use App\Services\Payments\PaystackClient;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/** Real-sandbox check of credentials and API reachability. Makes no charge: it only creates an unpaid test checkout. */
class PaystackSmoke extends Command
{
    protected $signature = 'paystack:smoke {--email=smoke@example.com}';

    protected $description = 'Verify Paystack sandbox credentials (initialise + verify an unpaid reference)';

    public function handle(PaystackClient $client): int
    {
        $key = (string) config('services.paystack.secret_key');
        if ($key === '' || ($key[0] ?? '') !== 's') {
            $this->error('PAYSTACK_SECRET_KEY is not set.');

            return self::FAILURE;
        }
        if (config('services.paystack.mode') === 'test' && ! str_starts_with($key, 'sk_test_')) {
            $this->error('PAYSTACK_MODE=test but the key is not an sk_test_ key. Refusing to continue.');

            return self::FAILURE;
        }
        $ref = 'SMOKE'.Str::upper((string) Str::ulid());
        try {
            $init = $client->initialize($this->option('email'), 10000, 'NGN', $ref, rtrim(config('app.frontend_url'), '/').'/checkout/callback', ['smoke' => true]);
            $this->info('initialize OK: '.$init['authorization_url']);
            $v = $client->verify($ref);
            $this->info('verify OK: status='.($v['status'] ?? 'null').' domain='.($v['domain'] ?? '?'));
        } catch (GatewayException $e) {
            $this->error('Gateway error: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
