<?php

namespace Autepos\AiPayment\Providers\StripeIntent;

use Stripe\PaymentMethod;
use Autepos\AiPayment\PaymentMethodResponse;
use Autepos\AiPayment\Contracts\CustomerData;
use Autepos\AiPayment\Models\PaymentProviderCustomer;
use Autepos\AiPayment\Providers\Contracts\ProviderPaymentMethod;
use Autepos\AiPayment\Models\PaymentProviderCustomerPaymentMethod;
use Autepos\AiPayment\Providers\StripeIntent\Concerns\PaymentMethodWebhook;

/**
 * @property-read StripeIntentPaymentProvider $provider
 */
class StripeIntentPaymentMethod extends ProviderPaymentMethod
{
    use PaymentMethodWebhook;

    public function init(array $data): PaymentMethodResponse
    {
        $paymentMethodResponse = new PaymentMethodResponse(PaymentMethodResponse::newType('init'));

        // If we were going to use setup intent, then we would create the SetupIntent here
        // and return the client secrete and public key through the PaymentMethodResponse
        // similar to payment intent

        $paymentMethodResponse->success = true;
        return $paymentMethodResponse;
    }

    /**
     * @inheritDoc
     * @param array $data [
     *      'payment_method_id'=>(string) Id of the payment method to be saved. Required. 
     * ]  
     */
    public function save(array $data): PaymentMethodResponse
    {
        $paymentMethodResponse = new PaymentMethodResponse(PaymentMethodResponse::newType('save'));

        //
        if (!isset($data['payment_method_id'])) {
            $paymentMethodResponse->message = "Incomplete data provided";
            $paymentMethodResponse->errors = ['Payment method is required'];
            return $paymentMethodResponse;
        }

        if (!$this->customerData) {
            $paymentMethodResponse->message = "Incorrect usage";
            $paymentMethodResponse->errors = ['Customer was not specified'];
            return $paymentMethodResponse;
        }


        // Get the associated payment provider customer
        $paymentProviderCustomer = $this->provider->customer()
            ->provider($this->provider)
            ->toPaymentProviderCustomerOrCreate($this->customerData);
        if (!$paymentProviderCustomer) {
            $paymentMethodResponse->message = "There was an issue";
            $paymentMethodResponse->errors = ['Could not create or retrieve customer'];
            return $paymentMethodResponse;
        }

        // Now go to Stripe to attach the payment method to the customer
        $stripePaymentMethod = $this->provider->client()
            ->paymentMethods->attach($data['payment_method_id'], [
                'customer' => $paymentProviderCustomer->payment_provider_customer_id
            ]);


        // Now create the return val
        if (!$stripePaymentMethod) {
            $paymentMethodResponse->message = "There was an issue while saving the payment details";
            $paymentMethodResponse->errors = ['Could not save payment details'];
            return $paymentMethodResponse;
        }


        $paymentProviderCustomerPaymentMethod = $this->record(
            $stripePaymentMethod,
            $paymentProviderCustomer
        );

        //
        $paymentMethodResponse->success = true;
        return $paymentMethodResponse->paymentProviderCustomerPaymentMethod($paymentProviderCustomerPaymentMethod);
    }


    public function remove(PaymentProviderCustomerPaymentMethod $paymentProviderCustomerPaymentMethod): PaymentMethodResponse
    {
        $paymentMethodResponse = new PaymentMethodResponse(PaymentMethodResponse::newType('delete'));


        $paymentMethod = $this->provider->client()
            ->paymentMethods->detach($paymentProviderCustomerPaymentMethod->payment_provider_payment_method_id);

        //
        if (!$paymentMethod) {
            $paymentMethodResponse->message = "There was an issue while removing the payment details";
            $paymentMethodResponse->errors = ['Could not remove the payment details'];
            return $paymentMethodResponse;
        }

        //
        $paymentProviderCustomerPaymentMethod->delete();

        //
        $paymentMethodResponse->success = true;
        return $paymentMethodResponse->paymentProviderCustomerPaymentMethod(
            $paymentProviderCustomerPaymentMethod
        );
    }

    public function syncAll(array $data = []): bool
    {
        $paymentProviderCustomer = $this->paymentProviderCustomer($this->customerData);

        if (!$paymentProviderCustomer) {
            return true; // Return true because there is nothing to sync
        }
        //
        $paymentProviderCustomerPaymentMethods = $paymentProviderCustomer->paymentMethods()
            ->where('payment_provider', $this->provider->getProvider())
            ->get();

        //
        $paymentMethods = $this->provider->client()->customers->allPaymentMethods(
            $paymentProviderCustomer->payment_provider_customer_id,
            ['type' => 'card']
        );

        //
        foreach ($paymentMethods->data as $paymentMethod) {
            $this->record(
                $paymentMethod,
                $paymentProviderCustomer,
                $paymentProviderCustomerPaymentMethods->pop()
            );
        }

        // If there is any old item left, then we will remove it
        foreach ($paymentProviderCustomerPaymentMethods as $paymentProviderCustomerPaymentMethod) {
            $paymentProviderCustomerPaymentMethod->delete();
        }

        return true;
    }

    /**
     * Convert customer data to payment provider customer
     *
     */
    private function paymentProviderCustomer(CustomerData $customerData): ?PaymentProviderCustomer
    {
        return PaymentProviderCustomer::fromCustomerData($customerData, $this->provider->getProvider());
    }



    /**
     * Add the given stripe payment method to local data. If a local model is supplied
     * we will use it to store the record by overwriting it.
     */
    private function record(
        PaymentMethod $stripePaymentMethod,
        PaymentProviderCustomer $paymentProviderCustomer,
        PaymentProviderCustomerPaymentMethod $paymentProviderCustomerPaymentMethod = null
    ): PaymentProviderCustomerPaymentMethod {
        if (is_null($paymentProviderCustomerPaymentMethod)) {
            $paymentProviderCustomerPaymentMethod = new PaymentProviderCustomerPaymentMethod;
        }

        $paymentProviderCustomerPaymentMethod->payment_provider_payment_method_id = $stripePaymentMethod->id; // Absolutely ridiculous I know.
        $paymentProviderCustomerPaymentMethod->payment_provider = $this->provider->getProvider();
        $paymentProviderCustomerPaymentMethod->type = $stripePaymentMethod->type;
        $paymentProviderCustomerPaymentMethod->country_code = $stripePaymentMethod->card->country;
        $paymentProviderCustomerPaymentMethod->brand = $stripePaymentMethod->card->brand;
        $paymentProviderCustomerPaymentMethod->last_four = $stripePaymentMethod->card->last4;
        $paymentProviderCustomerPaymentMethod->expires_at_month = $stripePaymentMethod->card->exp_month;
        $paymentProviderCustomerPaymentMethod->expires_at_year = $stripePaymentMethod->card->exp_year;
        $paymentProviderCustomerPaymentMethod->livemode = $stripePaymentMethod->livemode;
        $paymentProviderCustomer->paymentMethods()
            ->save($paymentProviderCustomerPaymentMethod);

        return $paymentProviderCustomerPaymentMethod;
    }
}
