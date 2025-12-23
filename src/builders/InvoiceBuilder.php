<?php

namespace Nikolag\Square\Builders;

use Illuminate\Support\Collection;
use Nikolag\Square\Models\Invoice;
use Nikolag\Square\Models\InvoiceAcceptedPaymentMethods;
use Nikolag\Square\Models\InvoiceCustomField;
use Nikolag\Square\Models\InvoicePaymentRequest;
use Nikolag\Square\Models\InvoiceRecipient;
use Square\Models\InvoiceAcceptedPaymentMethods as SquareInvoiceAcceptedPaymentMethods;
use Square\Models\Address;
use Square\Models\CreateInvoiceRequest;
use Square\Models\InvoiceCustomField as SquareInvoiceCustomField;
use Square\Models\InvoicePaymentRequest as SquareInvoicePaymentRequest;
use Square\Models\InvoiceRecipient as SquareInvoiceRecipient;
use Square\Models\Money;
use Square\Models\PublishInvoiceRequest;
use Square\Models\UpdateInvoiceRequest;
use Square\Models\Builders\InvoiceAcceptedPaymentMethodsBuilder;
use Square\Models\Builders\AddressBuilder;
use Square\Models\Builders\CreateInvoiceRequestBuilder;
use Square\Models\Builders\InvoiceBuilder as SquareInvoiceBuilder;
use Square\Models\Builders\InvoiceCustomFieldBuilder;
use Square\Models\Builders\InvoicePaymentRequestBuilder;
use Square\Models\Builders\InvoiceRecipientBuilder as SquareInvoiceRecipientBuilder;
use Square\Models\Builders\MoneyBuilder;
use Square\Models\Builders\PublishInvoiceRequestBuilder;
use Square\Models\Builders\UpdateInvoiceRequestBuilder;

class InvoiceBuilder
{
    /**
     * Build a CreateInvoiceRequest for the Square API.
     *
     * @param  Invoice  $invoice
     * @return CreateInvoiceRequest
     */
    public function buildCreateInvoiceRequest(Invoice $invoice): CreateInvoiceRequest
    {
        $invoiceBuilder = SquareInvoiceBuilder::init()
            ->locationId($invoice->location_id)
            ->orderId($invoice->order_id);

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

        // Add delivery method
        if ($invoice->delivery_method) {
            $invoiceBuilder->deliveryMethod($invoice->delivery_method);
        }

        // Add sale or service date
        if ($invoice->sale_or_service_date) {
            $invoiceBuilder->saleOrServiceDate($invoice->sale_or_service_date->format('Y-m-d'));
        }

        // Add payment conditions
        if ($invoice->payment_conditions) {
            $invoiceBuilder->paymentConditions($invoice->payment_conditions);
        }

        // Add store payment method enabled
        if ($invoice->store_payment_method_enabled) {
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
     * @param  Invoice  $invoice
     * @param  int  $version
     * @return UpdateInvoiceRequest
     */
    public function buildUpdateInvoiceRequest(Invoice $invoice, int $version): UpdateInvoiceRequest
    {
        $invoiceBuilder = SquareInvoiceBuilder::init()
            ->version($version)
            ->locationId($invoice->location_id)
            ->orderId($invoice->order_id);

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

        // Add delivery method
        if ($invoice->delivery_method) {
            $invoiceBuilder->deliveryMethod($invoice->delivery_method);
        }

        // Add sale or service date
        if ($invoice->sale_or_service_date) {
            $invoiceBuilder->saleOrServiceDate($invoice->sale_or_service_date->format('Y-m-d'));
        }

        // Add payment conditions
        if ($invoice->payment_conditions) {
            $invoiceBuilder->paymentConditions($invoice->payment_conditions);
        }

        // Add store payment method enabled
        if ($invoice->store_payment_method_enabled) {
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
     * @param  int  $version
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
     * @param  InvoiceRecipient  $recipient
     * @return SquareInvoiceRecipient
     */
    private function buildInvoiceRecipient(InvoiceRecipient $recipient): SquareInvoiceRecipient
    {
        $builder = SquareInvoiceRecipientBuilder::init();

        if ($recipient->customer_id) {
            $builder->customerId($recipient->customer->payment_service_id);
        }

        if ($recipient->given_name) {
            $builder->givenName($recipient->given_name);
        }

        if ($recipient->family_name) {
            $builder->familyName($recipient->family_name);
        }

        if ($recipient->email_address) {
            $builder->emailAddress($recipient->email_address);
        }

        if ($recipient->phone_number) {
            $builder->phoneNumber($recipient->phone_number);
        }

        if ($recipient->company_name) {
            $builder->companyName($recipient->company_name);
        }

        // Build address if provided
        if ($recipient->address_line_1 || $recipient->locality || $recipient->postal_code) {
            $addressBuilder = AddressBuilder::init();

            if ($recipient->address_line_1) {
                $addressBuilder->addressLine1($recipient->address_line_1);
            }
            if ($recipient->address_line_2) {
                $addressBuilder->addressLine2($recipient->address_line_2);
            }
            if ($recipient->locality) {
                $addressBuilder->locality($recipient->locality);
            }
            if ($recipient->administrative_district_level_1) {
                $addressBuilder->administrativeDistrictLevel1($recipient->administrative_district_level_1);
            }
            if ($recipient->postal_code) {
                $addressBuilder->postalCode($recipient->postal_code);
            }
            if ($recipient->country) {
                $addressBuilder->country($recipient->country);
            }

            $builder->address($addressBuilder->build());
        }

        return $builder->build();
    }

    /**
     * Build an array of InvoicePaymentRequests for the Square API.
     *
     * @param  Collection  $paymentRequests
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

            if ($paymentRequest->fixed_amount_requested_money_amount) {
                $builder->fixedAmountRequestedMoney(
                    MoneyBuilder::init()
                        ->amount($paymentRequest->fixed_amount_requested_money_amount)
                        ->currency($paymentRequest->fixed_amount_requested_money_currency ?? 'USD')
                        ->build()
                );
            }

            if ($paymentRequest->percentage_requested) {
                $builder->percentageRequested($paymentRequest->percentage_requested);
            }

            return $builder->build();
        })->toArray();
    }

    /**
     * Build AcceptedPaymentMethods for the Square API.
     *
     * @param  InvoiceAcceptedPaymentMethods  $methods
     * @return SquareInvoiceAcceptedPaymentMethods
     */
    private function buildAcceptedPaymentMethods(InvoiceAcceptedPaymentMethods $methods): SquareInvoiceAcceptedPaymentMethods
    {
        $builder = InvoiceAcceptedPaymentMethodsBuilder::init();

        if ($methods->card) {
            $builder->card($methods->card);
        }

        if ($methods->square_gift_card) {
            $builder->squareGiftCard($methods->square_gift_card);
        }

        if ($methods->bank_account) {
            $builder->bankAccount($methods->bank_account);
        }

        if ($methods->buy_now_pay_later) {
            $builder->buyNowPayLater($methods->buy_now_pay_later);
        }

        if ($methods->cash_app_pay) {
            $builder->cashAppPay($methods->cash_app_pay);
        }

        return $builder->build();
    }

    /**
     * Build an array of InvoiceCustomFields for the Square API.
     *
     * @param  Collection  $customFields
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
     * @param  Invoice  $invoice
     * @param  \Square\Models\Invoice  $squareInvoice
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
}
