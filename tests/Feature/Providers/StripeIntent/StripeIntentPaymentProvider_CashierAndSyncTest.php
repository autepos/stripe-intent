<?php

namespace Autepos\AiPayment\Tests\Feature\Providers\StripeIntent;

use Mockery;
use Stripe\WebhookEndpoint;
use Autepos\AiPayment\ResponseType;
use Autepos\AiPayment\SimpleResponse;
use Autepos\AiPayment\Tests\TestCase;
use Autepos\AiPayment\PaymentResponse;
use Autepos\AiPayment\Models\Transaction;
use Illuminate\Foundation\Testing\WithFaker;
use Autepos\AiPayment\Contracts\CustomerData;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Autepos\AiPayment\Providers\Contracts\Orderable;
use Autepos\AiPayment\Providers\StripeIntent\StripeIntentPaymentProvider;

class StripeIntentPaymentProvider_CashierAndSyncTest extends TestCase
{
    use RefreshDatabase;
    use StripeIntentTestHelpers;



    private $provider = StripeIntentPaymentProvider::PROVIDER;


    /**
     * We have to make the endpoint look like it is accessible from internet 
     * else Stripe won't accept it.
     *
     * @var string
     */
    private $webhookEndpointUrl = 'http://www.stripeintenttesting.com';


    public function test_can_get_provider()
    {
        $this->assertEquals($this->provider, $this->resolveProvider()->getProvider());
    }

    public function test_can_instantiate_provider()
    {
        $this->assertInstanceOf(StripeIntentPaymentProvider::class, $this->resolveProvider());
    }

    public function test_can_up(): WebhookEndpoint
    {

        $partialMockPaymentProvider = Mockery::mock(StripeIntentPaymentProvider::class)->makePartial();
        $partialMockPaymentProvider->shouldReceive('webhookEndpointUrl')
            ->atLeast()
            ->times(1)
            ->andReturn($this->webhookEndpointUrl);

        //
        $response = $partialMockPaymentProvider->up();


        $this->assertInstanceOf(SimpleResponse::class, $response);
        $this->assertEquals(ResponseType::TYPE_SAVE, $response->getType()->getName());
        $this->assertTrue($response->success);

        // Go to Stripe to check that webhook is created
        $found_webhook = false;
        $endpoint = null;
        $endpoints = $partialMockPaymentProvider->client()
            ->webhookEndpoints->all();
        foreach ($endpoints->data as $endpoint) {
            if ($endpoint->url == $this->webhookEndpointUrl) {
                $found_webhook = true;
                break;
            }
        }
        $this->assertTrue($found_webhook);

        return $endpoint;
    }

    /**
     * @depends test_can_up
     *
     * @return void
     */
    public function test_can_down(WebhookEndpoint $endpoint)
    {

        $partialMockPaymentProvider = Mockery::mock(StripeIntentPaymentProvider::class)->makePartial();
        $partialMockPaymentProvider->shouldReceive('webhookEndpointUrl')
            ->atLeast()
            ->times(1)
            ->andReturn($this->webhookEndpointUrl);

        //
        $response = $partialMockPaymentProvider->up();

        $this->assertInstanceOf(SimpleResponse::class, $response);
        $this->assertEquals(ResponseType::TYPE_SAVE, $response->getType()->getName());
        $this->assertTrue($response->success);

        // Go to Stripe to check that webhook is deleted
        $found_webhook = false;
        $endpoints = $partialMockPaymentProvider->client()
            ->webhookEndpoints->all();
        foreach ($endpoints->data as $endpoint_) {
            if ($endpoint_->id == $endpoint->id) {
                $found_webhook = true;
                break;
            }
        }
        $this->assertFalse($found_webhook);
    }

    public function test_can_ping()
    {
        $response = $this->providerInstance()->ping();

        $this->assertInstanceOf(SimpleResponse::class, $response);
        $this->assertEquals(ResponseType::TYPE_PING, $response->getType()->getName());
        $this->assertTrue($response->success);
    }

