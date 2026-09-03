<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const REMOVED_GATEWAYS = [
        'paddle',
        'razorpay',
        'cashfree',
        'tap',
        'paystack',
        'xendit',
        'paymob',
        'myfatoorah',
        'mollie',
        'square',
        'iyzico',
        'mercadopago',
    ];

    public function up(): void
    {
        Schema::dropIfExists('iyzico_checkout_sessions');
        Schema::dropIfExists('iyzico_pricing_plans');

        $paddleColumns = array_values(array_filter([
            Schema::hasColumn('plans', 'paddle_monthly_id') ? 'paddle_monthly_id' : null,
            Schema::hasColumn('plans', 'paddle_yearly_id') ? 'paddle_yearly_id' : null,
        ]));
        if ($paddleColumns !== []) {
            Schema::table('plans', function (Blueprint $table) use ($paddleColumns) {
                $table->dropColumn($paddleColumns);
            });
        }

        DB::table('payment_gateway_configs')
            ->whereIn('gateway', self::REMOVED_GATEWAYS)
            ->delete();
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            if (! Schema::hasColumn('plans', 'paddle_monthly_id')) {
                $table->string('paddle_monthly_id')->nullable();
            }
            if (! Schema::hasColumn('plans', 'paddle_yearly_id')) {
                $table->string('paddle_yearly_id')->nullable();
            }
        });
    }
};
