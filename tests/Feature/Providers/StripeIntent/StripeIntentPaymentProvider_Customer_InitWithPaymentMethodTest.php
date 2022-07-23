<?php

namespace Autepos\AiPayment\Tests\Feature\Providers\StripeIntent;

use Mockery;
use Autepos\AiPayment\ResponseType;
use Autepos\AiPayment\Tests\TestCase;
use Autepos\AiPayment\PaymentResponse;
use Autepos\AiPayment\Models\Transaction;
use Illuminate\Foundation\Testing\WithFaker;
use Autepos\AiPayment\Contracts\CustomerData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Autepos\AiPayment\Providers\Contracts\Orderable;
use Autepos\AiPayment\Models\PaymentProviderCustomerPaymentMethod;
use Autepos\AiPayment\Providers\StripeIntent\StripeIntentPaymentProvider;


/**
 * Test whether payment method supplied during payment initialisation is used.
 */
class StripeIntentPaymentProvider_Customer_InitWithPaymentMethodTest extends TestCase
{
    use RefreshDatabase;
    use StripeIntentTestHelpers;



    private $provider = StripeIntentPaymentProvider::PROVIDER;


      /**
     * Note that this test is only interested in checking if a selected stripe
     * payment method is used to init payment 
     *
     */
    public function test_can_use_stripe_payment_method_when_customer_init_payment()
    {
        $amount = 1000;
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

        // Create payment method
        $paymentMethod = $this->createSuccessPaymentMethod();

        // Attach the payment method to customer
        $this->providerInstance()->client()
            ->paymentMethods->attach($paymentMethod->id, [
                'customer' => $paymentProviderCustomer->payment_provider_customer_id,
            ]);
        PaymentProviderCustomerPaymentMethod::unguard();
        $paymentProviderCustomer->paymentMethods()->create([
            'payment_provider' => $this->provider,
            'payment_provider_payment_method_id' => $paymentMethod->id,
            'type' => $paymentMethod->type,
            'last_four' => $paymentMethod->card->last4,
        ]);
        PaymentProviderCustomerPaymentMethod::reguard();

        /**
         * @var \Autepos\AiPayment\Providers\Contracts\Orderable
         */
        $mockOrder = Mockery::mock(Orderable::class);
        $mockOrder->shouldReceive('getAmount')
            ->once()
            ->andReturn($amount);

        $mockOrder->shouldReceive('getKey')
            ->twice()
            ->andReturn(1);

        $mockOrder->shouldReceive('getCurrency')
            ->once()
            ->andReturn('gbp');

        $mockOrder->shouldReceive('getCustomer')
            ->twice()
            ->andReturn(
                new CustomerData(['user_type' => $user_type, 'user_id' => $user_id, 'email' => $email])
            );

        $mockOrder->shouldReceive('getDescription')
            ->once()
            ->andReturn('test_can_customer_init_payment');


        $response = $this->providerInstance()
            ->order($mockOrder)
            ->init(null, ['payment_provider_payment_method_id' => $paymentMethod->id]);

        $this->assertInstanceOf(PaymentResponse::class, $response);
        $this->assertEquals(ResponseType::TYPE_INIT, $response->getType()->getName());
        $this->assertTrue($response->success);

        $this->assertInstanceOf(Transaction::class, $response->getTransaction());

        // Retrieve the Payment Intent and check if the payment method was used
        $paymentIntent = $this->retrievePaymentIntent($response->getTransaction()->transaction_family_id);
        $this->assertNotNull($paymentIntent->payment_method);
        $this->assertEquals($paymentMethod->id, $paymentIntent->payment_method);
    }

