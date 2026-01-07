<?php

namespace Nikolag\Square\Tests\Unit;

use Nikolag\Square\Exceptions\InvalidInvoiceStateException;
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
use Nikolag\Square\Tests\Traits\MocksSquareConfigDependency;
use Nikolag\Square\Utils\Constants;
use Square\Models\InvoiceDeliveryMethod;
use Square\Models\InvoiceStatus;

class SquareServiceInvoiceTest extends TestCase
{
    use MocksSquareConfigDependency;
    /**
     * Test creating a new invoice in Square (saveInvoice create path).
     *
     * @return void
     */
    public function test_save_invoice_creates_new_invoice(): void
    {
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
            'delivery_method' => InvoiceDeliveryMethod::EMAIL,
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

        // Mock the Square API response for creating an invoice
        $this->mockCreateInvoiceSuccess([
            'invoice_id' => 'inv_created_123',
            'version' => 1,
            'status' => InvoiceStatus::DRAFT,
            'delivery_method' => InvoiceDeliveryMethod::EMAIL,
            'location_id' => $location->id,
            'order_id' => $order->id,
            'invoice_number' => 'INV-001',
        ]);

        // Save to Square (create path)
        Square::saveInvoice($invoice);

        // Refresh from database
        $invoice->refresh();

        // Verify Square ID and version were set
        $this->assertNotNull($invoice->payment_service_id);
        $this->assertEquals(1, $invoice->payment_service_version);
        $this->assertEquals(InvoiceStatus::DRAFT, $invoice->status);
    }

    /**
     * Test updating an existing invoice in Square (saveInvoice update path).
     *
     * @return void
     */
    public function test_save_invoice_updates_existing_invoice(): void
    {
        $order = factory(Order::class)->create();
        $location = factory(Location::class)->create();

        // Create an invoice that already exists in Square
        $invoice = factory(Constants::INVOICE_NAMESPACE)->create([
            'order_id' => $order->id,
            'location_id' => $location->id,
            'payment_service_id' => 'inv_existing_456',
            'payment_service_version' => 1,
            'title' => 'Original Title',
            'status' => InvoiceStatus::DRAFT,
            'delivery_method' => InvoiceDeliveryMethod::EMAIL,
        ]);

        // Add required payment request
        $invoice->paymentRequests()->create([
            'request_type' => 'BALANCE',
            'due_date' => now()->addDays(30),
        ]);

        // Add required accepted payment methods
        $invoice->acceptedPaymentMethods()->create([
            'card' => true,
        ]);

        // Update the title
        $invoice->title = 'Updated Title';

        // Mock the Square API response for updating an invoice
        $this->mockUpdateInvoiceSuccess([
            'invoice_id' => 'inv_existing_456',
            'version' => 2,
            'status' => InvoiceStatus::DRAFT,
            'delivery_method' => InvoiceDeliveryMethod::EMAIL,
            'location_id' => $location->id,
            'order_id' => $order->id,
            'title' => 'Updated Title',
        ]);

        // Save to Square (update path)
        Square::saveInvoice($invoice);

        // Refresh from database
        $invoice->refresh();

        // Verify version was incremented
        $this->assertEquals(2, $invoice->payment_service_version);
        $this->assertEquals('Updated Title', $invoice->title);
    }

    /**
     * Test saveInvoice throws exception for terminal state invoices.
     *
     * @return void
     */
    public function test_save_invoice_terminal_state_protection(): void
    {
        $this->expectException(InvalidInvoiceStateException::class);
        $this->expectExceptionMessage('Cannot update invoice in PAID status');

        $order = factory(Order::class)->create();
        $location = factory(Location::class)->create();

        $invoice = factory(Constants::INVOICE_NAMESPACE)->states(InvoiceStatus::PAID)->create([
            'order_id' => $order->id,
            'location_id' => $location->id,
            'payment_service_id' => 'inv_paid_' . uniqid(),
            'payment_service_version' => 3,
        ]);

        // This should throw an exception
        Square::saveInvoice($invoice);
    }

