<?php

namespace Autepos\AiPayment\Tests\Feature\Providers\StripeIntent;

use Stripe\Customer;
use Stripe\PaymentIntent;
use Stripe\PaymentMethod;
use Stripe\Exception\CardException;
use Autepos\AiPayment\Models\Transaction;
use Autepos\AiPayment\Models\PaymentProviderCustomer;
use Autepos\AiPayment\Contracts\PaymentProviderFactory;
use Autepos\AiPayment\Providers\StripeIntent\StripeIntentPaymentProvider;


trait StripeIntentTestHelpers
{
    /**
     * Get the instance of the payment manager
     */
    private function paymentManager(): PaymentProviderFactory
    {
        return app(PaymentProviderFactory::class);
    }

    /**
     * Get the instance of the provider by resolving it from the 
     * container. i.e how the base app will use it
     */
    private function resolveProvider(): StripeIntentPaymentProvider
    {
        $paymentManager = $this->paymentManager();
        $paymentProvider = $paymentManager->driver($this->provider);
        return $paymentProvider;
    }

    /**
     * Get the instance of the provider directly
     */
    private function providerInstance(): StripeIntentPaymentProvider
    {
        return new StripeIntentPaymentProvider;
    }



    /**
     * Get a Stripe customer
     *
     */
    private function createCustomer(?string $name = 'test customer', ?string $email = 'test-customer@autepos.com'): Customer
    {
        return $this->providerInstance()->client()
            ->customers->create([
                'name' => $name ?? 'test customer',
                'email' => $email ?? 'test-customer@autepos.com',
            ]);
    }
    /**
     * Get a success payment method
     *
     */
    private function createSuccessPaymentMethod(): PaymentMethod
    {

        return $this->providerInstance()->client()
            ->paymentMethods->create([
                'type' => 'card',
                'card' => [
                    'number' => '4242424242424242',
                    'exp_month' => 2,
                    'exp_year' => intval(date('Y', strtotime('+1 year'))), //random_int(date('Y', strtotime('+1 year')),2072),//Stripe says any future date but are not accepting numbers greater than 2072
                    'cvc' => strval(random_int(100, 999)),
                ],
            ]);
    }

    /**
     * Get a payment method that will result in "Charge is declined with a card_declined code."
     *
     */
    private function createDeclinePaymentMethod(): PaymentMethod
    {
        return $this->providerInstance()->client()
            ->paymentMethods->create([
                'type' => 'card',
                'card' => [
                    'number' => '4000000000000002',
                    'exp_month' => 2,
                    'exp_year' => intval(date('Y', strtotime('+1 year'))),
                    'cvc' => '314',
                ],
            ]);
    }

    /**
     * Create a test payment
     *
     */
    private function createTestPayment(int $amount = 1000, $currency = 'gbp', array $metadata = []): PaymentIntent
    {
        $paymentMethod = $this->createSuccessPaymentMethod();

        $data = [
            'amount' => $amount,
            'currency' => $currency,
            'payment_method' => $paymentMethod->id,
            'confirm' => true,
        ];

        if (count($metadata)) {
            $data['metadata'] = $metadata;
        }

        $paymentIntent = $this->providerInstance()->client()
            ->paymentIntents->create($data);

        return $paymentIntent;
    }

    /**
     * Create a test payment
     *
     */
    private function createUnsuccessfulTestPayment(int $amount = 1000, $currency = 'gbp'): PaymentIntent
    {

        //First init the intent;
        $paymentIntent = $this->providerInstance()->client()
            ->paymentIntents->create([
                'amount' => $amount,
                'currency' => $currency,
                'metadata' => [
                    'orderable_id' => 'test-unsuccessful',
                ],
            ]);

        // Now make a declined payment
        $paymentMethod = $this->createDeclinePaymentMethod();
        try {
            $paymentIntent = $this->providerInstance()->client()
                ->paymentIntents->confirm($paymentIntent->id, [
                    'payment_method' => $paymentMethod->id,
                ]);
        } catch (CardException $ex) {
            // Payment is declined as expected
        }

        return $paymentIntent;
    }

    /**
     * Retrieve the given payment method
     */
    private function retrievePaymentMethod(string $payment_method_id): PaymentMethod
    {
        return $this->providerInstance()->client()
            ->paymentMethods->retrieve($payment_method_id);
    }

    /**
     * Retrieve the given payment intent
     */
    private function retrievePaymentIntent(string $payment_intent_id): PaymentIntent
    {
        return $this->providerInstance()->client()
            ->paymentIntents->retrieve($payment_intent_id);
    }

    /**
     * Create a test payment
     *
     */
    private function createTestPaymentTransaction(int $amount = 1000, $currency = 'gbp'): Transaction
    {
        $paymentIntent = $this->createTestPayment($amount, $currency);

        $transaction = Transaction::factory()->create([
            'orderable_amount' => $paymentIntent->amount,
            'amount' => ($paymentIntent->status == 'succeeded') ? $paymentIntent->amount : 0,
            'orderable_id' => 1,
            'payment_provider' => StripeIntentPaymentProvider::PROVIDER,
            'transaction_family' => Transaction::TRANSACTION_FAMILY_PAYMENT,
            'transaction_family_id' => $paymentIntent->id,
            'success' => $paymentIntent->status == 'succeeded',
            'status' => $paymentIntent->status,
        ]);

        return $transaction;
    }

    /**
     * Confirm a transaction using it underlying payment intent
     *
     */
    private function confirmPaymentIntentTransaction(Transaction $transaction): PaymentIntent
    {
        $paymentMethod = $this->createSuccessPaymentMethod();

        $paymentIntent = $this->providerInstance()->client()
            ->paymentIntents->confirm(
                $transaction->transaction_family_id,
                [
                    'payment_method' => $paymentMethod->id,
                ]
            );

        $transaction->orderable_amount = ($paymentIntent->status == 'succeeded') ? $paymentIntent->amount : null;
        $transaction->success = $paymentIntent->status == 'succeeded';
        $transaction->status = $paymentIntent->status;
        $transaction->save();

        return $paymentIntent;
    }

    /**
     * Create a failed payment transactions
     */
    private function createUnsuccessfulPaymentTransaction(int $amount = 1000, $currency = 'gbp'): Transaction
    {
        $paymentIntent = $this->createUnsuccessfulTestPayment($amount, $currency);

        $transaction = Transaction::factory()->create([
            'orderable_amount' => $paymentIntent->amount,
            'amount' => ($paymentIntent->status == 'succeeded') ? $paymentIntent->amount : 0,
            'orderable_id' => 1,
            'payment_provider' => StripeIntentPaymentProvider::PROVIDER,
            'transaction_family' => Transaction::TRANSACTION_FAMILY_PAYMENT,
            'transaction_family_id' => $paymentIntent->id,
            'success' => $paymentIntent->status == 'succeeded',
            'status' => $paymentIntent->status,
        ]);

        return $transaction;
    }

    /**
     * Create an instance of payment provider customer for Stripe
     *
     * @param string $user_type @see PaymentProviderCustomer table
     * @param string $user_id @see PaymentProviderCustomer table
     */
    private function createTestPaymentProviderCustomer(string $user_type = 'test-payment-provider-customer', string $user_id = '1', string $name = null, string $email = null): PaymentProviderCustomer
    {
        $customer = $this->createCustomer($name, $email);
        return PaymentProviderCustomer::factory()->create([
            'payment_provider' => $this->providerInstance()->getProvider(),
            'payment_provider_customer_id' => $customer->id,
            'user_type' => $user_type,
            'user_id' => $user_id,

        ]);
    }
}
