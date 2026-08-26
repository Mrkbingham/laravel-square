<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('nikolag_category_product', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('category_id');
            $table->unsignedBigInteger('product_id');
            $table->integer('ordinal')->nullable();
            $table->timestamps();
        });

        // Fix column type mismatch before adding foreign key constraints
        // Change product_id to match nikolag_products.id (int unsigned)
        Schema::table('nikolag_category_product', function (Blueprint $table) {
            $table->unsignedInteger('product_id')->change();
        });

        // Add foreign key constraints
        Schema::table('nikolag_category_product', function (Blueprint $table) {
            $table->foreign('category_id')
                ->references('id')
                ->on('nikolag_categories')
                ->onDelete('cascade');
        });

        Schema::table('nikolag_category_product', function (Blueprint $table) {
            $table->foreign('product_id')
                ->references('id')
                ->on('nikolag_products')
                ->onDelete('cascade');
        });

        // Add a unique constraint to prevent duplicate entries
        Schema::table('nikolag_category_product', function (Blueprint $table) {
            $table->unique(['category_id', 'product_id'], 'category_product_unique');
        });

        // Add an index for faster lookups
        Schema::table('nikolag_category_product', function (Blueprint $table) {
            $table->index(['category_id', 'product_id'], 'category_product_index');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::drop('nikolag_category_product');
    }
};
