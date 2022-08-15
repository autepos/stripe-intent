<?php

namespace Autepos\AiPayment\Providers\StripeIntent\Concerns;

use Stripe\Customer as StripeCustomer;
use Autepos\AiPayment\Models\PaymentProviderCustomer;

trait CustomerWebhook
{
    /**
     * Used to response to webhook for 'customer deleted'
     *
     */
    public function webhookDeleted(StripeCustomer $webhookStripCustomer): bool
    {

        if ($webhookStripCustomer->deleted !== true) {
            return false;
        }

        $paymentProviderCustomer = PaymentProviderCustomer::where('payment_provider_customer_id', $webhookStripCustomer->id)
            ->where('payment_provider', $this->provider->getProvider())->first();

        if ($paymentProviderCustomer) {
            return $this->deletePaymentProviderCustomer($paymentProviderCustomer);
        }
        return true; // Return true if we could not find it as might have already been deleted.


    }
}
