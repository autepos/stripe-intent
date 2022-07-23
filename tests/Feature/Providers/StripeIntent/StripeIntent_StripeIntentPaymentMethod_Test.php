<?php

namespace Autepos\AiPayment\Tests\Feature\Providers\StripeIntent;


use Autepos\AiPayment\Tests\TestCase;
use Illuminate\Foundation\Testing\WithFaker;
use Autepos\AiPayment\PaymentMethodResponse;
use Autepos\AiPayment\Contracts\CustomerData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Autepos\AiPayment\Models\PaymentProviderCustomerPaymentMethod;
use Autepos\AiPayment\Providers\StripeIntent\StripeIntentPaymentMethod;
use Autepos\AiPayment\Providers\StripeIntent\StripeIntentPaymentProvider;

class StripeIntent_StripeIntentPaymentMethod_Test extends TestCase
{
    use RefreshDatabase;
    use StripeIntentTestHelpers;



    private $provider = StripeIntentPaymentProvider::PROVIDER;

    private function paymentMethodInstance(): StripeIntentPaymentMethod
    {
        $customerData = new CustomerData(['user_type' => 'test-user', 'user_id' => 'test-id', 'email' => 'test@test.com']);
        return (new StripeIntentPaymentProvider)->paymentMethod($customerData);
    }

    public function test_can_instantiate_payment_method()
    {
        $this->assertInstanceOf(StripeIntentPaymentMethod::class, $this->paymentMethodInstance());
    }

    public function test_can_init_payment_method()
    {

        $response = $this->paymentMethodInstance()
            ->init([]);
        $this->assertInstanceOf(PaymentMethodResponse::class, $response);
    }

    public function test_can_save_payment_method()
    {
        $user_type = 'type-is-test-class';
        $user_id = '21022022';
        $email = 'tester@autepos.com';

        // Create a payment-provider-customer that will be used under the hood
        $paymentProviderCustomer = $this->createTestPaymentProviderCustomer(
            $user_type,
            $user_id,
            'name is tester',
            $email
        );

        // Create a payment method
        $stripePaymentMethod = $this->createSuccessPaymentMethod();

        //
        $customerData = new CustomerData(['user_type' => $user_type, 'user_id' => $user_id, 'email' => $email]);

        // Save the payment method for the
        $response = $this->paymentMethodInstance()
            ->customerData($customerData) // To be transparent, instead of using the customer set already we will just set another customer.
            ->save(['payment_method_id' => $stripePaymentMethod->id]);



        // Check that we have payment method response
        $this->assertInstanceOf(PaymentMethodResponse::class, $response);
        $this->assertTrue($response->success);

        // Check that we have local payment method created
        $paymentProviderCustomerPaymentMethod = $response->getPaymentProviderCustomerPaymentMethod();
        $this->assertInstanceOf(PaymentProviderCustomerPaymentMethod::class, $paymentProviderCustomerPaymentMethod);

        $this->assertEquals($paymentProviderCustomer->id, $paymentProviderCustomerPaymentMethod->payment_provider_customer_id);
        $this->assertEquals($this->provider, $paymentProviderCustomerPaymentMethod->payment_provider);

        $this->assertNotNull($paymentProviderCustomerPaymentMethod->type);
        $this->assertEquals($stripePaymentMethod->type, $paymentProviderCustomerPaymentMethod->type);

        $this->assertEquals($stripePaymentMethod->card->country, $paymentProviderCustomerPaymentMethod->country_code);
        $this->assertEquals($stripePaymentMethod->card->brand, $paymentProviderCustomerPaymentMethod->brand);

        $this->assertNotNull($paymentProviderCustomerPaymentMethod->last_four);
        $this->assertEquals($stripePaymentMethod->card->last4, $paymentProviderCustomerPaymentMethod->last_four);

        // Check that payment method is attached to the customer at stripe
        $stripePaymentMethodFresh = $this->retrievePaymentMethod($stripePaymentMethod->id);
        $this->assertEquals($paymentProviderCustomer->payment_provider_customer_id, $stripePaymentMethodFresh->customer);
    }

