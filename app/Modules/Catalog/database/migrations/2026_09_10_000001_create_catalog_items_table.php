<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The catalogue the firm writes by hand.
     *
     * Deliberately not a set of columns bolted onto ecommerce_products: that
     * table is a read-only mirror of a connected Shopify/WooCommerce store, so
     * store_id is NOT NULL, every sync overwrites name/sku/price/status, and
     * SyncStoreProductsJob::prune() hard-deletes any row the store stopped
     * returning — a hand-typed service would be silently destroyed by the next
     * resync. Most of our customers (a clinic, a plumber, an estate agency) have
     * no shop at all and nothing to sync from.
     *
     * Designed once for all five stages of the module: the columns the later
     * stages need are created here, nullable and unused, because adding a column
     * later means a migration on a table that already holds customers' data.
     * Each one says which stage first writes to it.
     */
    public function up(): void
    {
        Schema::create('catalog_items', function (Blueprint $table) {
            $table->id();
            // The key that appears in URLs. Numeric ids never leave the server.
            $table->uuid('uuid')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();

            // What it is. 'bundle' is accepted by the schema but nothing composes
            // one until stage 3.
            $table->enum('type', ['product', 'service', 'bundle'])->default('product');

            $table->string('name');
            // SKU / cod produs, as the firm writes it. Not unique: two branches
            // of the same workspace reuse codes, and refusing the save would be
            // the wrong lesson to teach at the second row typed.
            $table->string('code')->nullable();
            // Free text, autocompleted from the DISTINCT values already used.
            // Not a table: a category list to maintain is a CRM, not this.
            $table->string('category')->nullable();
            $table->string('unit')->default('buc');

            // Money is integer minor units (bani). 24000 is 240,00 lei. Never
            // decimal — the offer totals in stage 4 sum these.
            $table->unsignedBigInteger('price_cents')->default(0);
            // The floor a discount may not go under. Written from stage 3.
            $table->unsignedBigInteger('min_price_cents')->nullable();

            // Null means "not tracked", which is every service and plenty of
            // products. Zero means "tracked, and there are none left".
            $table->integer('stock')->nullable();
            $table->unsignedInteger('low_stock_threshold')->nullable()->default(5);

            // The sales copy and where it came from. Written from stage 3.
            $table->text('description')->nullable();
            $table->enum('description_source', ['human', 'ai'])->nullable();
            // The photo. Written from stage 3 — stage 1 has no upload path that
            // WhatsApp can read, because our files sit on the private disk.
            $table->string('image_path')->nullable();

            // The shop row this item mirrors, when the firm also has a store.
            // Written from stage 3; deliberately not a foreign key, so pruning
            // ecommerce_products cannot cascade into hand-typed rows.
            $table->unsignedBigInteger('ecommerce_product_id')->nullable();

            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // The list as the screen reads it: a workspace, a type tab, active only.
            $table->index(['workspace_id', 'type', 'is_active']);
            // The category filter, and the DISTINCT that feeds its autocomplete.
            $table->index(['workspace_id', 'category']);
            // Lookup by the code the firm typed, from search and from import.
            $table->index(['workspace_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_items');
    }
};