    /**
     * Test saveInvoice throws exception for CANCELED invoice.
     *
     * @return void
     */
    public function test_save_invoice_canceled_state_protection(): void
    {
        $this->expectException(InvalidInvoiceStateException::class);
        $this->expectExceptionMessage('Cannot update invoice in CANCELED status');

        $order = factory(Order::class)->create();
        $location = factory(Location::class)->create();

        $invoice = factory(Constants::INVOICE_NAMESPACE)->states(InvoiceStatus::CANCELED)->create([
            'order_id' => $order->id,
            'location_id' => $location->id,
            'payment_service_id' => 'inv_canceled_' . uniqid(),
            'payment_service_version' => 2,
        ]);

        // This should throw an exception
        Square::saveInvoice($invoice);
    }

    /**
     * Test updateSquareInvoice throws exception when version is missing.
     *
     * @return void
     */
    public function test_update_invoice_missing_version_throws_exception(): void
    {
        $this->expectException(InvalidSquareVersionException::class);
        $this->expectExceptionMessage('Cannot update invoice: version is missing');

        $order = factory(Order::class)->create();
        $location = factory(Location::class)->create();

        // Create invoice with Square ID but no version
        $invoice = factory(Constants::INVOICE_NAMESPACE)->create([
            'order_id' => $order->id,
            'location_id' => $location->id,
            'payment_service_id' => 'inv_no_version_' . uniqid(),
            'payment_service_version' => null, // Missing version
            'status' => InvoiceStatus::DRAFT,
            'delivery_method' => InvoiceDeliveryMethod::EMAIL,
        ]);

        // This should throw an exception
        Square::saveInvoice($invoice);
    }

    /**
     * Test publishInvoice successfully publishes a draft invoice.
     *
     * @return void
     */
    public function test_publish_invoice_success(): void
    {
        $order = factory(Order::class)->create();
        $location = factory(Location::class)->create();

        $invoice = factory(Constants::INVOICE_NAMESPACE)->states(InvoiceStatus::DRAFT)->create([
            'order_id' => $order->id,
            'location_id' => $location->id,
            'payment_service_id' => 'inv_draft_' . uniqid(),
            'payment_service_version' => 1,
        ]);

        // Mock the Square API response for publishing an invoice
        $this->mockPublishInvoiceSuccess([
            'invoice_id' => $invoice->payment_service_id,
            'version' => 2,
            'status' => InvoiceStatus::UNPAID,
            'location_id' => $location->id,
            'order_id' => $order->id,
            'public_url' => 'https://squareup.com/invoice/inv_draft_test',
        ]);

        // Publish the invoice
        Square::publishInvoice($invoice);

        // Refresh from database
        $invoice->refresh();

        // Verify status changed to UNPAID and version incremented
        $this->assertEquals(InvoiceStatus::UNPAID, $invoice->status);
        $this->assertEquals(2, $invoice->payment_service_version);
        $this->assertNotNull($invoice->public_url);
    }

    /**
     * Test publishInvoice throws exception for non-draft invoices.
     *
     * @return void
     */
    public function test_publish_invoice_non_draft_throws_exception(): void
    {
        $this->expectException(InvalidInvoiceStateException::class);
        $this->expectExceptionMessage('Only DRAFT invoices can be published');

        $order = factory(Order::class)->create();
        $location = factory(Location::class)->create();

        $invoice = factory(Constants::INVOICE_NAMESPACE)->states(InvoiceStatus::UNPAID)->create([
            'order_id' => $order->id,
            'location_id' => $location->id,
            'payment_service_id' => 'inv_unpaid_' . uniqid(),
            'payment_service_version' => 2,
        ]);

        // This should throw an exception
        Square::publishInvoice($invoice);
    }

    /**
     * Test publishInvoice throws exception when version is missing.
     *
     * @return void
     */
    public function test_publish_invoice_missing_version_throws_exception(): void
    {
        $this->expectException(InvalidSquareVersionException::class);
        $this->expectExceptionMessage('Cannot publish invoice: version is missing');

        $order = factory(Order::class)->create();
        $location = factory(Location::class)->create();

        $invoice = factory(Constants::INVOICE_NAMESPACE)->states(InvoiceStatus::DRAFT)->create([
            'order_id' => $order->id,
            'location_id' => $location->id,
            'payment_service_id' => 'inv_draft_no_ver_' . uniqid(),
            'payment_service_version' => null,
        ]);

        // This should throw an exception
        Square::publishInvoice($invoice);
    }