    public function test_cannot_save_payment_method_when_payment_method_is_not_given()
    {
        $user_type = 'type-is-test-class';
        $user_id = '21022022';
        $email = 'tester@autepos.com';


        //
        $customerData = new CustomerData(['user_type' => $user_type, 'user_id' => $user_id, 'email' => $email]);

        // Save the payment method for the
        $response = $this->paymentMethodInstance()
            ->customerData($customerData) // To be transparent, instead of using the customer set already we will just set another customer.
            ->save([]);


        // Check that we have payment method response
        $this->assertInstanceOf(PaymentMethodResponse::class, $response);
        $this->assertFalse($response->success);
        $this->assertContains('Payment method is required', ($response->errors));
    }

    public function test_can_sync_all_payment_method()
    {
        $user_type = 'type-is-test-class';
        $user_id = '21022022';
        $email = 'tester@autepos.com';

        // Create a payment-provider-customer that will be used under the hood
        $paymentProviderCustomer = $this->createTestPaymentProviderCustomer(
            $user_type,
            $user_id,
            'name is tester',
            $email
        );

        // Create a couple of payment methods
        $stripePaymentMethod1 = $this->createSuccessPaymentMethod();
        $stripePaymentMethod2 = $this->createSuccessPaymentMethod();

        // Create a customer
        $customerData = new CustomerData(['user_type' => $user_type, 'user_id' => $user_id, 'email' => $email]);

        // Save the payment methods to the customer
        $paymentMethod = $this->paymentMethodInstance()
            ->customerData($customerData);

        $paymentMethodResponse1 = $paymentMethod->save(['payment_method_id' => $stripePaymentMethod1->id]);
        $paymentMethodResponse2 = $paymentMethod->save(['payment_method_id' => $stripePaymentMethod2->id]);

        // Remove one the payment methods locally
        $paymentProviderCustomerPaymentMethod = $paymentMethodResponse1->getPaymentProviderCustomerPaymentMethod();
        PaymentProviderCustomerPaymentMethod::withoutEvents(function () use ($paymentProviderCustomerPaymentMethod) {
            // Doing this without events, but it is not required to do it without events; but 
            // it helps to isolate this test incase some actions are hooked up to the delete 
            // event.
            $paymentProviderCustomerPaymentMethod->delete();
        });

        // Now check that customer only one payment method attached locally
        $this->assertCount(1, $paymentProviderCustomer->paymentMethods()->get());


        // Do the sync
        $result = $paymentMethod->syncAll(); // to replace deleted items
        $this->assertTrue($result);


        // Now check that customer again two payment methods attached locally
        $this->assertCount(2, $paymentProviderCustomer->paymentMethods()->get());
    }

    /**
     * If the dependent test fails there is no need to run this test: that is the only point of the dependence here.
     * @depends test_can_save_payment_method
     *
     */
    public function test_can_remove_payment_method()
    {
        $user_type = 'type-is-test-class';
        $user_id = '21022022';
        $email = 'tester@autepos.com';

        // Create a payment-provider-customer that will be used under the hood
        $paymentProviderCustomer = $this->createTestPaymentProviderCustomer(
            $user_type,
            $user_id,
            'name is tester',
            $email
        );

        // Create a payment method
        $stripePaymentMethod = $this->createSuccessPaymentMethod();

        //
        $customerData = new CustomerData(['user_type' => $user_type, 'user_id' => $user_id, 'email' => $email]);

        // Save the payment method for the
        $response = $this->paymentMethodInstance()
            ->customerData($customerData) // To be transparent, instead of using the customer set already we will just set another customer.
            ->save(['payment_method_id' => $stripePaymentMethod->id]);

        // Now that it has been saved we should try to remove it
        $paymentProviderCustomerPaymentMethod = $response->getPaymentProviderCustomerPaymentMethod();
        $response = $this->paymentMethodInstance()
            ->remove($paymentProviderCustomerPaymentMethod);

        // It should now be deleted
        $this->assertDatabaseMissing($paymentProviderCustomerPaymentMethod, ['id' => $paymentProviderCustomerPaymentMethod->id]);

        // Check that the payment method is not attached to the customer at Stripe
        $stripePaymentMethodFresh = $this->retrievePaymentMethod($stripePaymentMethod->id);
        $this->assertNull($stripePaymentMethodFresh->customer);
    }
}
