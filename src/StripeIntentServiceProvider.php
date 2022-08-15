<?php

namespace Autepos\AiPayment\Providers\StripeIntent;

use Illuminate\Support\ServiceProvider;
use Autepos\AiPayment\Contracts\PaymentProviderFactory;
use Autepos\AiPayment\Providers\StripeIntent\StripeIntentPaymentProvider;


class StripeIntentServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        // NOTE - Config: To avoid having another config file, let the config be provided 
        // by the main package autepos/ai-payment.
    }

    /**
     * Boot the service provider
     *
     * @return void
     */
    public function boot()
    {
        /**
         * Register default payment providers.
         * We are choosing to register payment providers in this manner to demonstrate 
         * how a programmer can register an arbitrary payment provider.
         */
        $paymentManager = $this->app->make(PaymentProviderFactory::class);

        $paymentManager->extend(StripeIntentPaymentProvider::PROVIDER, function ($app) {
            return $app->make(StripeIntentPaymentProvider::class);
        });

        /**
         * Load routes for StripeIntent
         */
        $this->loadRoutesFrom(dirname(__DIR__) . '/routes/routes.php');
    }
}