    /**
     * Test getInvoice retrieves an invoice from Square.
     *
     * @return void
     */
    public function test_get_invoice_success(): void
    {
        $squareInvoiceId = 'inv_test_' . uniqid();

        // Mock the Square API response for retrieving an invoice
        $this->mockGetInvoiceSuccess([
            'invoice_id' => $squareInvoiceId,
            'version' => 1,
            'status' => InvoiceStatus::DRAFT,
            'delivery_method' => InvoiceDeliveryMethod::EMAIL,
            'location_id' => 'main',
            'order_id' => 'order_123',
            'title' => 'Test Invoice for Retrieval',
            'description' => 'Testing getInvoice method',
        ]);

        // Retrieve the invoice from Square
        $squareInvoice = Square::getInvoice($squareInvoiceId);

        // Verify returned object
        $this->assertInstanceOf(\Square\Models\Invoice::class, $squareInvoice);
        $this->assertEquals($squareInvoiceId, $squareInvoice->getId());
        $this->assertEquals('Test Invoice for Retrieval', $squareInvoice->getTitle());
    }

    /**
     * Test getInvoice throws exception for non-existent invoice.
     *
     * @return void
     */
    public function test_get_invoice_not_found_throws_exception(): void
    {
        $this->expectException(\Nikolag\Square\Exception::class);

        // Mock the Square API error response for invoice not found
        $this->mockGetInvoiceError('Invoice not found', 404);

        // Try to retrieve a non-existent invoice
        Square::getInvoice('inv_does_not_exist_12345');
    }

    /**
     * Test invoice with all related models can be saved successfully.
     *
     * @return void
     */
    public function test_save_invoice_with_all_relationships(): void
    {
        $order = factory(Order::class)->create();
        $location = factory(Location::class)->create();
        $customer = factory(Constants::CUSTOMER_NAMESPACE)->create([
            'payment_service_id' => 'CUST_' . uniqid(),
        ]);

        // Create invoice with all relationships
        $invoice = Invoice::create([
            'order_id' => $order->id,
            'location_id' => $location->id,
            'title' => 'Complete Invoice Test',
            'description' => 'Invoice with all relationships',
            'delivery_method' => InvoiceDeliveryMethod::EMAIL,
            'status' => InvoiceStatus::DRAFT,
            'sale_or_service_date' => now()->subDays(5),
            'timezone' => 'America/New_York',
            'store_payment_method_enabled' => true,
        ]);

        // Add recipient
        $invoice->recipient()->create([
            'customer_id' => $customer->id,
            'email_address' => 'complete@example.com',
            'given_name' => 'Complete',
            'family_name' => 'Test',
            'company_name' => 'Test Company',
            'phone_number' => '+1234567890',
            'address_line_1' => '123 Test St',
            'locality' => 'Test City',
            'postal_code' => '12345',
            'country' => 'US',
        ]);

        // Add multiple payment requests
        $invoice->paymentRequests()->create([
            'request_type' => 'DEPOSIT',
            'due_date' => now()->addDays(7),
            'percentage_requested' => '25',
            'tipping_enabled' => false,
        ]);

        $invoice->paymentRequests()->create([
            'request_type' => 'BALANCE',
            'due_date' => now()->addDays(30),
            'tipping_enabled' => true,
        ]);

        // Add accepted payment methods
        $invoice->acceptedPaymentMethods()->create([
            'card' => true,
            'square_gift_card' => true,
            'bank_account' => true,
            'buy_now_pay_later' => false,
            'cash_app_pay' => false,
        ]);

        // Add custom fields
        $invoice->customFields()->create([
            'label' => 'PO Number',
            'value' => 'PO-2024-001',
            'placement' => 'ABOVE_LINE_ITEMS',
        ]);

        $invoice->customFields()->create([
            'label' => 'Project',
            'value' => 'Website Redesign',
            'placement' => 'BELOW_LINE_ITEMS',
        ]);

        // Mock the Square API response for creating an invoice
        $this->mockCreateInvoiceSuccess([
            'invoice_id' => 'inv_complete_' . uniqid(),
            'version' => 1,
            'status' => InvoiceStatus::DRAFT,
            'delivery_method' => InvoiceDeliveryMethod::EMAIL,
            'location_id' => $location->id,
            'order_id' => $order->id,
            'invoice_number' => 'INV-COMPLETE-001',
        ]);

        // Save to Square
        Square::saveInvoice($invoice);

        // Refresh and verify
        $invoice->refresh();

        $this->assertNotNull($invoice->payment_service_id);
        $this->assertNotNull($invoice->payment_service_version);
        $this->assertNotNull($invoice->recipient);
        $this->assertCount(2, $invoice->paymentRequests);
        $this->assertNotNull($invoice->acceptedPaymentMethods);
        $this->assertCount(2, $invoice->customFields);
    }

