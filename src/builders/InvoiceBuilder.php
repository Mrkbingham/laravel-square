<?php

namespace Nikolag\Square\Builders;

use Illuminate\Support\Collection;
use Nikolag\Square\Exceptions\MissingPropertyException;
use Nikolag\Square\Models\Invoice;
use Nikolag\Square\Models\InvoiceAcceptedPaymentMethods;
use Nikolag\Square\Models\InvoiceCustomField;
use Nikolag\Square\Models\InvoicePaymentRequest;
use Nikolag\Square\Models\InvoiceRecipient;
use Square\Models\Address;
use Square\Models\Builders\CreateInvoiceRequestBuilder;
use Square\Models\Builders\InvoiceAcceptedPaymentMethodsBuilder;
use Square\Models\Builders\InvoiceBuilder as SquareInvoiceBuilder;
use Square\Models\Builders\InvoiceCustomFieldBuilder;
use Square\Models\Builders\InvoicePaymentRequestBuilder;
use Square\Models\Builders\InvoiceRecipientBuilder as SquareInvoiceRecipientBuilder;
use Square\Models\Builders\MoneyBuilder;
use Square\Models\Builders\PublishInvoiceRequestBuilder;
use Square\Models\Builders\UpdateInvoiceRequestBuilder;
use Square\Models\CreateInvoiceRequest;
use Square\Models\InvoiceAcceptedPaymentMethods as SquareInvoiceAcceptedPaymentMethods;
use Square\Models\InvoiceDeliveryMethod;
use Square\Models\InvoiceRecipient as SquareInvoiceRecipient;
use Square\Models\PublishInvoiceRequest;
use Square\Models\UpdateInvoiceRequest;

class InvoiceBuilder
{
    /**
     * Build a CreateInvoiceRequest for the Square API.
     *
     * @param Invoice $invoice
     *
     * @throws MissingPropertyException
     *
     * @return CreateInvoiceRequest
     */
    public function buildCreateInvoiceRequest(Invoice $invoice): CreateInvoiceRequest
    {
        $this->validateOrderId($invoice);
        $this->validateLocation($invoice);
        $this->validatePaymentRequests($invoice);
        $this->validateAcceptedPaymentMethods($invoice);
        $this->validateDeliveryMethod($invoice);

        $property = config('nikolag.connections.square.order.service_identifier');

        $invoiceBuilder = SquareInvoiceBuilder::init()
            ->locationId($invoice->location->square_id)
            ->orderId($invoice->order->{$property});

        // Add invoice number if provided
        if ($invoice->invoice_number) {
            $invoiceBuilder->invoiceNumber($invoice->invoice_number);
        }

        // Add title and description
        if ($invoice->title) {
            $invoiceBuilder->title($invoice->title);
        }
        if ($invoice->description) {
            $invoiceBuilder->description($invoice->description);
        }

        // Add scheduled_at
        if ($invoice->scheduled_at) {
            $invoiceBuilder->scheduledAt($invoice->scheduled_at->toRfc3339String());
        }

        // Add delivery method (required by Square API)
        $invoiceBuilder->deliveryMethod($invoice->delivery_method);

        // Add sale or service date
        if ($invoice->sale_or_service_date) {
            $invoiceBuilder->saleOrServiceDate($invoice->sale_or_service_date->format('Y-m-d'));
        }

        // Add payment conditions
        if ($invoice->payment_conditions) {
            $invoiceBuilder->paymentConditions($invoice->payment_conditions);
        }

        // Add store payment method enabled
        if ($invoice->store_payment_method_enabled !== null) {
            $invoiceBuilder->storePaymentMethodEnabled($invoice->store_payment_method_enabled);
        }

        // Add primary recipient
        if ($invoice->recipient) {
            $invoiceBuilder->primaryRecipient($this->buildInvoiceRecipient($invoice->recipient));
        }

        // Add payment requests
        if ($invoice->paymentRequests && $invoice->paymentRequests->isNotEmpty()) {
            $invoiceBuilder->paymentRequests($this->buildPaymentRequests($invoice->paymentRequests));
        }

        // Add accepted payment methods
        if ($invoice->acceptedPaymentMethods) {
            $invoiceBuilder->acceptedPaymentMethods($this->buildAcceptedPaymentMethods($invoice->acceptedPaymentMethods));
        }

        // Add custom fields
        if ($invoice->customFields && $invoice->customFields->isNotEmpty()) {
            $invoiceBuilder->customFields($this->buildCustomFields($invoice->customFields));
        }

        $squareInvoice = $invoiceBuilder->build();

        return CreateInvoiceRequestBuilder::init($squareInvoice)
            ->idempotencyKey(uniqid())
            ->build();
    }

