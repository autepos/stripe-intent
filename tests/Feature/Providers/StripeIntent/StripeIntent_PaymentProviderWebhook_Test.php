<?php

namespace Autepos\AiPayment\Tests\Feature\Providers\StripeIntent;


use Autepos\AiPayment\ResponseType;
use Autepos\AiPayment\Tests\TestCase;
use Autepos\AiPayment\PaymentResponse;
use Autepos\AiPayment\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Autepos\AiPayment\Providers\StripeIntent\StripeIntentPaymentProvider;


class StripeIntent_PaymentProviderWebhook_Test extends TestCase
{
    use RefreshDatabase;
    use StripeIntentTestHelpers;



    private $provider = StripeIntentPaymentProvider::PROVIDER;


    public function test_can_charge_payment_intent_from_webhook()
    {
        $amount = 1000;
        $transaction = Transaction::factory()->create([
            'payment_provider' => $this->provider,
            'orderable_id' => 1,
            'orderable_amount' => $amount,
            'transaction_family' => Transaction::TRANSACTION_FAMILY_PAYMENT,
            'transaction_family_id' => null, //We will set this after we got an payment intent
        ]);
        $paymentIntent = $this->createTestPayment($amount, 'gbp', [
            'transaction_pid' => $transaction->pid
        ]);

        $transaction->transaction_family_id = $paymentIntent->id;

        $paymentProvider = new StripeIntentPaymentProvider;
        $response = $paymentProvider->webhookChargeByRetrieval($paymentIntent);

        $this->assertInstanceOf(PaymentResponse::class, $response);
        $this->assertEquals(ResponseType::TYPE_CHARGE, $response->getType()->getName());
        $this->assertTrue($response->success);

        $this->assertInstanceOf(Transaction::class, $response->getTransaction());
        $this->assertEquals($this->provider, $response->getTransaction()->payment_provider);
        $this->assertTrue($response->getTransaction()->success);
        $this->assertEquals($transaction->orderable_amount, $response->getTransaction()->orderable_amount);
        $this->assertEquals($transaction->orderable_amount, $response->getTransaction()->amount);
        $this->assertEquals($transaction->orderable_id, $response->getTransaction()->orderable_id);

        $this->assertDatabaseHas(new Transaction, ['id' => $response->getTransaction()->id]);
    }

    public function test_can_charge_payment_intent_from_webhook_when_transaction_is_missing()
    {
        $amount = 1000;
        $orderable_id = 'cool-order';
        $missing_transaction_id = 20000;


        $paymentIntent = $this->createTestPayment($amount, 'gbp', [
            'transaction_id' => $missing_transaction_id,
            'orderable_id' => $orderable_id,
            'orderable_amount' => $amount,
            'user_type' => 'main-user',
            'user_id' => '24022022',
        ]);



        $paymentProvider = new StripeIntentPaymentProvider;
        $response = $paymentProvider->webhookChargeByRetrieval($paymentIntent);

        $this->assertInstanceOf(PaymentResponse::class, $response);
        $this->assertEquals(ResponseType::TYPE_CHARGE, $response->getType()->getName());
        $this->assertTrue($response->success);

        $this->assertInstanceOf(Transaction::class, $response->getTransaction());
        $this->assertEquals($this->provider, $response->getTransaction()->payment_provider);
        $this->assertTrue($response->getTransaction()->success);
        $this->assertEquals($amount, $response->getTransaction()->orderable_amount);
        $this->assertEquals($amount, $response->getTransaction()->amount);
        $this->assertEquals($orderable_id, $response->getTransaction()->orderable_id);

        $this->assertDatabaseHas(new Transaction, ['id' => $response->getTransaction()->id]);
    }

    public function test_cannot_charge_unsuccessful_payment_intent_from_webhook()
    {
        $amount = 1000;
        $transaction = Transaction::factory()->create([
            'payment_provider' => $this->provider,
            'orderable_id' => 1,
            'orderable_amount' => $amount,
            'transaction_family' => Transaction::TRANSACTION_FAMILY_PAYMENT,
            'transaction_family_id' => null, //We will set this after we got an payment intent
        ]);

        //
        $paymentIntent = $this->createUnsuccessfulTestPayment($amount, 'gbp', [
            'transaction_pid' => $transaction->pid
        ]);

        //
        $transaction->transaction_family_id = $paymentIntent->id;

        //
        $paymentProvider = new StripeIntentPaymentProvider;
        $response = $paymentProvider->webhookChargeByRetrieval($paymentIntent);

        $this->assertInstanceOf(PaymentResponse::class, $response);
        $this->assertEquals(ResponseType::TYPE_CHARGE, $response->getType()->getName());
        $this->assertFalse($response->success);
    }
}