    public function test_correct_response_when_unable_to_ping()
    {
        // Provide wrong config to force the communication to fail
        $bad_config = $this->providerInstance()->getRawConfig();
        $bad_config['test_secret_key'] = '';
        $bad_config['secret_key'] = '';

        //
        $response = $this->providerInstance()
            ->config($bad_config)
            ->ping();

        $this->assertInstanceOf(SimpleResponse::class, $response);
        $this->assertEquals(ResponseType::TYPE_PING, $response->getType()->getName());
        $this->assertFalse($response->success);
        $this->assertNotNull($response->message);
        $this->assertNotEmpty($response->errors);
    }

    public function test_can_cashier_init_payment(): Transaction
    {
        $amount = 1000;

        /**
         * @var \Autepos\AiPayment\Providers\Contracts\Orderable
         */
        $mockOrder = Mockery::mock(Orderable::class);
        $mockOrder->shouldReceive('getAmount')
            ->once()
            ->andReturn($amount);

        $mockOrder->shouldReceive('getCurrency')
            ->once()
            ->andReturn('gbp');

        $mockOrder->shouldReceive('getCustomer')
            ->twice()
            ->andReturn(new CustomerData(['user_type' => 'test-user', 'user_id' => null, 'email' => 'test@test.com']));

        $mockOrder->shouldReceive('getKey')
            ->twice()
            ->andReturn(1);

        $mockOrder->shouldReceive('getDescription')
            ->once()
            ->andReturn('test_can_cashier_init_payment');

        /**
         * @var \Illuminate\Contracts\Auth\Authenticatable
         */
        $mockCashier = Mockery::mock(Authenticatable::class);
        $mockCashier->shouldReceive('getAuthIdentifier')
            ->once()
            ->andReturn(1);

        $response = $this->providerInstance()
            ->order($mockOrder)
            ->cashierInit($mockCashier, null);

        $this->assertInstanceOf(PaymentResponse::class, $response);
        $this->assertEquals(ResponseType::TYPE_INIT, $response->getType()->getName());
        $this->assertTrue($response->success);

        $this->assertInstanceOf(Transaction::class, $response->getTransaction());
        $this->assertEquals($this->provider, $response->getTransaction()->payment_provider);
        $this->assertEquals($amount, $response->getTransaction()->orderable_amount);
        $this->assertEquals(1, $response->getTransaction()->orderable_id);
        $this->assertEquals(1, $response->getTransaction()->cashier_id);
        $this->assertTrue($response->getTransaction()->exists, 'Failed asserting that transaction is stored');

        return $response->getTransaction();
    }



    public function test_can_cashier_init_split_payment()
    {
        $amount = 1000;

        /**
         * @var \Autepos\AiPayment\Providers\Contracts\Orderable
         */
        $mockOrder = Mockery::mock(Orderable::class);
        $mockOrder->shouldReceive('getKey')
            ->twice()
            ->andReturn(1);

        $mockOrder->shouldReceive('getCurrency')
            ->once()
            ->andReturn('gbp');

        $mockOrder->shouldReceive('getCustomer')
            ->twice()
            ->andReturn(new CustomerData(['user_type' => 'test-user', 'user_id' => null, 'email' => 'test@test.com']));

        $mockOrder->shouldReceive('getDescription')
            ->once()
            ->andReturn('test_can_cashier_init_payment');

        /**
         * @var \Illuminate\Contracts\Auth\Authenticatable
         */
        $mockCashier = Mockery::mock(Authenticatable::class);
        $mockCashier->shouldReceive('getAuthIdentifier')
            ->once()
            ->andReturn(1);

        $response = $this->providerInstance()
            ->order($mockOrder)
            ->cashierInit($mockCashier, $amount);

        $this->assertInstanceOf(PaymentResponse::class, $response);
        $this->assertEquals(ResponseType::TYPE_INIT, $response->getType()->getName());
        $this->assertTrue($response->success);

        $this->assertInstanceOf(Transaction::class, $response->getTransaction());
        $this->assertEquals($this->provider, $response->getTransaction()->payment_provider);
        $this->assertEquals($amount, $response->getTransaction()->orderable_amount);
        $this->assertTrue($response->getTransaction()->exists, 'Failed asserting that transaction stored');
    }