    /**
     * Build an UpdateInvoiceRequest for the Square API.
     *
     * @param Invoice $invoice
     * @param int     $version
     *
     * @throws MissingPropertyException
     *
     * @return UpdateInvoiceRequest
     */
    public function buildUpdateInvoiceRequest(Invoice $invoice, int $version): UpdateInvoiceRequest
    {
        $this->validateOrderId($invoice);
        $this->validateLocation($invoice);
        $this->validatePaymentRequests($invoice);
        $this->validateAcceptedPaymentMethods($invoice);
        $this->validateDeliveryMethod($invoice);

        $property = config('nikolag.connections.square.order.service_identifier');

        $invoiceBuilder = SquareInvoiceBuilder::init()
            ->version($version)
            ->locationId($invoice->location->square_id)
            ->orderId($invoice->order->{$property});

        // Add invoice number if provided
        if ($invoice->invoice_number) {
            $invoiceBuilder->invoiceNumber($invoice->invoice_number);
        }

        // Add title and description
        if ($invoice->title) {
            $invoiceBuilder->title($invoice->title);
        }
        if ($invoice->description) {
            $invoiceBuilder->description($invoice->description);
        }

        // Add scheduled_at
        if ($invoice->scheduled_at) {
            $invoiceBuilder->scheduledAt($invoice->scheduled_at->toRfc3339String());
        }

        // Add delivery method (required by Square API)
        $invoiceBuilder->deliveryMethod($invoice->delivery_method);

        // Add sale or service date
        if ($invoice->sale_or_service_date) {
            $invoiceBuilder->saleOrServiceDate($invoice->sale_or_service_date->format('Y-m-d'));
        }

        // Add payment conditions
        if ($invoice->payment_conditions) {
            $invoiceBuilder->paymentConditions($invoice->payment_conditions);
        }

        // Add store payment method enabled
        if ($invoice->store_payment_method_enabled !== null) {
            $invoiceBuilder->storePaymentMethodEnabled($invoice->store_payment_method_enabled);
        }

        // Add primary recipient
        if ($invoice->recipient) {
            $invoiceBuilder->primaryRecipient($this->buildInvoiceRecipient($invoice->recipient));
        }

        // Add payment requests
        if ($invoice->paymentRequests && $invoice->paymentRequests->isNotEmpty()) {
            $invoiceBuilder->paymentRequests($this->buildPaymentRequests($invoice->paymentRequests));
        }

        // Add accepted payment methods
        if ($invoice->acceptedPaymentMethods) {
            $invoiceBuilder->acceptedPaymentMethods($this->buildAcceptedPaymentMethods($invoice->acceptedPaymentMethods));
        }

        // Add custom fields
        if ($invoice->customFields && $invoice->customFields->isNotEmpty()) {
            $invoiceBuilder->customFields($this->buildCustomFields($invoice->customFields));
        }

        $squareInvoice = $invoiceBuilder->build();

        return UpdateInvoiceRequestBuilder::init($squareInvoice)
            ->idempotencyKey(uniqid())
            ->build();
    }

    /**
     * Build a PublishInvoiceRequest for the Square API.
     *
     * @param int $version
     *
     * @return PublishInvoiceRequest
     */
    public function buildPublishInvoiceRequest(int $version): PublishInvoiceRequest
    {
        return PublishInvoiceRequestBuilder::init($version)
            ->idempotencyKey(uniqid())
            ->build();
    }

    /**
     * Build an InvoiceRecipient for the Square API.
     *
     * The following fields are derived (via customer_id) and CANNOT be set directly on the recipient:
     *   given_name
     *   family_name
     *   email_address
     *   phone_number
     *   address
     *   company_name
     *
     * @param InvoiceRecipient $recipient
     *
     * @return SquareInvoiceRecipient
     */
    private function buildInvoiceRecipient(InvoiceRecipient $recipient): SquareInvoiceRecipient
    {
        $builder = SquareInvoiceRecipientBuilder::init();

        if ($recipient->customer_id) {
            if (!$recipient->customer || empty($recipient->customer->payment_service_id)) {
                throw new MissingPropertyException(
                    'Recipient customer is missing or does not have a payment_service_id required by Square.'
                );
            }
            $builder->customerId($recipient->customer->payment_service_id);
        }

        return $builder->build();
    }

