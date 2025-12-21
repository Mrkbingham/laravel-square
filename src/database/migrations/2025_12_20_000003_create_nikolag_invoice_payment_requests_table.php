<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Square\Models\InvoiceAutomaticPaymentSource;
use Square\Models\InvoiceRequestType;

class CreateNikolagInvoicePaymentRequestsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('nikolag_invoice_payment_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('invoice_id');
            $table->string('square_uid', 255)->nullable();
            // $table->string('request_method'); DEPRECATED - use $invoice->delivery_method
            $table->enum('request_type', [
                InvoiceRequestType::BALANCE,
                InvoiceRequestType::DEPOSIT,
                InvoiceRequestType::INSTALLMENT,
            ])->nullable();
            $table->dateTime('due_date')->nullable();
            $table->bigInteger('fixed_amount_requested_money_amount')->nullable();
            $table->string('fixed_amount_requested_money_currency', 3)->nullable();
            $table->string('percentage_requested')->nullable();
            $table->boolean('tipping_enabled')->default(false);
            $table->enum('automatic_payment_source', [
                InvoiceAutomaticPaymentSource::NONE,
                InvoiceAutomaticPaymentSource::CARD_ON_FILE,
                InvoiceAutomaticPaymentSource::BANK_ON_FILE,
            ])->default(InvoiceAutomaticPaymentSource::NONE);
            $table->string('card_id', 255)->nullable();
            $table->bigInteger('computed_amount_money_amount')->nullable();
            $table->string('computed_amount_money_currency', 3)->nullable();
            $table->bigInteger('total_completed_amount_money_amount')->nullable();
            $table->string('total_completed_amount_money_currency', 3)->nullable();
            $table->bigInteger('rounding_adjustment_amount')->nullable();
            $table->string('rounding_adjustment_currency', 3)->nullable();
            $table->timestamps();

            $table->index('invoice_id');
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
        Schema::dropIfExists('nikolag_invoice_payment_requests');
    }
}
