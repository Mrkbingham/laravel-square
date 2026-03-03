<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateNikolagInvoiceAttachmentsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('nikolag_invoice_attachments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('invoice_id');
            $table->string('attachment_id')->nullable();
            $table->string('filename')->nullable();
            $table->text('description')->nullable();
            $table->integer('filesize')->nullable();
            $table->string('hash')->nullable();
            $table->string('mime_type')->nullable();
            $table->timestamp('uploaded_at')->nullable();
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
        Schema::dropIfExists('nikolag_invoice_attachments');
    }
}