    /**
     * Test updateInvoice throws exception on version conflict.
     *
     * @return void
     */
    public function test_update_invoice_version_conflict(): void
    {
        $this->expectException(InvalidSquareVersionException::class);
        $this->expectExceptionMessage('Version mismatch');

        $order = factory(Order::class)->create();
        $location = factory(Location::class)->create();

        $invoice = factory(Constants::INVOICE_NAMESPACE)->create([
            'order_id' => $order->id,
            'location_id' => $location->id,
            'payment_service_id' => 'inv_conflict_' . uniqid(),
            'payment_service_version' => 1,
            'title' => 'Original Title',
            'status' => InvoiceStatus::DRAFT,
            'delivery_method' => InvoiceDeliveryMethod::EMAIL,
        ]);

        // Add required payment request
        $invoice->paymentRequests()->create([
            'request_type' => 'BALANCE',
            'due_date' => now()->addDays(30),
        ]);

        // Add required accepted payment methods
        $invoice->acceptedPaymentMethods()->create([
            'card' => true,
        ]);

        // Update the title
        $invoice->title = 'Updated Title';

        // Mock version conflict error (409)
        $this->mockUpdateInvoiceVersionConflict();

        // This should throw InvalidSquareVersionException
        Square::saveInvoice($invoice);
    }

    /**
     * Test createInvoice throws exception on API error.
     *
     * @return void
     */
    public function test_create_invoice_api_error(): void
    {
        $this->expectException(\Nikolag\Square\Exception::class);

        $order = factory(Order::class)->create();
        $location = factory(Location::class)->create();
        $customer = factory(Constants::CUSTOMER_NAMESPACE)->create([
            'payment_service_id' => 'CUST_' . uniqid(),
        ]);

        $invoice = Invoice::create([
            'order_id' => $order->id,
            'location_id' => $location->id,
            'title' => 'Test Invoice',
            'description' => 'Test invoice for API error',
            'delivery_method' => InvoiceDeliveryMethod::EMAIL,
            'status' => InvoiceStatus::DRAFT,
        ]);

        $invoice->recipient()->create([
            'customer_id' => $customer->id,
            'email_address' => 'test@example.com',
            'given_name' => 'John',
            'family_name' => 'Doe',
        ]);

        $invoice->paymentRequests()->create([
            'request_type' => 'BALANCE',
            'due_date' => now()->addDays(30),
            'tipping_enabled' => false,
        ]);

        $invoice->acceptedPaymentMethods()->create([
            'card' => true,
            'bank_account' => false,
        ]);

        // Mock API error (500)
        $this->mockCreateInvoiceError('Internal server error', 500);

        // This should throw exception
        Square::saveInvoice($invoice);
    }

    /**
     * Test publishInvoice throws exception on API error.
     *
     * @return void
     */
    public function test_publish_invoice_api_error(): void
    {
        $this->expectException(\Nikolag\Square\Exception::class);

        $order = factory(Order::class)->create();
        $location = factory(Location::class)->create();

        $invoice = factory(Constants::INVOICE_NAMESPACE)->states(InvoiceStatus::DRAFT)->create([
            'order_id' => $order->id,
            'location_id' => $location->id,
            'payment_service_id' => 'inv_draft_' . uniqid(),
            'payment_service_version' => 1,
        ]);

        // Mock API error (500)
        $this->mockPublishInvoiceError('Unable to publish invoice', 500);

        // This should throw exception
        Square::publishInvoice($invoice);
    }
}
