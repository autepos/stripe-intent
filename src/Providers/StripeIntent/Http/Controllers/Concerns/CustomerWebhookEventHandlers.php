<?php

namespace Autepos\AiPayment\Providers\StripeIntent\Http\Controllers\Concerns;

use Stripe\Customer;
use Stripe\StripeObject;
use Illuminate\Support\Facades\Log;


trait CustomerWebhookEventHandlers
{

    /**
     * Get the tenant id for the given Stripe customer
     *
     * @return string
     */
    protected function stripeCustomerToTenantId(Customer $customer)
    {
        return $customer->metadata->tenant_id;
    }

    /**
     * Handle customer.deleted
     *
     * @param StripeObject $event
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function handleCustomerDeleted(StripeObject $event)
    {

        $customer = $event->data->object;

        if ($customer instanceof Customer) {
            //
            $tenant_id = $this->stripeCustomerToTenantId($customer);

            $this->prepareToHandleRequest($tenant_id);
            //
            $result = $this->paymentProvider->customer()
                ->webhookDeleted($customer);

            if ($result) {
                return $this->successMethod();
            }
        }

        Log::error('Stripe webhook - received : ' . __METHOD__ . ' - But it could not be handled correctly:', ['webhook_event' => $event]);
        return response('There was an issue with processing the webhook', 422);
    }
}
