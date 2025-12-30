<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Square\Models\InvoiceDeliveryMethod;
use Square\Models\InvoiceStatus;

class CreateNikolagInvoicesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Get the order namespace from the configuration
        $orderClass = config('nikolag.connections.square.order.namespace');
        if (!class_exists($orderClass)) {
            throw new Exception("Order class {$orderClass} does not exist. Please check your configuration.");
        }

        // Get the table name for orders from the configuration
        $orderTable = (new $orderClass)->getTable();

        Schema::create('nikolag_invoices', function (Blueprint $table) use ($orderTable) {
            $table->id();
            $table->string('payment_service_id')->nullable()->index();
            $table->integer('payment_service_version')->nullable();
            $table->unsignedBigInteger('location_id')
                ->foreign('location_id')->references('id')->on('nikolag_locations')->onDelete('cascade');
            $table->unsignedBigInteger('order_id')->unique()
                ->foreign('order_id')->references('id')->on($orderTable)->onDelete('cascade');
            $table->enum('delivery_method', [
                InvoiceDeliveryMethod::EMAIL,
                InvoiceDeliveryMethod::SHARE_MANUALLY,
                InvoiceDeliveryMethod::SMS,
            ])->nullable();
            $table->string('invoice_number', 191)->nullable();
            $table->string('title', 255)->nullable();
            $table->text('description')->nullable();
            $table->dateTime('scheduled_at')->nullable();
            $table->text('public_url')->nullable();
            $table->bigInteger('next_payment_amount_money_amount')->nullable();
            $table->string('next_payment_amount_money_currency', 3)->nullable();
            $table->enum('status', [
                InvoiceStatus::DRAFT,
                InvoiceStatus::UNPAID,
                InvoiceStatus::SCHEDULED,
                InvoiceStatus::PARTIALLY_PAID,
                InvoiceStatus::PAID,
                InvoiceStatus::PARTIALLY_REFUNDED,
                InvoiceStatus::REFUNDED,
                InvoiceStatus::CANCELED,
                InvoiceStatus::FAILED,
                InvoiceStatus::PAYMENT_PENDING,
            ])->nullable()->index();
            $table->string('timezone')->nullable();
            $table->dateTime('square_created_at')->nullable();
            $table->dateTime('square_updated_at')->nullable();
            $table->string('subscription_id')->nullable();
            $table->date('sale_or_service_date')->nullable();
            $table->text('payment_conditions')->nullable();
            $table->boolean('store_payment_method_enabled')->default(false);
            $table->string('creator_team_member_id')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('nikolag_invoices');
    }
}
