<?php

namespace Autepos\AiPayment\Tests\Feature\Providers\StripeIntent;


use Stripe\StripeClient;

use Autepos\AiPayment\Tests\TestCase;
use Autepos\AiPayment\Providers\StripeIntent\StripeIntentPaymentMethod;
use Autepos\AiPayment\Providers\StripeIntent\StripeIntentPaymentProvider;

class StripeIntent_PaymentProviderUtils_Test extends TestCase
{

    use StripeIntentTestHelpers;


    private $provider = StripeIntentPaymentProvider::PROVIDER;

    public function test_can_get_raw_config()
    {
        $config = (new StripeIntentPaymentProvider)->getRawConfig();

        //
        $this->assertNotNull($config['test_secret_key']);
        $this->assertNotNull($config['test_publishable_key']);

        //
        $this->assertArrayHasKey('secret_key', $config);
        $this->assertArrayHasKey('publishable_key', $config);

        //
        $this->assertNotNull($config['webhook_secret']);
        $this->assertNotNull($config['webhook_tolerance']);
    }
    public function test_can_get_config()
    {
        $config = (new StripeIntentPaymentProvider)->getConfig();

        //
        $this->assertNotNull($config['secret_key']);
        $this->assertNotNull($config['publishable_key']);

        //
        $this->assertNotNull($config['webhook_secret']);
        $this->assertNotNull($config['webhook_tolerance']);
    }

    public function test_can_get_client()
    {
        $client = (new StripeIntentPaymentProvider)->client();
        $this->assertInstanceOf(StripeClient::class, $client);
    }

    public function test_can_get_payment_method_for_webhook()
    {
        $paymentMethod = (new StripeIntentPaymentProvider)->paymentMethodForWebhook();
        $this->assertInstanceOf(StripeIntentPaymentMethod::class, $paymentMethod);
    }
}
