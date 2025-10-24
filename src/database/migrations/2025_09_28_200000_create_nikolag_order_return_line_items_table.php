<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nikolag_order_return_line_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_return_id');

            // nikolag_products uses 'int unsigned', so we need unsignedInteger() not foreignId()
            $table->unsignedInteger('product_id')->nullable();

            // Line item details
            $table->integer('quantity');
            $table->string('square_uid')->nullable();
            $table->string('source_line_item_uid')->nullable();
            $table->string('catalog_object_id')->nullable();
            $table->bigInteger('catalog_version')->nullable();
            $table->string('name')->nullable();
            $table->string('variation_name')->nullable();
            $table->string('item_type')->nullable();
            $table->text('note')->nullable();

            // Money fields (stored as integer cents)
            $table->integer('base_price_money_amount')->default(0)->nullable();
            $table->string('base_price_money_currency', 3)->default('USD')->nullable();
            $table->integer('variation_total_price_money_amount')->default(0)->nullable();
            $table->string('variation_total_price_money_currency', 3)->default('USD')->nullable();
            $table->integer('gross_return_money_amount')->default(0)->nullable();
            $table->string('gross_return_money_currency', 3)->default('USD')->nullable();
            $table->integer('total_discount_money_amount')->default(0)->nullable();
            $table->string('total_discount_money_currency', 3)->default('USD')->nullable();
            $table->integer('total_service_charge_money_amount')->default(0)->nullable();
            $table->string('total_service_charge_money_currency', 3)->default('USD')->nullable();

            $table->timestamps();

            // Foreign key constraints
            $table->foreign('order_return_id')->references('id')->on('nikolag_order_returns')->onDelete('cascade');
            $table->foreign('product_id')->references('id')->on('nikolag_products')->onDelete('set null');

            // Indexes for performance
            $table->index('order_return_id');
            $table->index('product_id');
            $table->index('square_uid');
            $table->index('source_line_item_uid');
            $table->index(['order_return_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nikolag_order_return_line_items');
    }
};
