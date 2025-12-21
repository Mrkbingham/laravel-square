<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Square\Models\InvoiceCustomFieldPlacement;

class CreateNikolagInvoiceCustomFieldsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('nikolag_invoice_custom_fields', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('invoice_id');
            $table->string('label', 30);
            $table->string('value', 2000)->nullable();
            $table->enum('placement', [
                InvoiceCustomFieldPlacement::ABOVE_LINE_ITEMS,
                InvoiceCustomFieldPlacement::BELOW_LINE_ITEMS
            ]);
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
        Schema::dropIfExists('nikolag_invoice_custom_fields');
    }
}
