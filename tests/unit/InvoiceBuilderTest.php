<?php

namespace Nikolag\Square\Tests\Unit;

use Nikolag\Square\Builders\InvoiceBuilder;
use Nikolag\Square\Exceptions\MissingPropertyException;
use Nikolag\Square\Models\Invoice;
use Nikolag\Square\Models\Location;
use Nikolag\Square\Tests\Models\Order;
use Nikolag\Square\Tests\TestCase;
use Nikolag\Square\Utils\Constants;
use Square\Models\CreateInvoiceRequest;
use Square\Models\Invoice as SquareInvoice;
use Square\Models\InvoiceStatus;
use Square\Models\PublishInvoiceRequest;
use Square\Models\UpdateInvoiceRequest;
use Square\Models\Builders\InvoiceBuilder as SquareInvoiceBuilder;
use Square\Models\Builders\MoneyBuilder;

class InvoiceBuilderTest extends TestCase
{
    private InvoiceBuilder $builder;

    /**
     * @return void
     */
    public function setUp(): void
    {
        parent::setUp();
        $this->builder = new InvoiceBuilder();
    }

    /**
     * Test buildCreateInvoiceRequest with minimal invoice.
     *
     * @return void
     */
    public function test_build_create_invoice_request_minimal(): void
    {
        $order = factory(Order::class)->create();
        $location = factory(Location::class)->create();

        $invoice = Invoice::create([
            'order_id' => $order->id,
            'location_id' => $location->id,
            'status' => InvoiceStatus::DRAFT,
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

        $request = $this->builder->buildCreateInvoiceRequest($invoice);

        $this->assertInstanceOf(CreateInvoiceRequest::class, $request);
        $this->assertNotNull($request->getInvoice());
        $this->assertEquals($location->square_id, $request->getInvoice()->getLocationId());
        $this->assertEquals($order->payment_service_id, $request->getInvoice()->getOrderId());
        $this->assertNotNull($request->getIdempotencyKey());
    }

    /**
     * Test buildCreateInvoiceRequest with all basic fields.
     *
     * @return void
     */
    public function test_build_create_invoice_request_with_all_fields(): void
    {
        $order = factory(Order::class)->create();
        $location = factory(Location::class)->create();

        $invoice = Invoice::create([
            'order_id' => $order->id,
            'location_id' => $location->id,
            'title' => 'Test Invoice',
            'description' => 'Test invoice description',
            'invoice_number' => 'INV-001',
            'delivery_method' => 'EMAIL',
            'sale_or_service_date' => now()->subDays(5),
            'store_payment_method_enabled' => true,
            'status' => InvoiceStatus::DRAFT,
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

        $request = $this->builder->buildCreateInvoiceRequest($invoice);

        $this->assertInstanceOf(CreateInvoiceRequest::class, $request);
        $squareInvoice = $request->getInvoice();

        $this->assertEquals('Test Invoice', $squareInvoice->getTitle());
        $this->assertEquals('Test invoice description', $squareInvoice->getDescription());
        $this->assertEquals('INV-001', $squareInvoice->getInvoiceNumber());
        $this->assertEquals('EMAIL', $squareInvoice->getDeliveryMethod());
        $this->assertTrue($squareInvoice->getStorePaymentMethodEnabled());
    }

    /**
     * Test buildCreateInvoiceRequest with recipient.
     *
     * @return void
     */
    public function test_build_create_invoice_request_with_recipient(): void
    {
        $order = factory(Order::class)->create();
        $location = factory(Location::class)->create();
        $customer = factory(Constants::CUSTOMER_NAMESPACE)->create([
            'payment_service_id' => 'CUST_' . uniqid(),
        ]);

        $invoice = Invoice::create([
            'order_id' => $order->id,
            'location_id' => $location->id,
            'status' => InvoiceStatus::DRAFT,
        ]);

        $invoice->recipient()->create([
            'customer_id' => $customer->id,
            'email_address' => 'test@example.com',
            'given_name' => 'John',
            'family_name' => 'Doe',
            'company_name' => 'Test Company',
            'phone_number' => '+1234567890',
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

        $request = $this->builder->buildCreateInvoiceRequest($invoice);

        $this->assertInstanceOf(CreateInvoiceRequest::class, $request);
        $recipient = $request->getInvoice()->getPrimaryRecipient();

        $this->assertNotNull($recipient);
        $this->assertEquals('test@example.com', $recipient->getEmailAddress());
        $this->assertEquals('John', $recipient->getGivenName());
        $this->assertEquals('Doe', $recipient->getFamilyName());
        $this->assertEquals('Test Company', $recipient->getCompanyName());
        $this->assertEquals('+1234567890', $recipient->getPhoneNumber());
        $this->assertEquals($customer->payment_service_id, $recipient->getCustomerId());
    }

    /**
     * Test buildCreateInvoiceRequest with recipient address.
     *
     * @return void
     */
    public function test_build_create_invoice_request_with_recipient_address(): void
    {
        $order = factory(Order::class)->create();
        $location = factory(Location::class)->create();
        $customer = factory(Constants::CUSTOMER_NAMESPACE)->create([
            'payment_service_id' => 'CUST_' . uniqid(),
        ]);

        $invoice = Invoice::create([
            'order_id' => $order->id,
            'location_id' => $location->id,
            'status' => InvoiceStatus::DRAFT,
        ]);

        $invoice->recipient()->create([
            'customer_id' => $customer->id,
            'email_address' => 'test@example.com',
            'given_name' => 'John',
            'family_name' => 'Doe',
            'address_line_1' => '123 Test St',
            'address_line_2' => 'Apt 4',
            'locality' => 'Test City',
            'administrative_district_level_1' => 'CA',
            'postal_code' => '12345',
            'country' => 'US',
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

        $request = $this->builder->buildCreateInvoiceRequest($invoice);

        $recipient = $request->getInvoice()->getPrimaryRecipient();
        $address = $recipient->getAddress();

        $this->assertNotNull($address);
        $this->assertEquals('123 Test St', $address->getAddressLine1());
        $this->assertEquals('Apt 4', $address->getAddressLine2());
        $this->assertEquals('Test City', $address->getLocality());
        $this->assertEquals('CA', $address->getAdministrativeDistrictLevel1());
        $this->assertEquals('12345', $address->getPostalCode());
        $this->assertEquals('US', $address->getCountry());
    }

    /**
     * Test buildCreateInvoiceRequest with payment requests.
     *
     * @return void
     */
    public function test_build_create_invoice_request_with_payment_requests(): void
    {
        $order = factory(Order::class)->create();
        $location = factory(Location::class)->create();

        $invoice = Invoice::create([
            'order_id' => $order->id,
            'location_id' => $location->id,
            'status' => InvoiceStatus::DRAFT,
        ]);

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

        // Add required accepted payment methods
        $invoice->acceptedPaymentMethods()->create([
            'card' => true,
        ]);

        $request = $this->builder->buildCreateInvoiceRequest($invoice);

        $paymentRequests = $request->getInvoice()->getPaymentRequests();

        $this->assertNotNull($paymentRequests);
        $this->assertCount(2, $paymentRequests);
        $this->assertEquals('DEPOSIT', $paymentRequests[0]->getRequestType());
        $this->assertEquals('25', $paymentRequests[0]->getPercentageRequested());
        $this->assertFalse($paymentRequests[0]->getTippingEnabled());
        $this->assertEquals('BALANCE', $paymentRequests[1]->getRequestType());
        $this->assertTrue($paymentRequests[1]->getTippingEnabled());
    }

    /**
     * Test buildCreateInvoiceRequest with payment request fixed amount.
     *
     * @return void
     */
    public function test_build_create_invoice_request_with_fixed_amount_payment_request(): void
    {
        $order = factory(Order::class)->create();
        $location = factory(Location::class)->create();

        $invoice = Invoice::create([
            'order_id' => $order->id,
            'location_id' => $location->id,
            'status' => InvoiceStatus::DRAFT,
        ]);

        $invoice->paymentRequests()->create([
            'request_type' => 'BALANCE',
            'due_date' => now()->addDays(30),
            'fixed_amount_requested_money_amount' => 10000,
            'fixed_amount_requested_money_currency' => 'USD',
            'tipping_enabled' => false,
        ]);

        // Add required accepted payment methods
        $invoice->acceptedPaymentMethods()->create([
            'card' => true,
        ]);

        $request = $this->builder->buildCreateInvoiceRequest($invoice);

        $paymentRequests = $request->getInvoice()->getPaymentRequests();
        $fixedAmount = $paymentRequests[0]->getFixedAmountRequestedMoney();

        $this->assertNotNull($fixedAmount);
        $this->assertEquals(10000, $fixedAmount->getAmount());
        $this->assertEquals('USD', $fixedAmount->getCurrency());
    }

    /**
     * Test buildCreateInvoiceRequest with accepted payment methods.
     *
     * @return void
     */
    public function test_build_create_invoice_request_with_accepted_payment_methods(): void
    {
        $order = factory(Order::class)->create();
        $location = factory(Location::class)->create();

        $invoice = Invoice::create([
            'order_id' => $order->id,
            'location_id' => $location->id,
            'status' => InvoiceStatus::DRAFT,
        ]);

        $invoice->acceptedPaymentMethods()->create([
            'card' => true,
            'square_gift_card' => true,
            'bank_account' => false,
            'buy_now_pay_later' => false,
            'cash_app_pay' => true,
        ]);

        // Add required payment request
        $invoice->paymentRequests()->create([
            'request_type' => 'BALANCE',
            'due_date' => now()->addDays(30),
        ]);

        $request = $this->builder->buildCreateInvoiceRequest($invoice);

        $methods = $request->getInvoice()->getAcceptedPaymentMethods();

        $this->assertNotNull($methods);
        $this->assertTrue($methods->getCard());
        $this->assertTrue($methods->getSquareGiftCard());
        $this->assertNull($methods->getBankAccount()); // Square SDK only sets true values
        $this->assertNull($methods->getBuyNowPayLater()); // Square SDK only sets true values
        $this->assertTrue($methods->getCashAppPay());
    }

    /**
     * Test buildCreateInvoiceRequest with custom fields.
     *
     * @return void
     */
    public function test_build_create_invoice_request_with_custom_fields(): void
    {
        $order = factory(Order::class)->create();
        $location = factory(Location::class)->create();

        $invoice = Invoice::create([
            'order_id' => $order->id,
            'location_id' => $location->id,
            'status' => InvoiceStatus::DRAFT,
        ]);

        $invoice->customFields()->create([
            'label' => 'PO Number',
            'value' => 'PO-12345',
            'placement' => 'ABOVE_LINE_ITEMS',
        ]);

        $invoice->customFields()->create([
            'label' => 'Project',
            'value' => 'Website Redesign',
            'placement' => 'BELOW_LINE_ITEMS',
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

        $request = $this->builder->buildCreateInvoiceRequest($invoice);

        $customFields = $request->getInvoice()->getCustomFields();

        $this->assertNotNull($customFields);
        $this->assertCount(2, $customFields);
        $this->assertEquals('PO Number', $customFields[0]->getLabel());
        $this->assertEquals('PO-12345', $customFields[0]->getValue());
        $this->assertEquals('ABOVE_LINE_ITEMS', $customFields[0]->getPlacement());
        $this->assertEquals('Project', $customFields[1]->getLabel());
        $this->assertEquals('Website Redesign', $customFields[1]->getValue());
        $this->assertEquals('BELOW_LINE_ITEMS', $customFields[1]->getPlacement());
    }

    /**
     * Test buildUpdateInvoiceRequest with version.
     *
     * @return void
     */
    public function test_build_update_invoice_request_with_version(): void
    {
        $order = factory(Order::class)->create();
        $location = factory(Location::class)->create();

        $invoice = factory(Constants::INVOICE_NAMESPACE)->create([
            'order_id' => $order->id,
            'location_id' => $location->id,
            'payment_service_id' => 'inv_' . uniqid(),
            'payment_service_version' => 1,
            'title' => 'Original Title',
            'status' => InvoiceStatus::DRAFT,
        ]);

        $invoice->title = 'Updated Title';

        // Add required payment request
        $invoice->paymentRequests()->create([
            'request_type' => 'BALANCE',
            'due_date' => now()->addDays(30),
        ]);

        // Add required accepted payment methods
        $invoice->acceptedPaymentMethods()->create([
            'card' => true,
        ]);

        $request = $this->builder->buildUpdateInvoiceRequest($invoice, 1);

        $this->assertInstanceOf(UpdateInvoiceRequest::class, $request);
        $this->assertNotNull($request->getInvoice());
        $this->assertEquals(1, $request->getInvoice()->getVersion());
        $this->assertEquals('Updated Title', $request->getInvoice()->getTitle());
        $this->assertNotNull($request->getIdempotencyKey());
    }

    /**
     * Test buildUpdateInvoiceRequest with all fields.
     *
     * @return void
     */
    public function test_build_update_invoice_request_with_all_fields(): void
    {
        $order = factory(Order::class)->create();
        $location = factory(Location::class)->create();
        $customer = factory(Constants::CUSTOMER_NAMESPACE)->create([
            'payment_service_id' => 'CUST_' . uniqid(),
        ]);

        $invoice = factory(Constants::INVOICE_NAMESPACE)->create([
            'order_id' => $order->id,
            'location_id' => $location->id,
            'payment_service_id' => 'inv_' . uniqid(),
            'payment_service_version' => 1,
            'title' => 'Updated Invoice',
            'description' => 'Updated description',
            'invoice_number' => 'INV-002',
            'delivery_method' => 'EMAIL',
            'sale_or_service_date' => now()->subDays(3),
            'store_payment_method_enabled' => true,
            'status' => InvoiceStatus::DRAFT,
        ]);

        $invoice->recipient()->create([
            'customer_id' => $customer->id,
            'email_address' => 'updated@example.com',
            'given_name' => 'Jane',
            'family_name' => 'Smith',
        ]);

        $invoice->paymentRequests()->create([
            'request_type' => 'BALANCE',
            'due_date' => now()->addDays(14),
            'tipping_enabled' => false,
        ]);

        $invoice->acceptedPaymentMethods()->create([
            'card' => true,
            'bank_account' => true,
        ]);

        // Note: Payment requests already added above

        $request = $this->builder->buildUpdateInvoiceRequest($invoice, 1);

        $squareInvoice = $request->getInvoice();

        $this->assertEquals(1, $squareInvoice->getVersion());
        $this->assertEquals('Updated Invoice', $squareInvoice->getTitle());
        $this->assertEquals('Updated description', $squareInvoice->getDescription());
        $this->assertEquals('INV-002', $squareInvoice->getInvoiceNumber());
        $this->assertNotNull($squareInvoice->getPrimaryRecipient());
        $this->assertEquals('updated@example.com', $squareInvoice->getPrimaryRecipient()->getEmailAddress());
        $this->assertNotNull($squareInvoice->getPaymentRequests());
        $this->assertCount(1, $squareInvoice->getPaymentRequests());
        $this->assertNotNull($squareInvoice->getAcceptedPaymentMethods());
    }

    /**
     * Test buildPublishInvoiceRequest.
     *
     * @return void
     */
    public function test_build_publish_invoice_request(): void
    {
        $version = 1;

        $request = $this->builder->buildPublishInvoiceRequest($version);

        $this->assertInstanceOf(PublishInvoiceRequest::class, $request);
        $this->assertEquals($version, $request->getVersion());
        $this->assertNotNull($request->getIdempotencyKey());
    }

    /**
     * Test syncFromSquareResponse updates local invoice.
     *
     * @return void
     */
    public function test_sync_from_square_response_updates_local_invoice(): void
    {
        $order = factory(Order::class)->create();
        $location = factory(Location::class)->create();

        $invoice = Invoice::create([
            'order_id' => $order->id,
            'location_id' => $location->id,
            'title' => 'Test Invoice',
            'status' => InvoiceStatus::DRAFT,
        ]);

        // Create a mock Square Invoice response
        $squareInvoice = SquareInvoiceBuilder::init()
            ->id('inv_square_123')
            ->version(2)
            ->locationId($location->id)
            ->orderId($order->id)
            ->status(InvoiceStatus::UNPAID)
            ->publicUrl('https://squareup.com/invoice/inv_square_123')
            ->invoiceNumber('INV-SQUARE-001')
            ->nextPaymentAmountMoney(
                MoneyBuilder::init()
                    ->amount(5000)
                    ->currency('USD')
                    ->build()
            )
            ->build();

        // Sync the response
        $this->builder->syncFromSquareResponse($invoice, $squareInvoice);

        // Refresh from database
        $invoice->refresh();

        // Verify all fields were synced
        $this->assertEquals('inv_square_123', $invoice->payment_service_id);
        $this->assertEquals(2, $invoice->payment_service_version);
        $this->assertEquals(InvoiceStatus::UNPAID, $invoice->status);
        $this->assertEquals('https://squareup.com/invoice/inv_square_123', $invoice->public_url);
        $this->assertEquals('INV-SQUARE-001', $invoice->invoice_number);
        $this->assertEquals(5000, $invoice->next_payment_amount_money_amount);
        $this->assertEquals('USD', $invoice->next_payment_amount_money_currency);
    }

    /**
     * Test syncFromSquareResponse handles null optional fields.
     *
     * @return void
     */
    public function test_sync_from_square_response_handles_null_fields(): void
    {
        $order = factory(Order::class)->create();
        $location = factory(Location::class)->create();

        $invoice = Invoice::create([
            'order_id' => $order->id,
            'location_id' => $location->id,
            'title' => 'Test Invoice',
            'status' => InvoiceStatus::DRAFT,
        ]);

        // Create a Square Invoice with minimal fields
        $squareInvoice = SquareInvoiceBuilder::init()
            ->id('inv_minimal_123')
            ->version(1)
            ->locationId($location->id)
            ->orderId($order->id)
            ->status(InvoiceStatus::DRAFT)
            ->build();

        // Sync the response
        $this->builder->syncFromSquareResponse($invoice, $squareInvoice);

        // Refresh from database
        $invoice->refresh();

        // Verify required fields were synced
        $this->assertEquals('inv_minimal_123', $invoice->payment_service_id);
        $this->assertEquals(1, $invoice->payment_service_version);
        $this->assertEquals(InvoiceStatus::DRAFT, $invoice->status);

        // Verify optional fields are null
        $this->assertNull($invoice->public_url);
        $this->assertNull($invoice->invoice_number);
    }

    /**
     * Test buildCreateInvoiceRequest throws exception when order is missing.
     *
     * @return void
     */
    public function test_build_create_invoice_request_throws_exception_when_order_missing(): void
    {
        $this->expectException(MissingPropertyException::class);
        $this->expectExceptionMessage('Cannot create invoice without an associated order');

        $location = factory(Location::class)->create();

        // Create an invoice without an order relationship
        $invoice = new Invoice([
            'location_id' => $location->id,
            'status' => InvoiceStatus::DRAFT,
        ]);

        $this->builder->buildCreateInvoiceRequest($invoice);
    }

    /**
     * Test buildCreateInvoiceRequest throws exception when order ID is missing.
     *
     * @return void
     */
    public function test_build_create_invoice_request_throws_exception_when_order_id_missing(): void
    {
        $this->expectException(MissingPropertyException::class);
        $this->expectExceptionMessage('Cannot create invoice without a Square order ID');

        $location = factory(Location::class)->create();

        // Create an order without a payment_service_id
        $order = factory(Order::class)->create();
        $order->payment_service_id = null;
        $order->save();

        $invoice = Invoice::create([
            'order_id' => $order->id,
            'location_id' => $location->id,
            'status' => InvoiceStatus::DRAFT,
        ]);

        $this->builder->buildCreateInvoiceRequest($invoice);
    }

    /**
     * Test buildUpdateInvoiceRequest throws exception when order is missing.
     *
     * @return void
     */
    public function test_build_update_invoice_request_throws_exception_when_order_missing(): void
    {
        $this->expectException(MissingPropertyException::class);
        $this->expectExceptionMessage('Cannot create invoice without an associated order');

        $location = factory(Location::class)->create();

        // Create an invoice without an order relationship
        $invoice = new Invoice([
            'location_id' => $location->id,
            'payment_service_id' => 'inv_' . uniqid(),
            'payment_service_version' => 1,
            'status' => InvoiceStatus::DRAFT,
        ]);

        $this->builder->buildUpdateInvoiceRequest($invoice, 1);
    }

    /**
     * Test buildUpdateInvoiceRequest throws exception when order ID is missing.
     *
     * @return void
     */
    public function test_build_update_invoice_request_throws_exception_when_order_id_missing(): void
    {
        $this->expectException(MissingPropertyException::class);
        $this->expectExceptionMessage('Cannot create invoice without a Square order ID');

        $location = factory(Location::class)->create();

        // Create an order without a payment_service_id
        $order = factory(Order::class)->create();
        $order->payment_service_id = null;
        $order->save();

        $invoice = Invoice::create([
            'order_id' => $order->id,
            'location_id' => $location->id,
            'payment_service_id' => 'inv_' . uniqid(),
            'payment_service_version' => 1,
            'status' => InvoiceStatus::DRAFT,
        ]);

        $this->builder->buildUpdateInvoiceRequest($invoice, 1);
    }

    /**
     * Test buildCreateInvoiceRequest throws exception when payment requests are missing.
     *
     * @return void
     */
    public function test_build_create_invoice_request_throws_exception_when_payment_requests_missing(): void
    {
        $this->expectException(MissingPropertyException::class);
        $this->expectExceptionMessage('Cannot create invoice without at least one payment request');

        $order = factory(Order::class)->create();
        $location = factory(Location::class)->create();

        // Create an invoice without payment requests
        $invoice = Invoice::create([
            'order_id' => $order->id,
            'location_id' => $location->id,
            'status' => InvoiceStatus::DRAFT,
        ]);

        $this->builder->buildCreateInvoiceRequest($invoice);
    }

    /**
     * Test buildCreateInvoiceRequest throws exception when payment request is missing request_type.
     *
     * @return void
     */
    public function test_build_create_invoice_request_throws_exception_when_request_type_missing(): void
    {
        $this->expectException(MissingPropertyException::class);
        $this->expectExceptionMessage('Payment request is missing required field: request_type');

        $order = factory(Order::class)->create();
        $location = factory(Location::class)->create();

        $invoice = Invoice::create([
            'order_id' => $order->id,
            'location_id' => $location->id,
            'status' => InvoiceStatus::DRAFT,
        ]);

        // Create a payment request without request_type
        $invoice->paymentRequests()->create([
            'due_date' => now()->addDays(30),
            'tipping_enabled' => false,
        ]);

        $this->builder->buildCreateInvoiceRequest($invoice);
    }

    /**
     * Test buildCreateInvoiceRequest throws exception when payment request is missing due_date.
     *
     * @return void
     */
    public function test_build_create_invoice_request_throws_exception_when_due_date_missing(): void
    {
        $this->expectException(MissingPropertyException::class);
        $this->expectExceptionMessage('Payment request is missing required field: due_date');

        $order = factory(Order::class)->create();
        $location = factory(Location::class)->create();

        $invoice = Invoice::create([
            'order_id' => $order->id,
            'location_id' => $location->id,
            'status' => InvoiceStatus::DRAFT,
        ]);

        // Create a payment request without due_date
        $invoice->paymentRequests()->create([
            'request_type' => 'BALANCE',
            'tipping_enabled' => false,
        ]);

        $this->builder->buildCreateInvoiceRequest($invoice);
    }

    /**
     * Test buildUpdateInvoiceRequest throws exception when payment requests are missing.
     *
     * @return void
     */
    public function test_build_update_invoice_request_throws_exception_when_payment_requests_missing(): void
    {
        $this->expectException(MissingPropertyException::class);
        $this->expectExceptionMessage('Cannot create invoice without at least one payment request');

        $order = factory(Order::class)->create();
        $location = factory(Location::class)->create();

        // Create an invoice without payment requests
        $invoice = Invoice::create([
            'order_id' => $order->id,
            'location_id' => $location->id,
            'payment_service_id' => 'inv_' . uniqid(),
            'payment_service_version' => 1,
            'status' => InvoiceStatus::DRAFT,
        ]);

        $this->builder->buildUpdateInvoiceRequest($invoice, 1);
    }

    /**
     * Test buildUpdateInvoiceRequest throws exception when payment request is missing request_type.
     *
     * @return void
     */
    public function test_build_update_invoice_request_throws_exception_when_request_type_missing(): void
    {
        $this->expectException(MissingPropertyException::class);
        $this->expectExceptionMessage('Payment request is missing required field: request_type');

        $order = factory(Order::class)->create();
        $location = factory(Location::class)->create();

        $invoice = Invoice::create([
            'order_id' => $order->id,
            'location_id' => $location->id,
            'payment_service_id' => 'inv_' . uniqid(),
            'payment_service_version' => 1,
            'status' => InvoiceStatus::DRAFT,
        ]);

        // Create a payment request without request_type
        $invoice->paymentRequests()->create([
            'due_date' => now()->addDays(30),
            'tipping_enabled' => false,
        ]);

        $this->builder->buildUpdateInvoiceRequest($invoice, 1);
    }

    /**
     * Test buildUpdateInvoiceRequest throws exception when payment request is missing due_date.
     *
     * @return void
     */
    public function test_build_update_invoice_request_throws_exception_when_due_date_missing(): void
    {
        $this->expectException(MissingPropertyException::class);
        $this->expectExceptionMessage('Payment request is missing required field: due_date');

        $order = factory(Order::class)->create();
        $location = factory(Location::class)->create();

        $invoice = Invoice::create([
            'order_id' => $order->id,
            'location_id' => $location->id,
            'payment_service_id' => 'inv_' . uniqid(),
            'payment_service_version' => 1,
            'status' => InvoiceStatus::DRAFT,
        ]);

        // Create a payment request without due_date
        $invoice->paymentRequests()->create([
            'request_type' => 'BALANCE',
            'tipping_enabled' => false,
        ]);

        $this->builder->buildUpdateInvoiceRequest($invoice, 1);
    }

    /**
     * Test buildCreateInvoiceRequest throws exception when accepted payment methods are missing.
     *
     * @return void
     */
    public function test_build_create_invoice_request_throws_exception_when_accepted_payment_methods_missing(): void
    {
        $this->expectException(MissingPropertyException::class);
        $this->expectExceptionMessage('Cannot create invoice without accepted payment methods');

        $order = factory(Order::class)->create();
        $location = factory(Location::class)->create();

        $invoice = Invoice::create([
            'order_id' => $order->id,
            'location_id' => $location->id,
            'status' => InvoiceStatus::DRAFT,
        ]);

        // Add payment request but no accepted payment methods
        $invoice->paymentRequests()->create([
            'request_type' => 'BALANCE',
            'due_date' => now()->addDays(30),
        ]);

        $this->builder->buildCreateInvoiceRequest($invoice);
    }

    /**
     * Test buildUpdateInvoiceRequest throws exception when accepted payment methods are missing.
     *
     * @return void
     */
    public function test_build_update_invoice_request_throws_exception_when_accepted_payment_methods_missing(): void
    {
        $this->expectException(MissingPropertyException::class);
        $this->expectExceptionMessage('Cannot create invoice without accepted payment methods');

        $order = factory(Order::class)->create();
        $location = factory(Location::class)->create();

        $invoice = Invoice::create([
            'order_id' => $order->id,
            'location_id' => $location->id,
            'payment_service_id' => 'inv_' . uniqid(),
            'payment_service_version' => 1,
            'status' => InvoiceStatus::DRAFT,
        ]);

        // Add payment request but no accepted payment methods
        $invoice->paymentRequests()->create([
            'request_type' => 'BALANCE',
            'due_date' => now()->addDays(30),
        ]);

        $this->builder->buildUpdateInvoiceRequest($invoice, 1);
    }
}