    /**
     * @depends test_can_cashier_init_payment
     */
    public function test_can_cashier_charge_payment(Transaction $transaction): Transaction
    {
        // Since this is a new test the database would have been refreshed 
        // so we need to re-add this transaction to db.
        $transaction->exists = false;
        $transaction->save();

        $paymentIntent = $this->confirmPaymentIntentTransaction($transaction);




        /**
         * @var \Illuminate\Contracts\Auth\Authenticatable
         */
        $mockCashier = Mockery::mock(Authenticatable::class);
        $mockCashier->shouldReceive('getAuthIdentifier')
            ->once() // 
            ->andReturn(1);

        $response = $this->providerInstance()
            ->cashierCharge($mockCashier, $transaction);

        $this->assertInstanceOf(PaymentResponse::class, $response);
        $this->assertEquals(ResponseType::TYPE_CHARGE, $response->getType()->getName());
        $this->assertTrue($response->success);

        $this->assertInstanceOf(Transaction::class, $response->getTransaction());
        $this->assertEquals($this->provider, $response->getTransaction()->payment_provider);
        $this->assertTrue($response->getTransaction()->success);
        $this->assertEquals($transaction->orderable_amount, $response->getTransaction()->orderable_amount);
        $this->assertEquals($transaction->orderable_amount, $response->getTransaction()->amount);
        $this->assertEquals($transaction->orderable_id, $response->getTransaction()->orderable_id);
        $this->assertEquals(1, $response->getTransaction()->cashier_id);

        $this->assertDatabaseHas(new Transaction, ['id' => $response->getTransaction()->id]);

        return $transaction;
    }

    /**
     * Transaction must have the same livemode value as offline payment provider. This
     * is to ensure that payment made in livemode=true is charged in livemode=true 
     * and vise versa
     *
     * @return void
     */
    public function test_cashier_cannot_charge_payment_on_livemode_mismatch()
    {


        /**
         * @var \Illuminate\Contracts\Auth\Authenticatable
         */
        $mockCashier = Mockery::mock(Authenticatable::class);

        $transaction = Transaction::factory()->create([
            'orderable_id' => 1,
            'payment_provider' => $this->provider,
            'amount' => 1000,
            'livemode' => false,
        ]);

        //
        $provider = $this->providerInstance();

        //
        $provider->livemode(true);
        $response = $provider->cashierCharge($mockCashier, $transaction);
        $this->assertInstanceOf(PaymentResponse::class, $response);
        $this->assertFalse($response->success);
        $this->assertStringContainsString('Livemode', implode(' .', $response->errors));

        // Try the other way round
        $transaction->livemode = true;
        $transaction->save();
        $provider->livemode(false);
        $response = $provider->cashierCharge($mockCashier, $transaction);
        $this->assertFalse($response->success);
        $this->assertStringContainsString('Livemode', implode(' .', $response->errors));
    }

    /**
     *
     * Transaction's payment provider must be the current provider
     */
    public function test_cashier_cannot_charge_payment_when_provider_mismatch()
    {

        /**
         * @var \Illuminate\Contracts\Auth\Authenticatable
         */
        $mockCashier = Mockery::mock(Authenticatable::class);

        $transaction = Transaction::factory()->create([
            'orderable_id' => 1,
            'payment_provider' => 'wrong_provider',
            'amount' => 1000,
        ]);


        $response = $this->providerInstance()
            ->cashierCharge($mockCashier, $transaction);

        $this->assertInstanceOf(PaymentResponse::class, $response);
        $this->assertFalse($response->success);
        $this->assertStringContainsString('Unauthorised', implode(' .', $response->errors));
    }


