<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The business identity of a tenant, split off from `clients`.
 *
 * `clients` stays the platform's own record of the tenant — the name on the
 * impersonation banner, the admin list, billing currency. Everything a Romanian
 * company needs on an invoice or a contact page (CUI, VAT regime, trade
 * register, IBAN, structured address) lives here instead of widening `clients`
 * by 28 columns, because none of it is loaded on the hot paths that read
 * `clients` on every request.
 *
 * Every column is nullable: this is filled in gradually from a settings form,
 * and an incomplete profile must never block a tenant from using the product.
 *
 * `clients.address` becomes derived from address_* on save — it is kept only so
 * the existing admin list and CSV export keep rendering, and is no longer the
 * source of truth.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();

            // Identity
            $table->string('legal_name', 255)->nullable();
            $table->string('industry', 64)->nullable();
            $table->string('industry_other', 255)->nullable();
            $table->string('company_size', 16)->nullable();
            $table->text('short_description')->nullable();

            // Fiscal. vat_status is a three-state gate ('none', 'standard',
            // 'on_collection') deciding which legal mention the invoice carries;
            // vat_rate is stored separately because 11% goods are ordinary, not
            // an exception to the standard rate.
            $table->string('cui', 16)->nullable()->index();
            $table->string('vat_status', 16)->nullable();
            $table->decimal('vat_rate', 5, 2)->nullable();
            $table->string('trade_register_no', 32)->nullable();
            $table->decimal('share_capital', 15, 2)->nullable();
            $table->string('iban', 34)->nullable();
            $table->string('bank_name', 128)->nullable();

            // Contact. clients.phone stays the landline; this is the mobile.
            $table->string('mobile_phone', 64)->nullable();
            $table->string('website', 255)->nullable();
            $table->string('contact_person_name', 128)->nullable();
            $table->string('contact_person_role', 128)->nullable();

            // Address
            $table->string('address_street', 255)->nullable();
            $table->string('address_city', 128)->nullable();
            $table->string('address_county', 64)->nullable();
            $table->string('address_postcode', 16)->nullable();
            $table->string('address_country', 2)->nullable()->default('RO');

            // Delivery
            $table->string('timezone', 64)->nullable();
            $table->text('delivery_zones')->nullable();
            $table->string('delivery_time', 255)->nullable();

            // Online presence
            $table->string('facebook_url', 255)->nullable();
            $table->string('instagram_url', 255)->nullable();
            $table->string('google_maps_url', 512)->nullable();
            $table->string('online_shop_url', 255)->nullable();

            $table->timestamps();

            // Declared on its own line, not chained onto ->constrained(): chaining
            // ->unique() there lands on the foreign-key definition and is silently
            // a no-op, leaving one client able to hold several profiles.
            $table->unique('client_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_profiles');
    }
};
