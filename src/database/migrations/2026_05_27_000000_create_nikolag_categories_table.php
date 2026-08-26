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
        Schema::create('nikolag_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('square_catalog_object_id')->unique();
            $table->unsignedBigInteger('parent_category_id')->nullable();
            $table->string('parent_square_catalog_object_id')->nullable();
            $table->boolean('is_top_level')->nullable();
            $table->string('category_type', 50)->nullable();
            $table->json('image_ids')->nullable();
            $table->boolean('online_visibility')->nullable();
            $table->string('root_category')->nullable();
            $table->timestamps();

            $table->foreign('parent_category_id')
                ->references('id')
                ->on('nikolag_categories')
                ->onDelete('set null');

            $table->index('parent_category_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::drop('nikolag_categories');
    }
};