    public function test_cashier_cannot_refund_more_than_transaction_amount()
    {
        $amount = 1000;
        $too_much_refund_amount = $amount + 1;


        $parentTransaction = Transaction::factory()->create([
            'orderable_id' => 1,
            'payment_provider' => $this->provider,
            'amount' => $amount,
        ]);

        /**
         * @var \Illuminate\Contracts\Auth\Authenticatable
         */
        $mockCashier = Mockery::mock(Authenticatable::class);

        $response = $this->providerInstance()
            ->refund($mockCashier, $parentTransaction, $too_much_refund_amount, 'Overpayment');

        $this->assertInstanceOf(PaymentResponse::class, $response);
        $this->assertEquals(ResponseType::TYPE_REFUND, $response->getType()->getName());
        $this->assertFalse($response->success);
    }

    public function test_can_cashier_refund_payment(): Transaction
    {
        $amount = 1000;


        $parentTransaction = $this->createTestPaymentTransaction($amount);

        /**
         * @var \Illuminate\Contracts\Auth\Authenticatable
         */
        $mockCashier = Mockery::mock(Authenticatable::class);
        $mockCashier->shouldReceive('getAuthIdentifier')
            ->once() // 
            ->andReturn(1);

        $response = $this->providerInstance()
            ->refund($mockCashier, $parentTransaction, $amount, 'duplicate');

        //
        $this->assertInstanceOf(PaymentResponse::class, $response);
        $this->assertEquals(ResponseType::TYPE_REFUND, $response->getType()->getName());
        $this->assertTrue($response->success);

        //
        $refundTransaction = $response->getTransaction();
        $this->assertInstanceOf(Transaction::class, $refundTransaction);
        $this->assertEquals($parentTransaction->id, $refundTransaction->id);
        $this->assertEquals($this->provider, $refundTransaction->payment_provider);
        $this->assertFalse($refundTransaction->refund); //i.e it cannot be refund because it should the same as parent transaction, but updated to reflect refund.


        $this->assertDatabaseHas($refundTransaction, ['id' => $refundTransaction->id]);

        return $parentTransaction;
    }

    public function test_can_cashier_refund_part_of_payment()
    {
        $amount = 1000;
        $part_refund_amount = 500;


        $parentTransaction = $this->createTestPaymentTransaction($amount);

        /**
         * @var \Illuminate\Contracts\Auth\Authenticatable
         */
        $mockCashier = Mockery::mock(Authenticatable::class);
        $mockCashier->shouldReceive('getAuthIdentifier')
            ->once()
            ->andReturn(1);

        $response = $this->providerInstance()
            ->refund($mockCashier, $parentTransaction, $part_refund_amount, 'duplicate');

        //
        $this->assertInstanceOf(PaymentResponse::class, $response);
        $this->assertEquals(ResponseType::TYPE_REFUND, $response->getType()->getName());
        $this->assertTrue($response->success);

        //
        $refundTransaction = $response->getTransaction();
        $this->assertInstanceOf(Transaction::class, $refundTransaction);
        $this->assertEquals($parentTransaction->id, $refundTransaction->id);

        //
        $this->assertEquals(-$part_refund_amount, $refundTransaction->amount_refunded);
    }



    /**
     * Transaction must have the same livemode value as offline payment provider. This
     * is to ensure that payment made in livemode=true is charged in livemode=true 
     * and vise versa
     *
     * @return void
     */
    public function test_cashier_cannot_refund_payment_on_livemode_mismatch()
    {

        $parentTransaction = Transaction::factory()->create([
            'orderable_id' => 1,
            'payment_provider' => $this->provider,
            'amount' => 1000,
            'livemode' => false,
        ]);

        /**
         * @var \Illuminate\Contracts\Auth\Authenticatable
         */
        $mockCashier = Mockery::mock(Authenticatable::class);


        $provider = $this->providerInstance();
        $provider->livemode(true);
        $response = $provider->refund($mockCashier, $parentTransaction, 1000, 'Refund');
        $this->assertInstanceOf(PaymentResponse::class, $response);
        $this->assertFalse($response->success);
        $this->assertStringContainsString('Livemode', implode(' .', $response->errors));

        // Try the other way round
        $parentTransaction->livemode = true;
        $parentTransaction->save();
        $provider->livemode(false);
        $response = $provider->refund($mockCashier, $parentTransaction, 1000, 'Refund');
        $this->assertFalse($response->success);
        $this->assertStringContainsString('Livemode', implode(' .', $response->errors));
    }

