<?php

namespace Autepos\AiPayment\Tests\Feature\Providers\StripeIntent;

use Mockery;
use Stripe\PaymentIntent;
use Illuminate\Support\Facades\Log;
use Autepos\AiPayment\Tests\TestCase;
use Autepos\AiPayment\Models\Transaction;
use Autepos\AiPayment\PaymentMethodResponse;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Autepos\AiPayment\Providers\StripeIntent\StripeIntentPaymentMethod;
use Autepos\AiPayment\Providers\StripeIntent\StripeIntentPaymentProvider;


class StripeIntentPaymentProvider_CustomerPaymentMethodTest extends TestCase
{
    use RefreshDatabase;




    private $provider = StripeIntentPaymentProvider::PROVIDER;


    public function test_can_save_payment_method_for_customer_on_successful_charge()
    {

        //
        $transaction = Transaction::factory()->create([
            'orderable_id' => 1,
            'payment_provider' => $this->provider,
            'amount' => 100,
            'user_type' => 'test_type',
            'user_id' => 'test_id',
            'transaction_family_id' => 'test_intent'
        ]);

        $partialMockProviderInstance = Mockery::mock(StripeIntentPaymentProvider::class)
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        //
        $paymentIntent = new PaymentIntent($transaction->transaction_family_id);
        $paymentIntent->status = PaymentIntent::STATUS_SUCCEEDED;
        $paymentIntent->payment_method = 'pm_test_payment_method';
        $partialMockProviderInstance->shouldReceive('retrievePaymentIntent')
            ->once()
            ->with(Mockery::type('string'))
            ->andReturn($paymentIntent);


        //
        $partialMockProviderInstance->shouldReceive('record')
            ->once();

        //
        $mockProviderPaymentMethod = Mockery::mock(StripeIntentPaymentMethod::class);
        $mockProviderPaymentMethod->shouldReceive('save')
            ->once()
            ->withArgs(function ($data) {
                return (is_array($data) and isset($data['save_payment_method']) and ($data['save_payment_method'] == 1));
            })
            ->andReturn(new PaymentMethodResponse(PaymentMethodResponse::newType('save'), true));

        //
        $partialMockProviderInstance->shouldReceive('paymentMethod')
            ->once()
            ->andReturn($mockProviderPaymentMethod);


        //
        $data['save_payment_method'] = 1;
        $response = $partialMockProviderInstance->charge($transaction, $data);
    }

    public function test_can_make_log_when_payment_method_could_not_be_saved()
    {

        //
        $transaction = Transaction::factory()->create([
            'orderable_id' => 1,
            'payment_provider' => $this->provider,
            'amount' => 100,
            'user_type' => 'test_type',
            'user_id' => 'test_id',
            'transaction_family_id' => 'test_intent'
        ]);

        $partialMockProviderInstance = Mockery::mock(StripeIntentPaymentProvider::class)
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        //
        $paymentIntent = new PaymentIntent($transaction->transaction_family_id);
        $paymentIntent->status = PaymentIntent::STATUS_SUCCEEDED;
        $paymentIntent->payment_method = 'pm_test_payment_method';
        $partialMockProviderInstance->shouldReceive('retrievePaymentIntent')
            ->once()
            ->with(Mockery::type('string'))
            ->andReturn($paymentIntent);


        //
        $partialMockProviderInstance->shouldReceive('record')
            ->once();

        // Return unsuccessful payment method response.
        $mockProviderPaymentMethod = Mockery::mock(StripeIntentPaymentMethod::class);
        $mockProviderPaymentMethod->shouldReceive('save')
            ->once()
            ->withArgs(function ($data) {
                return (is_array($data) and isset($data['save_payment_method']) and ($data['save_payment_method'] == 1));
            })
            ->andReturn(new PaymentMethodResponse(PaymentMethodResponse::newType('save'), false));

        //
        $partialMockProviderInstance->shouldReceive('paymentMethod')
            ->once()
            ->andReturn($mockProviderPaymentMethod);

        //
        Log::shouldReceive('warning')->withArgs(function ($msg) {
            return strpos($msg, 'Issue with saving ' . $this->provider . ' payment method') === 0;
        })->once();


        //
        $data['save_payment_method'] = 1;
        $response = $partialMockProviderInstance->charge($transaction, $data);
    }

    public function test_do_not_save_payment_method_for_customer_on_unsuccessful_charge()
    {

        //
        $transaction = Transaction::factory()->create([
            'orderable_id' => 1,
            'payment_provider' => $this->provider,
            'amount' => 100,
            'user_type' => 'test_type',
            'user_id' => 'test_id',
            'transaction_family_id' => 'test_intent'
        ]);


        $partialMockProviderInstance = Mockery::mock(StripeIntentPaymentProvider::class)
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        //
        $paymentIntent = new PaymentIntent('test-intent');
        $paymentIntent->status = PaymentIntent::STATUS_CANCELED;
        $partialMockProviderInstance->shouldReceive('retrievePaymentIntent')
            ->once()
            ->with(Mockery::type('string'))
            ->andReturn($paymentIntent);


        //
        $partialMockProviderInstance->shouldNotReceive('paymentMethod');


        //
        $data['save_payment_method'] = 1;
        $response = $partialMockProviderInstance->charge($transaction, $data);
    }
}
