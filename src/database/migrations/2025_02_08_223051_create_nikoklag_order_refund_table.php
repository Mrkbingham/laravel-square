<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Square\Models\RefundStatus;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('nikolag_order_refunds', function (Blueprint $table) {
            $table->id();
            $table->morphs('refundable');
            $table->integer('quantity')->default(1);
            $table->string('reason', 192);
            $table->enum('status', [
                RefundStatus::PENDING,
                RefundStatus::APPROVED,
                RefundStatus::REJECTED,
                RefundStatus::FAILED,
            ])->default(RefundStatus::PENDING);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nikolag_order_refunds');
    }
};