    /**
     * Transaction's payment provider must be the current payment provider
     *
     * @return void
     */
    public function test_cashier_cannot_refund_payment_on_provider_mismatch()
    {

        $parentTransaction = Transaction::factory()->create([
            'orderable_id' => 1,
            'payment_provider' => 'wrong_provider',
            'amount' => 1000,
        ]);

        /**
         * @var \Illuminate\Contracts\Auth\Authenticatable
         */
        $mockCashier = Mockery::mock(Authenticatable::class);


        $response = $this->providerInstance()
            ->refund($mockCashier, $parentTransaction, 1000, 'Refund');
        $this->assertInstanceOf(PaymentResponse::class, $response);
        $this->assertFalse($response->success);
        $this->assertStringContainsString('Unauthorised', implode(' .', $response->errors));
    }


    /**
     * @depends test_can_cashier_charge_payment
     *
     */
    public function test_can_sync_transaction(Transaction $transaction)
    {
        // Since this is a new test the database would have been refreshed 
        // so we need to re-add this transaction to db.
        $transaction = Transaction::factory()->create($transaction->attributesToArray());
        /** OR ->tell Laravel that the model does not exists and then save it.
         * $transaction->exists=false;
         * $transaction->save();
         */


        // Just change a few fields and see if they can be replaced 
        // following synchronisation
        $original_amount = $transaction->amount;
        $original_status = $transaction->status;

        $transaction->amount = 0;
        $transaction->status = 'unknown';
        $transaction->save();

        $paymentProvider = $this->providerInstance();

        // Prevent syncing of refunds and unsuccessful charges
        $paymentProvider->setSyncRefunds(false);
        $paymentProvider->setSyncUnsuccessfulCharges(false);


        //
        $response = $paymentProvider->syncTransaction($transaction);

        //
        $this->assertInstanceOf(PaymentResponse::class, $response);
        $this->assertEquals($response->getType()->getName(), ResponseType::TYPE_SYNC);

        //
        $this->assertEquals($original_amount, $response->getTransaction()->amount);
        $this->assertEquals($original_status, $response->getTransaction()->status);
    }

    /**
     * @depends test_can_cashier_refund_payment
     * 
     */
    public function test_can_sync_refunds(Transaction $parentTransaction)
    {
        // Since this is a new test the database would have been refreshed 
        // so we need to re-add this transaction to db.
        $parentTransaction = Transaction::factory()->create($parentTransaction->attributesToArray());

        $payment_intent_id = $parentTransaction->transaction_family_id;

        // Partially mock the provider so that we can call protected method
        $partialMockPaymentProvider = Mockery::mock(StripeIntentPaymentProvider::class)->makePartial();

        $paymentIntent = $this->retrievePaymentIntent($payment_intent_id);

        $response = $partialMockPaymentProvider->syncRefunds($paymentIntent);

        //
        $this->assertTrue($response);


        // Check that the refund made using the parent transaction was synced. 
        $this->assertDatabaseHas(new Transaction, [
            'refund' => true,
            'parent_id' => $parentTransaction->id,
            'amount_refunded' => $parentTransaction->amount_refunded
        ]);
    }


    public function test_can_sync_unsuccessful_charges()
    {

        $paymentIntent = $this->createUnsuccessfulTestPayment();


        // Ensure that db is cleared so we know that any new data will be from syncing.
        Transaction::query()->delete();


        $payment_intent_id = $paymentIntent->id;

        // Partially mock the provider so that we can call protected method
        $partialMockPaymentProvider = Mockery::mock(StripeIntentPaymentProvider::class)->makePartial();

        $response = $partialMockPaymentProvider->syncUnsuccessfulCharges($paymentIntent);

        //
        $this->assertTrue($response);


        // Check that the unsuccessful transaction made using the transaction was synced. 
        $this->assertDatabaseHas(new Transaction, [
            'success' => false,
            'transaction_family_id' => $payment_intent_id,
        ]);
    }
}