    /**
     * Build an array of InvoicePaymentRequests for the Square API.
     *
     * @param Collection $paymentRequests
     *
     * @return array
     */
    private function buildPaymentRequests(Collection $paymentRequests): array
    {
        return $paymentRequests->map(function (InvoicePaymentRequest $paymentRequest) {
            $builder = InvoicePaymentRequestBuilder::init();

            if ($paymentRequest->square_uid) {
                $builder->uid($paymentRequest->square_uid);
            }

            if ($paymentRequest->request_type) {
                $builder->requestType($paymentRequest->request_type);
            }

            if ($paymentRequest->due_date) {
                $builder->dueDate($paymentRequest->due_date->format('Y-m-d'));
            }

            if ($paymentRequest->tipping_enabled !== null) {
                $builder->tippingEnabled($paymentRequest->tipping_enabled);
            }

            if ($paymentRequest->automatic_payment_source) {
                $builder->automaticPaymentSource($paymentRequest->automatic_payment_source);
            }

            if ($paymentRequest->fixed_amount_requested_money_amount !== null) {
                $builder->fixedAmountRequestedMoney(
                    MoneyBuilder::init()
                        ->amount($paymentRequest->fixed_amount_requested_money_amount)
                        ->currency($paymentRequest->fixed_amount_requested_money_currency ?? 'USD')
                        ->build()
                );
            }

            if ($paymentRequest->percentage_requested !== null) {
                $builder->percentageRequested($paymentRequest->percentage_requested);
            }

            return $builder->build();
        })->toArray();
    }

    /**
     * Build AcceptedPaymentMethods for the Square API.
     *
     * @param InvoiceAcceptedPaymentMethods $methods
     *
     * @return SquareInvoiceAcceptedPaymentMethods
     */
    private function buildAcceptedPaymentMethods(InvoiceAcceptedPaymentMethods $methods): SquareInvoiceAcceptedPaymentMethods
    {
        $builder = InvoiceAcceptedPaymentMethodsBuilder::init();

        if ($methods->card !== null) {
            $builder->card($methods->card);
        }

        if ($methods->square_gift_card !== null) {
            $builder->squareGiftCard($methods->square_gift_card);
        }

        if ($methods->bank_account !== null) {
            $builder->bankAccount($methods->bank_account);
        }

        if ($methods->buy_now_pay_later !== null) {
            $builder->buyNowPayLater($methods->buy_now_pay_later);
        }

        if ($methods->cash_app_pay !== null) {
            $builder->cashAppPay($methods->cash_app_pay);
        }

        return $builder->build();
    }

    /**
     * Build an array of InvoiceCustomFields for the Square API.
     *
     * @param Collection $customFields
     *
     * @return array
     */
    private function buildCustomFields(Collection $customFields): array
    {
        return $customFields->map(function (InvoiceCustomField $customField) {
            $builder = InvoiceCustomFieldBuilder::init();

            if ($customField->label) {
                $builder->label($customField->label);
            }

            if ($customField->value) {
                $builder->value($customField->value);
            }

            if ($customField->placement) {
                $builder->placement($customField->placement);
            }

            return $builder->build();
        })->toArray();
    }

    /**
     * Sync data from a Square Invoice response to the local Invoice model.
     *
     * @param Invoice                $invoice
     * @param \Square\Models\Invoice $squareInvoice
     *
     * @return void
     */
    public function syncFromSquareResponse(Invoice $invoice, \Square\Models\Invoice $squareInvoice): void
    {
        // Update local invoice with Square response data
        $invoice->payment_service_id = $squareInvoice->getId();
        $invoice->payment_service_version = $squareInvoice->getVersion();
        $invoice->status = $squareInvoice->getStatus();
        $invoice->public_url = $squareInvoice->getPublicUrl();
        $invoice->invoice_number = $squareInvoice->getInvoiceNumber();

        // Update next payment amount if available
        $nextPaymentMoney = $squareInvoice->getNextPaymentAmountMoney();
        if ($nextPaymentMoney) {
            $invoice->next_payment_amount_money_amount = $nextPaymentMoney->getAmount();
            $invoice->next_payment_amount_money_currency = $nextPaymentMoney->getCurrency();
        }

        $invoice->save();
    }