    /**
     * Test that a saved payment method i.e PaymentProviderCustomerPaymentMethod will be 
     * attached to payment intent when provided using its **id** during init.
     *
     */
    public function test_can_use_saved_payment_method_id_when_customer_init_payment()
    {
        $amount = 1000;
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

        // Create payment method
        $paymentMethod = $this->createSuccessPaymentMethod();

        // Attach the payment method to customer
        $this->providerInstance()->client()
            ->paymentMethods->attach($paymentMethod->id, [
                'customer' => $paymentProviderCustomer->payment_provider_customer_id,
            ]);
        PaymentProviderCustomerPaymentMethod::unguard();
        $paymentProviderCustomerPaymentMethod = $paymentProviderCustomer->paymentMethods()->create([
            'payment_provider' => $this->provider,
            'payment_provider_payment_method_id' => $paymentMethod->id,
            'type' => $paymentMethod->type,
            'last_four' => $paymentMethod->card->last4,
        ]);
        PaymentProviderCustomerPaymentMethod::reguard();

        /**
         * @var \Autepos\AiPayment\Providers\Contracts\Orderable
         */
        $mockOrder = Mockery::mock(Orderable::class);
        $mockOrder->shouldReceive('getAmount')
            ->once()
            ->andReturn($amount);

        $mockOrder->shouldReceive('getKey')
            ->twice()
            ->andReturn(1);

        $mockOrder->shouldReceive('getCurrency')
            ->once()
            ->andReturn('gbp');

        $mockOrder->shouldReceive('getCustomer')
            ->twice()
            ->andReturn(
                new CustomerData(['user_type' => $user_type, 'user_id' => $user_id, 'email' => $email])
            );

        $mockOrder->shouldReceive('getDescription')
            ->once()
            ->andReturn('test_can_customer_init_payment');


        $response = $this->providerInstance()
            ->order($mockOrder)
            ->init(null, ['payment_provider_customer_payment_method_id' => $paymentProviderCustomerPaymentMethod->id]);

        $this->assertInstanceOf(PaymentResponse::class, $response);
        $this->assertEquals(ResponseType::TYPE_INIT, $response->getType()->getName());
        $this->assertTrue($response->success);

        $this->assertInstanceOf(Transaction::class, $response->getTransaction());

        // Retrieve the Payment Intent and check if the payment method was used
        $paymentIntent = $this->retrievePaymentIntent($response->getTransaction()->transaction_family_id);
        $this->assertNotNull($paymentIntent->payment_method);
        $this->assertEquals($paymentMethod->id, $paymentIntent->payment_method);
    }

    /**
     * Test that a saved payment method i.e PaymentProviderCustomerPaymentMethod will be 
     * attached to payment intent when provided using its **pid** during init.
     *
     */
    public function test_can_use_saved_payment_method_pid_when_customer_init_payment()
    {
        $amount = 1000;
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

        // Create payment method
        $paymentMethod = $this->createSuccessPaymentMethod();

        // Attach the payment method to customer
        $this->providerInstance()->client()
            ->paymentMethods->attach($paymentMethod->id, [
                'customer' => $paymentProviderCustomer->payment_provider_customer_id,
            ]);
        PaymentProviderCustomerPaymentMethod::unguard();
        $paymentProviderCustomerPaymentMethod = $paymentProviderCustomer->paymentMethods()->create([
            'payment_provider' => $this->provider,
            'payment_provider_payment_method_id' => $paymentMethod->id,
            'type' => $paymentMethod->type,
            'last_four' => $paymentMethod->card->last4,
        ]);
        PaymentProviderCustomerPaymentMethod::reguard();

        /**
         * @var \Autepos\AiPayment\Providers\Contracts\Orderable
         */
        $mockOrder = Mockery::mock(Orderable::class);
        $mockOrder->shouldReceive('getAmount')
            ->once()
            ->andReturn($amount);

        $mockOrder->shouldReceive('getKey')
            ->twice()
            ->andReturn(1);

        $mockOrder->shouldReceive('getCurrency')
            ->once()
            ->andReturn('gbp');

        $mockOrder->shouldReceive('getCustomer')
            ->twice()
            ->andReturn(
                new CustomerData(['user_type' => $user_type, 'user_id' => $user_id, 'email' => $email])
            );

        $mockOrder->shouldReceive('getDescription')
            ->once()
            ->andReturn('test_can_customer_init_payment');


        $response = $this->providerInstance()
            ->order($mockOrder)
            ->init(null, ['payment_provider_customer_payment_method_pid' => $paymentProviderCustomerPaymentMethod->pid]);

        $this->assertInstanceOf(PaymentResponse::class, $response);
        $this->assertEquals(ResponseType::TYPE_INIT, $response->getType()->getName());
        $this->assertTrue($response->success);

        $this->assertInstanceOf(Transaction::class, $response->getTransaction());

        // Retrieve the Payment Intent and check if the payment method was used
        $paymentIntent = $this->retrievePaymentIntent($response->getTransaction()->transaction_family_id);
        $this->assertNotNull($paymentIntent->payment_method);
        $this->assertEquals($paymentMethod->id, $paymentIntent->payment_method);
    }

}
