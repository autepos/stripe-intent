<?php

namespace Autepos\AiPayment\Tests\Feature\Providers\StripeIntent;


use Autepos\AiPayment\Tests\TestCase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Autepos\AiPayment\Models\PaymentProviderCustomer;
use Autepos\AiPayment\Providers\StripeIntent\StripeIntentCustomer;
use Autepos\AiPayment\Providers\StripeIntent\StripeIntentPaymentProvider;


class StripeIntent_CustomerWebhook_Test extends TestCase
{
    use RefreshDatabase;
    use StripeIntentTestHelpers;

    private $provider = StripeIntentPaymentProvider::PROVIDER;

    /**
     * Returns an instance of the provider customer instance.
     *
     */
    private function providerCustomerInstance(): StripeIntentCustomer
    {
        return (new StripeIntentCustomer)->provider(new StripeIntentPaymentProvider);
    }

    public function test_can_remove_customer_on_deleted_webhook_event()
    {
        $user_type = 'type-is-test-class';
        $user_id = '21022022';
        $customer_email = 'tester@autepos.com';

        $paymentProviderCustomer = $this->createTestPaymentProviderCustomer(
            $user_type,
            $user_id,
            'name is tester',
            $customer_email
        );

        // Delete directly at Stripe
        $stripeCustomer = (new StripeIntentPaymentProvider)->client()
            ->customers->delete($paymentProviderCustomer->payment_provider_customer_id);

        //
        $result = $this->providerCustomerInstance()
            ->webhookDeleted($stripeCustomer);

        $this->assertTrue($result);

        // Check that it is removed locally
        $this->assertDatabaseMissing(new PaymentProviderCustomer(), [
            'id' => $paymentProviderCustomer->id,
        ]);
    }
}
