<?php

namespace Autepos\AiPayment\Providers\StripeIntent\Concerns;

use Stripe\PaymentMethod;
use Illuminate\Support\Facades\Log;
use Autepos\AiPayment\Models\PaymentProviderCustomer;
use Autepos\AiPayment\Models\PaymentProviderCustomerPaymentMethod;

trait PaymentMethodWebhook
{
    /**
     * In response to webhook event, update or create payment method.
     *
     */
    public function webhookUpdatedOrAttached(PaymentMethod $webhookPaymentMethod): bool
    {

        $paymentProviderCustomer = PaymentProviderCustomer::query()
            ->where('payment_provider', $this->provider->getProvider())
            ->where('payment_provider_customer_id', $webhookPaymentMethod->customer)
            ->first();


        if (!$paymentProviderCustomer) {
            return false;
        }

        $paymentProviderCustomerPaymentMethod = $paymentProviderCustomer->paymentMethods()
            ->where('payment_provider_payment_method_id', $webhookPaymentMethod->id)
            ->first();

        return !!$this->record(
            $webhookPaymentMethod,
            $paymentProviderCustomer,
            $paymentProviderCustomerPaymentMethod
        );
    }

    /**
     * In response to webhook event, delete payment method that has been detached.
     */
    public function webhookDetached(PaymentMethod $webhookPaymentMethod): bool
    {

        $paymentProviderCustomerPaymentMethods = PaymentProviderCustomerPaymentMethod::query()
            ->where('payment_provider', $this->provider->getProvider())
            ->where('payment_provider_payment_method_id', $webhookPaymentMethod->id)
            ->where('type', $webhookPaymentMethod->type)
            ->where('last_four', $webhookPaymentMethod->card->last4)
            // pedantic
            ->where('country_code', $webhookPaymentMethod->card->country)
            ->where('brand', $webhookPaymentMethod->card->brand)
            ->where('expires_at_month', $webhookPaymentMethod->card->exp_month)
            ->where('expires_at_year', $webhookPaymentMethod->card->exp_year)
            //
            ->get();


        if (!$paymentProviderCustomerPaymentMethods->count()) {
            return true; // The item does not exist so we should return true as it seem to have been deleted already.
        }

        if ($paymentProviderCustomerPaymentMethods->count() > 1) {
            $msg = 'Error while deleting payment method. Somehow there are more than one occurendances of the same payment method. The payment method must therefore manually deleted locally.';
            Log::error($msg, ['stripePaymentMethod' => $webhookPaymentMethod]);

            return true;
        }

        return $paymentProviderCustomerPaymentMethods[0]->delete();
    }
}
