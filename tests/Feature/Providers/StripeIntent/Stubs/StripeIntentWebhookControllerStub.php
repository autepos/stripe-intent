<?php
namespace Autepos\AiPayment\Tests\Feature\Providers\StripeIntent\Stubs;

use Stripe\PaymentMethod;
use Autepos\AiPayment\Providers\StripeIntent\Http\Controllers\StripeIntentWebhookController;

class StripeIntentWebhookControllerStub extends StripeIntentWebhookController{

    protected function stripePaymentMethodToTenantId(PaymentMethod $paymentMethod){
        return 2;
    }
    
}