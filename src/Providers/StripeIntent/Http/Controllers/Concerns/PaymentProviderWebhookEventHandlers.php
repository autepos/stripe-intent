<?php

namespace Autepos\AiPayment\Providers\StripeIntent\Http\Controllers\Concerns;

use Stripe\StripeObject;
use Stripe\PaymentIntent;
use Illuminate\Support\Facades\Log;

trait PaymentProviderWebhookEventHandlers
{
    /**
     * Use a stripe payment intent to get tenant id
     *
     * @return int|string|null
     */
    protected function stripePaymentIntentToTenantId(PaymentIntent $paymentIntent)
    {
        return $paymentIntent->metadata->tenant_id;
    }
    /**
     * Handle payment_intent.succeeded
     *
     * @param StripeObject $event
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function handlePaymentIntentSucceeded(StripeObject $event)
    {

        $paymentIntent = $event->data->object;

        if ($paymentIntent instanceof PaymentIntent) {

            $tenant_id = $this->stripePaymentIntentToTenantId($paymentIntent);

            $this->prepareToHandleRequest($tenant_id);

            //
            $paymentResponse = $this->paymentProvider
                ->webhookChargeByRetrieval($paymentIntent);

            if ($paymentResponse->success) {
                return $this->successMethod();
            } else {
                Log::error('Stripe webhook - received : payment_intent.succeeded - But could successfully not record the transaction :', ['paymentIntent' => $paymentIntent, 'paymentResponse' => $paymentResponse]);
            }
        } else {
            Log::error('Stripe webhook - received : payment_intent.succeeded - But payment intent could not be extracted:', ['webhook_event' => $event]);
        }

        return response('There was an issue with processing the webhook', 422);
    }
}
