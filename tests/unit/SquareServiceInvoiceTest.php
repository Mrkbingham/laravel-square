<?php

namespace Nikolag\Square\Tests\Unit;

use Nikolag\Square\Exceptions\InvalidSquareOrderException;
use Nikolag\Square\Exceptions\InvalidSquareVersionException;
use Nikolag\Square\Facades\Square;
use Nikolag\Square\Models\Invoice;
use Nikolag\Square\Models\InvoiceRecipient;
use Nikolag\Square\Models\InvoicePaymentRequest;
use Nikolag\Square\Models\InvoiceAcceptedPaymentMethods;
use Nikolag\Square\Models\Customer;
use Nikolag\Square\Models\Location;
use Nikolag\Square\Tests\Models\Order;
use Nikolag\Square\Tests\TestCase;
use Nikolag\Square\Utils\Constants;
use Square\Models\InvoiceStatus;

class SquareServiceInvoiceTest extends TestCase
{
    /**
     * Test creating a new invoice in Square (saveInvoice create path).
     *
     * @return void
     */
    public function test_save_invoice_creates_new_invoice(): void
    {
        $this->markTestSkipped('Requires Square API sandbox credentials and real API calls');

        $order = factory(Order::class)->create();
        $location = factory(Location::class)->create();
        $customer = factory(Constants::CUSTOMER_NAMESPACE)->create([
            'payment_service_id' => 'CUST_' . uniqid(),
        ]);

        // Create a draft invoice without Square ID
        $invoice = Invoice::create([
            'order_id' => $order->id,
            'location_id' => $location->id,
            'title' => 'Test Invoice',
            'description' => 'Test invoice for saveInvoice create path',
            'delivery_method' => 'EMAIL',
            'status' => InvoiceStatus::DRAFT,
        ]);

        // Create recipient
        $invoice->recipient()->create([
            'customer_id' => $customer->id,
            'email_address' => 'test@example.com',
            'given_name' => 'John',
            'family_name' => 'Doe',
        ]);

        // Create payment request
        $invoice->paymentRequests()->create([
            'request_type' => 'BALANCE',
            'due_date' => now()->addDays(30),
            'tipping_enabled' => false,
        ]);

        // Create accepted payment methods
        $invoice->acceptedPaymentMethods()->create([
            'card' => true,
            'bank_account' => false,
        ]);

        // Verify invoice doesn't have Square ID yet
        $this->assertNull($invoice->payment_service_id);
        $this->assertNull($invoice->payment_service_version);

        // Save to Square (create path)
        Square::saveInvoice($invoice);

        // Refresh from database
        $invoice->refresh();

        // Verify Square ID and version were set
        $this->assertNotNull($invoice->payment_service_id);
        $this->assertNotNull($invoice->payment_service_version);
        $this->assertEquals(InvoiceStatus::DRAFT, $invoice->status);
    }
}
