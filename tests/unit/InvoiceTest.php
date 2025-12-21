<?php

namespace Nikolag\Square\Tests\Unit;

use Nikolag\Square\Exceptions\InvalidSquareOrderException;
use Nikolag\Square\Exceptions\InvalidSquareVersionException;
use Nikolag\Square\Facades\Square;
use Nikolag\Square\Models\Customer;
use Nikolag\Square\Models\Invoice;
use Nikolag\Square\Models\InvoiceAcceptedPaymentMethods;
use Nikolag\Square\Models\InvoiceCustomField;
use Nikolag\Square\Models\InvoicePaymentRequest;
use Nikolag\Square\Models\InvoiceRecipient;
use Nikolag\Square\Models\Product;
use Nikolag\Square\Tests\Models\Order;
use Nikolag\Square\Tests\TestCase;
use Nikolag\Square\Utils\Constants;
use Square\Models\InvoiceStatus;

class InvoiceTest extends TestCase
{
    /**
     * Test invoice creation with basic fields.
     *
     * @return void
     */
    public function test_invoice_creation(): void
    {
        $order = factory(Order::class)->create();
        $invoice = factory(Constants::INVOICE_NAMESPACE)->create([
            'order_id' => $order->id,
        ]);

        $this->assertNotNull($invoice, 'Invoice is null');
        $this->assertEquals($order->id, $invoice->order_id, 'Order ID doesn\'t match');
        $this->assertEquals(InvoiceStatus::DRAFT, $invoice->status, 'Status should be DRAFT');
    }

    /**
     * Test invoice with recipient relationship.
     *
     * @return void
     */
    public function test_invoice_with_recipient(): void
    {
        $order = factory(Order::class)->create();
        $customer = factory(Constants::CUSTOMER_NAMESPACE)->create();
        $invoice = factory(Constants::INVOICE_NAMESPACE)->create([
            'order_id' => $order->id,
        ]);

        $recipient = factory(Constants::INVOICE_RECIPIENT_NAMESPACE)->create([
            'invoice_id' => $invoice->id,
            'customer_id' => $customer->id,
        ]);

        $this->assertNotNull($invoice->recipient, 'Recipient is null');
        $this->assertEquals($recipient->id, $invoice->recipient->id, 'Recipient ID doesn\'t match');
        $this->assertEquals($customer->id, $invoice->recipient->customer_id, 'Customer ID doesn\'t match');
    }

    /**
     * Test invoice with payment requests.
     *
     * @return void
     */
    public function test_invoice_with_payment_requests(): void
    {
        $order = factory(Order::class)->create();
        $invoice = factory(Constants::INVOICE_NAMESPACE)->create([
            'order_id' => $order->id,
        ]);

        $paymentRequest1 = factory(Constants::INVOICE_PAYMENT_REQUEST_NAMESPACE)->create([
            'invoice_id' => $invoice->id,
            'request_type' => 'BALANCE',
        ]);

        $paymentRequest2 = factory(Constants::INVOICE_PAYMENT_REQUEST_NAMESPACE)->create([
            'invoice_id' => $invoice->id,
            'request_type' => 'INSTALLMENT',
        ]);

        $this->assertNotNull($invoice->paymentRequests, 'Payment requests are null');
        $this->assertCount(2, $invoice->paymentRequests, 'Payment requests count doesn\'t match');
    }

    /**
     * Test invoice with accepted payment methods.
     *
     * @return void
     */
    public function test_invoice_with_accepted_payment_methods(): void
    {
        $order = factory(Order::class)->create();
        $invoice = factory(Constants::INVOICE_NAMESPACE)->create([
            'order_id' => $order->id,
        ]);

        $methods = factory(Constants::INVOICE_ACCEPTED_PAYMENT_METHODS_NAMESPACE)->create([
            'invoice_id' => $invoice->id,
            'card' => true,
            'bank_account' => true,
        ]);

        $this->assertNotNull($invoice->acceptedPaymentMethods, 'Accepted payment methods are null');
        $this->assertTrue($invoice->acceptedPaymentMethods->card, 'Card should be enabled');
        $this->assertTrue($invoice->acceptedPaymentMethods->bank_account, 'Bank account should be enabled');
    }

    /**
     * Test invoice with custom fields.
     *
     * @return void
     */
    public function test_invoice_with_custom_fields(): void
    {
        $order = factory(Order::class)->create();
        $invoice = factory(Constants::INVOICE_NAMESPACE)->create([
            'order_id' => $order->id,
        ]);

        $customField1 = factory(Constants::INVOICE_CUSTOM_FIELD_NAMESPACE)->create([
            'invoice_id' => $invoice->id,
            'label' => 'PO Number',
            'value' => 'PO-12345',
        ]);

        $customField2 = factory(Constants::INVOICE_CUSTOM_FIELD_NAMESPACE)->create([
            'invoice_id' => $invoice->id,
            'label' => 'Project Code',
            'value' => 'PROJ-ABC',
        ]);

        $this->assertNotNull($invoice->customFields, 'Custom fields are null');
        $this->assertCount(2, $invoice->customFields, 'Custom fields count doesn\'t match');
    }

    /**
     * Test invoice terminal state check.
     *
     * @return void
     */
    public function test_invoice_terminal_state_check(): void
    {
        $order = factory(Order::class)->create();

        $draftInvoice = factory(Constants::INVOICE_NAMESPACE)->states(InvoiceStatus::DRAFT)->create([
            'order_id' => $order->id,
        ]);
        $this->assertFalse($draftInvoice->isTerminal(), 'DRAFT should not be terminal');

        $order2 = factory(Order::class)->create();
        $unpaidInvoice = factory(Constants::INVOICE_NAMESPACE)->states(InvoiceStatus::UNPAID)->create([
            'order_id' => $order2->id,
        ]);
        $this->assertFalse($unpaidInvoice->isTerminal(), 'UNPAID should not be terminal');

        $order3 = factory(Order::class)->create();
        $paidInvoice = factory(Constants::INVOICE_NAMESPACE)->states(InvoiceStatus::PAID)->create([
            'order_id' => $order3->id,
        ]);
        $this->assertTrue($paidInvoice->isTerminal(), 'PAID should be terminal');

        $order4 = factory(Order::class)->create();
        $canceledInvoice = factory(Constants::INVOICE_NAMESPACE)->states(InvoiceStatus::CANCELED)->create([
            'order_id' => $order4->id,
        ]);
        $this->assertTrue($canceledInvoice->isTerminal(), 'CANCELED should be terminal');
    }
}
