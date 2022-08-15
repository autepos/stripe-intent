<?php
namespace Autepos\AiPayment\Providers\StripeIntent\Tests\Feature\Stubs;

use Stripe\PaymentMethod;
use Autepos\AiPayment\Providers\StripeIntent\Http\Controllers\StripeIntentWebhookController;

class StripeIntentWebhookControllerStub extends StripeIntentWebhookController{

    protected function stripePaymentMethodToTenantId(PaymentMethod $paymentMethod){
        return 2;
    }
    
}