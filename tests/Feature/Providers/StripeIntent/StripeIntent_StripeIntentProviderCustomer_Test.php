<?php

namespace Autepos\AiPayment\Tests\Feature\Providers\StripeIntent;


use Autepos\AiPayment\ResponseType;
use Autepos\AiPayment\Tests\TestCase;
use Autepos\AiPayment\CustomerResponse;
use Illuminate\Foundation\Testing\WithFaker;
use Autepos\AiPayment\Contracts\CustomerData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Autepos\AiPayment\Models\PaymentProviderCustomer;
use Autepos\AiPayment\Providers\StripeIntent\StripeIntentCustomer;
use Autepos\AiPayment\Providers\StripeIntent\StripeIntentPaymentProvider;


class StripeIntent_StripeIntentCustomer_Test extends TestCase
{
    use RefreshDatabase;
    use StripeIntentTestHelpers;



    private $provider = StripeIntentPaymentProvider::PROVIDER;


    private function providerCustomerInstance(): StripeIntentCustomer
    {
        return (new StripeIntentPaymentProvider)->customer();
    }

    public function test_can_create_customer()
    {
        $customerData = new CustomerData(['user_type' => 'test-user', 'user_id' => '1', 'email' => 'test@test.com']);
        $response = $this->providerCustomerInstance()->create($customerData);


        $this->assertInstanceOf(CustomerResponse::class, $response);
        $this->assertEquals(ResponseType::TYPE_SAVE, $response->getType()->getName());
        $this->assertTrue($response->success);

        // Check that it was created in db locally
        $paymentProviderCustomer = $response->getPaymentProviderCustomer();
        $this->assertTrue($paymentProviderCustomer->exists);

        // Check that it is created in Stripe
        $stripeCustomer = (new StripeIntentPaymentProvider)->client()
            ->customers->retrieve($paymentProviderCustomer->payment_provider_customer_id);
        $this->assertEquals($paymentProviderCustomer->payment_provider_customer_id, $stripeCustomer->id);
    }


    public function test_can_delete_customer()
    {
        $paymentProviderCustomer = $this->createTestPaymentProviderCustomer();


        // Delete it
        $response = $this->providerCustomerInstance()->delete($paymentProviderCustomer);

        $this->assertInstanceOf(CustomerResponse::class, $response);
        $this->assertEquals(ResponseType::TYPE_DELETE, $response->getType()->getName());
        $this->assertTrue($response->success);

        // Check that it is removed locally
        $this->assertDatabaseMissing(new PaymentProviderCustomer(), [
            'id' => $paymentProviderCustomer->id,
        ]);


        // Check that is removed from Stripe
        $stripeCustomerDeleted = (new StripeIntentPaymentProvider)->client()
            ->customers->retrieve($paymentProviderCustomer->payment_provider_customer_id);
        $this->assertTrue($stripeCustomerDeleted->deleted);
    }
}
