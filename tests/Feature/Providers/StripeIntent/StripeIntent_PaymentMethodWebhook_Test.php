<?php

namespace Autepos\AiPayment\Tests\Feature\Providers\StripeIntent;


use Autepos\AiPayment\Tests\TestCase;
use Illuminate\Foundation\Testing\WithFaker;
use Autepos\AiPayment\Contracts\CustomerData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Autepos\AiPayment\Models\PaymentProviderCustomerPaymentMethod;
use Autepos\AiPayment\Providers\StripeIntent\StripeIntentPaymentMethod;
use Autepos\AiPayment\Providers\StripeIntent\StripeIntentPaymentProvider;


class StripeIntent_PaymentMethodWebhook_Test extends TestCase
{
    use RefreshDatabase;
    use StripeIntentTestHelpers;

    private $provider = StripeIntentPaymentProvider::PROVIDER;

    private function paymentMethodInstance(): StripeIntentPaymentMethod
    {
        return (new StripeIntentPaymentMethod)->provider(new StripeIntentPaymentProvider);
    }


    public function test_can_update_payment_method_on_updated_webhook_event()
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

        // Save the payment method for the customer
        $response = $this->paymentMethodInstance()
            ->customerData($customerData) // 
            ->save(['payment_method_id' => $stripePaymentMethod->id]);


        // Update the payment method only in Stripe
        $expires_at_year = $stripePaymentMethod->card->exp_year + 1;
        $stripePaymentMethodUpdated = (new StripeIntentPaymentProvider)->client()
            ->paymentMethods->update($stripePaymentMethod->id, [
                'card' => [
                    'exp_year' => $expires_at_year,
                ],
            ]);

        // Send the the updated payment method to the webhook update method
        $this->paymentMethodInstance()
            ->webhookUpdatedOrAttached($stripePaymentMethodUpdated);

        // Check if the payment method was updated locally
        $this->assertDatabaseHas(
            new PaymentProviderCustomerPaymentMethod,
            [
                'payment_provider' => $paymentProviderCustomer->payment_provider,
                'payment_provider_customer_id' => $paymentProviderCustomer->id,
                'payment_provider_payment_method_id' => $stripePaymentMethod->id,
                'expires_at_year' => $expires_at_year
            ]
        );
    }


    public function test_can_update_payment_method_on_attached_webhook_event()
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


        // Attach the payment method directly to Stripe
        $stripePaymentMethodAttached = (new StripeIntentPaymentProvider)->client()
            ->paymentMethods->attach($stripePaymentMethod->id, [
                ['customer' => $paymentProviderCustomer->payment_provider_customer_id]
            ]);

        // Send the the updated payment method to the webhook update method
        $this->paymentMethodInstance()
            ->webhookUpdatedOrAttached($stripePaymentMethodAttached);

        // Check if the payment method was save locally
        $this->assertDatabaseHas(
            new PaymentProviderCustomerPaymentMethod,
            [
                'payment_provider' => $paymentProviderCustomer->payment_provider,
                'payment_provider_customer_id' => $paymentProviderCustomer->id,
                'payment_provider_payment_method_id' => $stripePaymentMethod->id,
            ]
        );
    }

    public function test_can_delete_payment_method_on_detached_webhook_event()
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

        // Save the payment method for the customer
        $response = $this->paymentMethodInstance()
            ->customerData($customerData) // 
            ->save(['payment_method_id' => $stripePaymentMethod->id]);


        // Detach the payment method only in Stripe
        $stripePaymentMethodUpdated = (new StripeIntentPaymentProvider)->client()
            ->paymentMethods->detach($stripePaymentMethod->id);

        // Send the the updated payment method to the webhook update method
        $result = $this->paymentMethodInstance()
            ->webhookDetached($stripePaymentMethodUpdated);

        $this->assertTrue($result);


        // Check if the payment method was deleted locally
        $this->assertDatabaseMissing(
            new PaymentProviderCustomerPaymentMethod,
            [
                'payment_provider' => $paymentProviderCustomer->payment_provider,
                'payment_provider_customer_id' => $paymentProviderCustomer->id,
                'payment_provider_payment_method_id' => $stripePaymentMethod->id,
            ]
        );
    }
}
