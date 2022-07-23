<?php

namespace Autepos\AiPayment\Providers\StripeIntent;

use Stripe\Customer as StripeCustomer;
use Autepos\AiPayment\CustomerResponse;
use Autepos\AiPayment\Contracts\CustomerData;
use Autepos\AiPayment\Models\PaymentProviderCustomer;
use Autepos\AiPayment\Providers\Contracts\ProviderCustomer;
use Autepos\AiPayment\Providers\StripeIntent\Concerns\CustomerWebhook;
use Autepos\AiPayment\Providers\StripeIntent\StripeIntentPaymentProvider;

/**
 * @property-read StripeIntentPaymentProvider $provider
 */
class StripeIntentCustomer extends ProviderCustomer
{

    use CustomerWebhook;

    /**
     * Convert the customer data to a payment provider customer or create a new one
     * if does not exits.
     *
     * UNFORTUNATELY, THIS HAS TO BE PUBLIC FOR IT TO BE ACCESSED BY
     * StripeIntentPaymentMethod and StripeIntentPaymentProvider. OTHERWISE IT SHOULD BE PRIVATE.
     *
     * @throws \Stripe\Exception\ApiErrorException — if the request fails
     */
    public  function toPaymentProviderCustomerOrCreate(CustomerData $customerData): ?PaymentProviderCustomer
    {
        $paymentProviderCustomer = $this->paymentProviderCustomer($customerData);

        if (!$paymentProviderCustomer) {
            $stripeCustomer = $this->createStripeCustomer($customerData, $this->provider);

            if ($stripeCustomer) {
                $paymentProviderCustomer = $this->toPaymentProviderCustomer($stripeCustomer, $customerData);
            }
        }
        return $paymentProviderCustomer;
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
     * Return a payment provider customer for a given Stripe customer
     *
     */
    private  function toPaymentProviderCustomer(StripeCustomer $stripeCustomer, CustomerData $customerData): PaymentProviderCustomer
    {
        $paymentProviderCustomer = PaymentProviderCustomer::where('payment_provider_customer_id', $stripeCustomer->id)
            ->where('payment_provider', $this->provider->getProvider())->first();

        if (!$paymentProviderCustomer) {
            //
            $paymentProviderCustomer = new PaymentProviderCustomer;

            //
            $paymentProviderCustomer->payment_provider_customer_id = $stripeCustomer->id;
            $paymentProviderCustomer->payment_provider = $this->provider->getProvider();

            //
            $paymentProviderCustomer->user_type = $customerData->user_type;
            $paymentProviderCustomer->user_id = $customerData->user_id;

            //
            $paymentProviderCustomer->save();
        }

        return $paymentProviderCustomer;
    }


    /**
     * Create a new Stripe customer
     *
     * @throws \Stripe\Exception\ApiErrorException — if the request fails
     */
    private function createStripeCustomer(CustomerData $customerData): ?StripeCustomer
    {
        return $this->provider->client()
            ->customers->create([
                'metadata' => [
                    'tenant_id'=>$this->provider->getTenant(),
                    'user_type' => $customerData->user_type, // Note: no current use for this metadata
                    'user_id' => $customerData->user_id, // Note: no current use for this metadata
                ]
            ]);
    }

    /**
     * Retrieve the corresponding Stripe customer
     *
     * @throws \Stripe\Exception\ApiErrorException — if the request fails
     */
    private  function retrieve(PaymentProviderCustomer $paymentProviderCustomer): StripeCustomer
    {
        return $this->retrieveById($paymentProviderCustomer->payment_provider_customer_id);
    }

    /**
     * Retrieve a Stripe customer
     *
     * @throws \Stripe\Exception\ApiErrorException — if the request fails
     */
    private  function retrieveById(string $stripe_customer_id): ?StripeCustomer
    {
        return $this->provider->client()
            ->customers->retrieve($stripe_customer_id);
    }

    /**
     * Remove the given customer from Stripe
     *
     * @throws \Stripe\Exception\ApiErrorException — if the request fails
     */
    public function remove(PaymentProviderCustomer $paymentProviderCustomer): bool
    {
        $stripeCustomer = $this->provider->client()
            ->customers->delete($paymentProviderCustomer->payment_provider_customer_id);
        return $stripeCustomer ? true : false;
    }

    /**
     * Delete the local record of the customer
     *
     */
    public function deletePaymentProviderCustomer(PaymentProviderCustomer $paymentProviderCustomer): bool
    {
        return $paymentProviderCustomer->delete();
    }

    /**
     * @inheritDoc
     */
    public function create(CustomerData $customerData): CustomerResponse
    {
        $customerResponse = new CustomerResponse(CustomerResponse::newType('save'));
        $paymentProviderCustomer = $this->toPaymentProviderCustomerOrCreate($customerData);
        if ($paymentProviderCustomer) {
            $customerResponse->paymentProviderCustomer($paymentProviderCustomer);
            $customerResponse->success = true;
        }
        return $customerResponse;
    }

    /**
     * Delete the local record of the customer and remove also the record from Stripe
     *
     */
    public function delete(PaymentProviderCustomer $paymentProviderCustomer): CustomerResponse
    {
        $customerResponse = new CustomerResponse(CustomerResponse::newType('delete'));
        if (
            $this->remove($paymentProviderCustomer)
            and $this->deletePaymentProviderCustomer($paymentProviderCustomer)
        ) {
            $customerResponse->success = true;
        }

        return $customerResponse;
    }
}