    /**
     * Validate that the invoice has an associated order with a Square order ID.
     *
     * @param Invoice $invoice
     *
     * @throws MissingPropertyException
     *
     * @return void
     */
    private function validateOrderId(Invoice $invoice): void
    {
        $property = config('nikolag.connections.square.order.service_identifier');

        if (!$invoice->order) {
            throw new MissingPropertyException(
                'Cannot create invoice without an associated order. The invoice must have an order relationship defined.'
            );
        }

        if (empty($invoice->order->{$property})) {
            throw new MissingPropertyException(
                "Cannot create invoice without a Square order ID. The order must be saved to Square first (order.{$property} is required)."
            );
        }
    }

    /**
     * Validate that the invoice has an associated location with a Square ID.
     *
     * @param Invoice $invoice
     *
     * @throws MissingPropertyException
     *
     * @return void
     */
    private function validateLocation(Invoice $invoice): void
    {
        if (!$invoice->location) {
            throw new MissingPropertyException(
                'Cannot create invoice without an associated location. The invoice must have a location relationship defined.'
            );
        }

        if (empty($invoice->location->square_id)) {
            throw new MissingPropertyException(
                'Cannot create invoice without a Square location ID. The location must have a square_id set.'
            );
        }
    }

    /**
     * Validate that the invoice has at least one payment request.
     *
     * Square API requires every invoice to have at least one payment request
     * with a due_date and request_type specified.
     *
     * @param Invoice $invoice
     *
     * @throws MissingPropertyException
     *
     * @return void
     */
    private function validatePaymentRequests(Invoice $invoice): void
    {
        // Check if payment requests relationship is loaded
        if (!$invoice->relationLoaded('paymentRequests')) {
            $invoice->load('paymentRequests');
        }

        // Check if invoice has at least one payment request
        if (!$invoice->paymentRequests || $invoice->paymentRequests->isEmpty()) {
            throw new MissingPropertyException(
                'Cannot create invoice without at least one payment request. Square requires all invoices to have payment request(s) with a due_date and request_type. '.
                'Add a payment request using $invoice->paymentRequests()->create([...]).'
            );
        }

        // Validate each payment request has required fields
        foreach ($invoice->paymentRequests as $paymentRequest) {
            if (empty($paymentRequest->request_type)) {
                throw new MissingPropertyException(
                    'Payment request is missing required field: request_type. Valid values are BALANCE, DEPOSIT, or INSTALLMENT.'
                );
            }

            if (empty($paymentRequest->due_date)) {
                throw new MissingPropertyException(
                    'Payment request is missing required field: due_date. Each payment request must have a due date.'
                );
            }
        }
    }

    /**
     * Validate that the invoice has accepted payment methods defined.
     *
     * Square API requires every invoice to specify which payment methods are accepted.
     *
     * @param Invoice $invoice
     *
     * @throws MissingPropertyException
     *
     * @return void
     */
    private function validateAcceptedPaymentMethods(Invoice $invoice): void
    {
        // Check if accepted payment methods relationship is loaded
        if (!$invoice->relationLoaded('acceptedPaymentMethods')) {
            $invoice->load('acceptedPaymentMethods');
        }

        // Check if invoice has accepted payment methods defined
        if (!$invoice->acceptedPaymentMethods) {
            throw new MissingPropertyException(
                'Cannot create invoice without accepted payment methods. Square requires all invoices to specify which payment methods are accepted. '.
                'Add accepted payment methods using $invoice->acceptedPaymentMethods()->create([\'card\' => true, ...]).'
            );
        }
    }

    /**
     * Validate that the invoice has a delivery method specified.
     *
     * Square API requires every invoice to specify how it will be delivered.
     *
     * @param Invoice $invoice
     *
     * @throws MissingPropertyException
     *
     * @return void
     */
    private function validateDeliveryMethod(Invoice $invoice): void
    {
        // Check if delivery method is set
        if (empty($invoice->delivery_method)) {
            throw new MissingPropertyException('Cannot create invoice without a delivery_method');
        }

        // Validate delivery method is one of the allowed values
        $validMethods = [
            InvoiceDeliveryMethod::EMAIL,
            InvoiceDeliveryMethod::SMS,
            InvoiceDeliveryMethod::SHARE_MANUALLY,
        ];

        if (!in_array($invoice->delivery_method, $validMethods)) {
            throw new MissingPropertyException(
                "Invalid delivery_method '{$invoice->delivery_method}'. ".
                'Valid values are: InvoiceDeliveryMethod::EMAIL, InvoiceDeliveryMethod::SMS, or InvoiceDeliveryMethod::SHARE_MANUALLY.'
            );
        }
    }
}
