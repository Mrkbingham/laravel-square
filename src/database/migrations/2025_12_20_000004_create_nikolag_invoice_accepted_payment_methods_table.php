<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateNikolagInvoiceAcceptedPaymentMethodsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('nikolag_invoice_accepted_payment_methods', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('invoice_id')->unique();
            $table->boolean('card')->default(false);
            $table->boolean('square_gift_card')->default(false);
            $table->boolean('bank_account')->default(false);
            $table->boolean('buy_now_pay_later')->default(false);
            $table->boolean('cash_app_pay')->default(false);
            $table->timestamps();

            $table->foreign('invoice_id')->references('id')->on('nikolag_invoices')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('nikolag_invoice_accepted_payment_methods');
    }
}
