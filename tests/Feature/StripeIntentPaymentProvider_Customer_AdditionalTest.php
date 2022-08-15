<?php

namespace Autepos\AiPayment\Providers\StripeIntent\Tests\Feature;

use Mockery;
use Autepos\AiPayment\ResponseType;
use Autepos\AiPayment\Providers\StripeIntent\Tests\TestCase;
use Autepos\AiPayment\PaymentResponse;
use Autepos\AiPayment\Models\Transaction;
use Illuminate\Foundation\Testing\WithFaker;
use Autepos\AiPayment\Contracts\CustomerData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Autepos\AiPayment\Providers\Contracts\Orderable;
use Autepos\AiPayment\Providers\StripeIntent\StripeIntentPaymentProvider;



class StripeIntentPaymentProvider_Customer_AdditionalTest extends TestCase
{
    use RefreshDatabase;
    use StripeIntentTestHelpers;



    private $provider = StripeIntentPaymentProvider::PROVIDER;


    public function test_can_get_provider()
    {
        $this->assertEquals($this->provider, $this->resolveProvider()->getProvider());
    }

    public function test_can_instantiate_provider()
    {
        $this->assertInstanceOf(StripeIntentPaymentProvider::class, $this->resolveProvider());
    }


    
    /**
     * Note that this test is only interested in checking if the user id can be attached 
     * if the user id is given which should happen when a customer is logged in.
     *
     */
    public function test_can_associate_user_info_when_customer_init_payment(): Transaction
    {


        $amount = 1000;
        $user_type = 'type-is-test-class';
        $user_id = '21022022';
        $email = 'tester@autepos.com';


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
            ->init(null);

        $this->assertInstanceOf(PaymentResponse::class, $response);
        $this->assertEquals(ResponseType::TYPE_INIT, $response->getType()->getName());
        $this->assertTrue($response->success);

        $this->assertInstanceOf(Transaction::class, $response->getTransaction());
        $this->assertEquals($user_type, $response->getTransaction()->user_type);
        $this->assertEquals($user_id, $response->getTransaction()->user_id);

        return $response->getTransaction();
    }

    /**
     * Note that this test is only interested in checking if the stripe customer id
     * can be attached if available.
     *
     */
    public function test_can_attach_stripe_customer_id_when_customer_init_payment()
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
            ->init(null);

        $this->assertInstanceOf(PaymentResponse::class, $response);
        $this->assertEquals(ResponseType::TYPE_INIT, $response->getType()->getName());
        $this->assertTrue($response->success);

        $this->assertInstanceOf(Transaction::class, $response->getTransaction());

        // Retrieve the Payment and check if the customer was attached
        $paymentIntent = $this->retrievePaymentIntent($response->getTransaction()->transaction_family_id);
        $this->assertEquals($paymentProviderCustomer->payment_provider_customer_id, $paymentIntent->customer);
    }

    


    

    /**
     * Note that this test is only interested in checking if the user id can be attached 
     * if the user id is given which should happen when a customer is logged in.
     * 
     * @depends test_can_associate_user_info_when_customer_init_payment
     */
    public function test_can_associate_user_info_when_customer_charge_payment(Transaction $transaction)
    {
        // Since this is a new test the database would have been refreshed 
        // so we need to re-add this transaction to db.
        $transaction = Transaction::factory()->create($transaction->attributesToArray());

        // Reconfirm that the init data is fine
        $user_type = $transaction->user_type;
        $user_id = $transaction->user_id;
        $this->assertNotNull($user_id);

        // Remove the user info from the transaction to confirm that it is put back during charging.
        $transaction->user_type = 'different-user-type-' . $transaction->user_type;
        $transaction->user_id = null;
        $transaction->save();


        //
        $paymentIntent = $this->confirmPaymentIntentTransaction($transaction);


        $response = $this->providerInstance()
            ->charge($transaction);

        $this->assertInstanceOf(PaymentResponse::class, $response);
        $this->assertEquals(ResponseType::TYPE_CHARGE, $response->getType()->getName());
        $this->assertTrue($response->success);

        $this->assertInstanceOf(Transaction::class, $response->getTransaction());
        $this->assertEquals($user_type, $response->getTransaction()->user_type);
        $this->assertEquals($user_id, $response->getTransaction()->user_id);
    }
}
