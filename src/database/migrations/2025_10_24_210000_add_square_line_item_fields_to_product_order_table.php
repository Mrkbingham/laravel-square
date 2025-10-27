<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * This migration adds fields to align nikolag_product_order table with Square's
     * OrderLineItem model structure, matching the nikolag_order_return_line_items table.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('nikolag_product_order', function (Blueprint $table) {
            // Catalog reference fields
            $table->string('catalog_object_id', 192)->nullable()->index();
            $table->bigInteger('catalog_version')->nullable();

            // Line item metadata
            $table->string('item_type', 25)->nullable();
            $table->text('note')->nullable();

            // Money fields - base price
            $table->integer('base_price_money_amount')->default(0)->nullable();
            $table->string('base_price_money_currency', 3)->default('USD')->nullable();

            // Money fields - variation total (base price × quantity)
            $table->integer('variation_total_price_money_amount')->default(0)->nullable();
            $table->string('variation_total_price_money_currency', 3)->default('USD')->nullable();

            // Money fields - gross sales (variation total + modifiers)
            $table->integer('gross_sales_money_amount')->default(0)->nullable();
            $table->string('gross_sales_money_currency', 3)->default('USD')->nullable();

            // Money fields - total tax
            $table->integer('total_tax_money_amount')->default(0)->nullable();
            $table->string('total_tax_money_currency', 3)->default('USD')->nullable();

            // Money fields - total discount
            $table->integer('total_discount_money_amount')->default(0)->nullable();
            $table->string('total_discount_money_currency', 3)->default('USD')->nullable();

            // Money fields - total money (final line item total)
            $table->integer('total_money_amount')->default(0)->nullable();
            $table->string('total_money_currency', 3)->default('USD')->nullable();

            // Timestamps for audit trail
            $table->timestamps();
        });

        // Drop the `price_money_amount` and `price_money_currency` columns as they are now redundant
        Schema::table('nikolag_product_order', function (Blueprint $table) {
            $table->dropColumn(['price_money_amount', 'price_money_currency']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('nikolag_product_order', function (Blueprint $table) {
            // Drop index first for SQLite compatibility
            $table->dropIndex(['catalog_object_id']);

            $table->dropColumn([
                'catalog_object_id',
                'catalog_version',
                'item_type',
                'note',
                'variation_total_price_money_amount',
                'variation_total_price_money_currency',
                'gross_sales_money_amount',
                'gross_sales_money_currency',
                'total_tax_money_amount',
                'total_tax_money_currency',
                'total_discount_money_amount',
                'total_discount_money_currency',
                'total_money_amount',
                'total_money_currency',
                'created_at',
                'updated_at',
            ]);
        });
    }
};
