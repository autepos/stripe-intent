<?php

namespace Autepos\AiPayment\Providers\StripeIntent\Concerns;

use Exception;
use Stripe\StripeClient;
use Stripe\PaymentIntent;
use Stripe\PaymentMethod;
use Stripe\WebhookSignature;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Autepos\AiPayment\PaymentResponse;
use Stripe\Exception\ApiErrorException;
use Autepos\AiPayment\Models\Transaction;
use Autepos\AiPayment\PaymentMethodResponse;
use Illuminate\Contracts\Auth\Authenticatable;
use Autepos\AiPayment\Models\PaymentProviderCustomer;
use Autepos\AiPayment\Providers\Contracts\ProviderPaymentMethod;
use Autepos\AiPayment\Providers\StripeIntent\StripeIntentPaymentMethod;

trait PaymentProviderUtils
{

    /**
     * Get raw payment  config.
     *
     */
    public function getRawConfig(): array
    {
        return count($this->config) ? $this->config : config('ai-payment.' . static::PROVIDER, []);
    }

    /**
     * Get payment  config.
     *
     */
    public function getConfig(): array
    {
        $configurations = $this->getRawConfig();

        $config['webhook_secret'] = $configurations['webhook_secret'];
        $config['webhook_tolerance'] = $configurations['webhook_tolerance'];


        if ($this->livemode === true) {
            $secret_key = $configurations['secret_key'];
            $publishable_key = $configurations['publishable_key'];
        } else {
            $secret_key = $configurations['test_secret_key'];
            $publishable_key = $configurations['test_publishable_key'];
        }
        $config['secret_key'] = $secret_key;
        $config['publishable_key'] = $publishable_key;

        return $config;
    }

    /**
     * Get the Stripe SDK client.
     *
     */
    public function client(array $data = []): StripeClient
    {
        $secret_key=$this->getConfig()['secret_key'];

        if ($secret_key and !$this->isLivemode()){
            if(strpos($secret_key, 'sk_test_')!==0) {
                throw new \InvalidArgumentException('Tests may not be run with a production Stripe key.');
            }
        }


        //
        if (count($data)) {
            return new StripeClient($data);
        }
        return new StripeClient([
            'api_key' => $secret_key,
            "stripe_version" => static::STRIPE_VERSION
        ]);
    }



    /**
     * Get the webhook url
     *
     */
    public function webhookEndpointUrl(): string
    {
        $endpoint = static::$webhookEndpoint;
        return strpos($endpoint, 'http') === 0
            ? $endpoint
            : URL::to($endpoint);
    }

    /**
     * Go to stripe and delete webhook if it exists
     * @throws \Stripe\Exception\ApiErrorException — if the request fails
     */
    private function deleteWebhook(): bool
    {
        $url = $this->webhookEndpointUrl();

        $endpoints = $this->client()->webhookEndpoints->all();
        foreach ($endpoints->data as $endpoint) {
            if ($endpoint->url == $url) {
                $this->client()->webhookEndpoints->delete($endpoint->id);
                break; // we are not expecting there to be more than one webhook, matching our endpoint.
            }
        }
        return true;
    }

    /**
     * Get payment method with minimal settings required for webbook operations.
     *
     */
    public function paymentMethodForWebhook(): ProviderPaymentMethod
    {
        return (new StripeIntentPaymentMethod)
            ->provider($this);
    }
    
    /**
     * A wrapper for \Stripe\WebhookSignature::verifyHeader() to facilitate testing.
     * @see \Stripe\WebhookSignature::verifyHeader() dor detailed description.
     * 
     * NOTE: Unfortunately this method is non-static to make testing easier.
     * 
     * @param string $payload
     * @param string $header
     * @param string $secret
     * @param int $tolerance
     * @return bool
     * @throws Exception\SignatureVerificationException — if the verification fails
     */
    public function verifyWebhookHeader($payload,$header,$secret,$tolerance = null){
        return WebhookSignature::verifyHeader(
            $payload,
            $header,
            $secret,
            $tolerance
        );
    }


    /**
     * It is claimed that a charge has been successful for an order. So we have to
     * record the charge by confirming the claim. This could be done by retrieving
     * the charge information from the provider to confirm the validity of the
     * charge claimed to have been made. We can record the charge after confirming it.
     * NOTE: We should ensure that we have not previously recorded the charge to avoid
     * duplicate.
     *
     * @param array $data [
     *      'save_payment_method'=>(int{0,1}) When 1 the payment method used to charge payment when payment is successful. Default is 0.
     * ]
     *
     */
    private function chargeByRetrieval(Transaction $transaction, Authenticatable $cashier = null, array $data = []): PaymentResponse
    {
        $paymentResponse = new PaymentResponse(PaymentResponse::newType('charge'));

        //
        if (!$this->authoriseProviderTransaction($transaction)) { //TODO: This should already been taken care of by PaymentService
            $paymentResponse->success = false;
            $paymentResponse->errors = $this->hasSameLiveModeAsTransaction($transaction)
                ? ['Unauthorised payment transaction with provider']
                : ['Livemode mismatch'];
            return $paymentResponse;
        }


        $payment_intent_id = $transaction->transaction_family_id;

        //
        $paymentIntent = null;
        if ($payment_intent_id) {
            try {
                $paymentIntent = $this->retrievePaymentIntent($payment_intent_id);


                $paymentResponse->message = $paymentIntent->status;

                if ($paymentIntent and $paymentIntent->status == 'succeeded') {
                    $paymentResponse->success = true;
                } else {
                    $paymentResponse->success = false;
                }
            } catch (\Stripe\Exception\ApiErrorException $ex) {
                $paymentResponse->message = "Error ocurred";
                $paymentResponse->errors = ["There was an error while confirming payment. Please try again"];
                Log::error($ex->getMessage(), ['cashier' => $cashier, 'data' => $data, 'transaction' => $transaction, 'customer' => $this->getCustomerData(), 'at' => __METHOD__ . ' in line ' . __LINE__]);
            } catch (Exception $ex) {
                $paymentResponse->message = "Error ocurred";
                $paymentResponse->errors = ["A problem prevented payment confirmation. Please try again"];
                Log::error($ex->getMessage(), ['cashier' => $cashier, 'data' => $data, 'transaction' => $transaction, 'customer' => $this->getCustomerData(), 'at' => __METHOD__ . ' in line ' . __LINE__]);
                throw $ex;
            }
        }

        if (!$paymentIntent or $paymentIntent->status != 'succeeded') {
            return $paymentResponse;
        }

        $chargedTransaction = $this->record($paymentIntent, $transaction, $cashier, true);

        // Check if we need to save the payment method
        if (isset($data['save_payment_method']) and intval($data['save_payment_method']) == 1 and $paymentResponse->success) {
            $this->savePaymentMethod($paymentIntent->payment_method, $data, $transaction, $cashier);
        }


        //
        return $paymentResponse->transaction($chargedTransaction);
    }




    /**
     * Record a transaction with payment intent.
     *
     */
    protected function record(PaymentIntent $paymentIntent, Transaction $transaction, Authenticatable $cashier = null, bool $retrospective = false, bool $through_webhook = false): Transaction
    {


        //
        $paymentMethod = null;
        try {
            $paymentMethod = $this->retrievePaymentMethod($paymentIntent->payment_method);
        } catch (ApiErrorException $ex) {
            Log::warning('Cannot retrieve payment method for recording purposes: ' . $ex->getMessage(), ['payment_intent' => $paymentIntent, 'at' => __METHOD__ . ' in line ' . __LINE__]);
        }


        //
        $transaction->livemode = $paymentIntent->livemode;

        //
        $transaction->currency = $paymentIntent->currency;
        $transaction->amount = $paymentIntent->amount_received; // Stripes payment intent 'amount_received' is equivalent to 'amount' on our transaction.
        $transaction->refund = false;
        $transaction->amount_escrow = $paymentIntent->amount_capturable;

        $transaction->cashier_id = optional($cashier)->getAuthIdentifier();

        // Note that even though user_type and id should have been set during payment intent
        // initialisation, we will go ahead and overwrite it here with up-to-date info
        if ($paymentIntent->customer and ($providerCustomer = PaymentProviderCustomer::fromPaymentProviderId($paymentIntent->customer, $this))) {
            $transaction->user_type = $providerCustomer->user_type;
            $transaction->user_id = $providerCustomer->user_id;
        } else {
            // TODO: Note that the first conditions is unnecessary since we can always get the user_type and user_id from metadata when they exist.
            $transaction->user_type = $paymentIntent->metadata->user_type ?? $transaction->user_type;
            $transaction->user_id = $paymentIntent->metadata->user_id ?? $transaction->user_id;
        }

        //
        $transaction->status = $paymentIntent->status;
        if ($paymentIntent->status == 'succeeded') {
            $transaction->success = true;
            $transaction->local_status = Transaction::LOCAL_STATUS_COMPLETE;

            // Stripe said that the payment intent will only contain the latest charge
            // object, so we will only need to look for one charge object. Also a payment
            // intent can only have one successful charge. So if the payment intent is
            // successful, then this latest charge on the payment intent should be the
            // successful charge. https://stripe.com/docs/payments/payment-intents/verifying-status
            $charge = $paymentIntent->charges->data[0];
            if ($charge) {
                if ($charge->status == 'succeeded') {
                    $transaction->amount_refunded = -abs($charge->amount_refunded);
                    $transaction->transaction_child_id = $charge->id;
                } else {
                    // Pedantic log: We don't expect this to happen
                    Log::alert('RARE: Payment intent succeeded but  corresponding charge did not', ['paymentIntent' => $paymentIntent, 'at' => __METHOD__ . ' in line ' . __LINE__]);
                }
            } else {
                // Pedantic log: We don't expect this to happen
                Log::alert('RARE: Payment intent succeeded but  corresponding charge is missing', ['paymentIntent' => $paymentIntent, 'at' => __METHOD__ . ' in line ' . __LINE__]);
            }
        } else {
            $transaction->success = false;
        }

        //
        if ($paymentMethod and ($paymentMethod->id == $paymentIntent->payment_method)) {
            $threedchecks = $paymentMethod->card->checks;

            $transaction->last_four = $paymentMethod ? $paymentMethod->card->last4 : null;
            $transaction->card_type = $paymentMethod->card->brand;
            $transaction->address_matched = $threedchecks->address_line1_check === 'pass';
            $transaction->cvc_matched = $threedchecks->cvc_check === 'pass';
            $transaction->threed_secure = $paymentMethod->card->three_d_secure_usage->supported ?? false;
            $transaction->postcode_matched = $threedchecks->address_postal_code_check === 'pass';
        } else {
            Log::alert('Stripe PaymentMethod does not match the referenced payment intent', ['paymentIntent' => $paymentIntent, 'paymentMethod' => $paymentMethod, 'at' => __METHOD__ . ' in line ' . __LINE__]);
        }

        //
        $transaction->retrospective = $retrospective;
        $transaction->through_webhook = $through_webhook;

        $transaction->save();
        return $transaction;
    }


    /**
     * A helper to save payment method.
     *
     * @param array $data Any data to be passed on the the save method of payment method implementation.
     */
    private function savePaymentMethod(string $payment_method_id, array $data = [], Transaction $transaction, Authenticatable $cashier = null): PaymentMethodResponse
    {
        $data['payment_method_id'] = $payment_method_id;
        try {
            $paymentMethodResponse = $this->paymentMethod($transaction->toCustomerData())->save($data);
            if ($paymentMethodResponse->success == false) {
                Log::warning(
                    'Issue with saving ' . $this->getProvider() . ' payment method. ' . $paymentMethodResponse->message,
                    ['cashier' => $cashier, 'data' => $data, 'transaction' => $transaction, 'paymentMethodResponse' => $paymentMethodResponse, 'at' => __METHOD__ . ' in line ' . __LINE__]
                );
            }
            return $paymentMethodResponse;
        } catch (\Exception $ex) {
            // Since saving payment method is a complimentary process here, we
            // do not want any error here breaking the code of the main 
            // processes. So we will swallow the error and just do a log instead.
            Log::warning(
                'Issue with saving ' . $this->getProvider() . ' payment method. ' . $ex->getMessage(),
                ['cashier' => $cashier, 'data' => $data, 'transaction' => $transaction, 'at' => __METHOD__ . ' in line ' . __LINE__]
            );
        }

        return new PaymentMethodResponse(PaymentMethodResponse::newType('save'));
    }

    /**
     * Creates a transaction from a payment intent, with an option to save the transaction to disk.
     */
    private function paymentIntentToTransaction(PaymentIntent $paymentIntent, bool $save = true): ?Transaction
    {
        // Transaction requires an orderable id
        $orderable_id = $paymentIntent->metadata->orderable_id;
        if (!$orderable_id) {
            return null;
        }

        //
        $transaction = new Transaction();
        $transaction->transaction_family = Transaction::TRANSACTION_FAMILY_PAYMENT;
        $transaction->transaction_family_id = $paymentIntent->id;
        if ($paymentIntent->charges->data[0]) {
            $transaction->transaction_child_id = $paymentIntent->charges->data[0]->id;
        }
        $transaction->payment_provider = $this->getProvider();
        $transaction->orderable_id = $orderable_id;
        $transaction->amount = $paymentIntent->amount;
        $transaction->amount_refunded = $paymentIntent->amount_refunded ?? 0;
        $transaction->currency = $paymentIntent->currency;


        //
        $transaction->user_type = $paymentIntent->metadata->user_type;
        $transaction->user_id = $paymentIntent->metadata->user_id;
        $transaction->cashier_id = $paymentIntent->metadata->cashier_id;


        //
        $transaction->orderable_amount = $paymentIntent->metadata->orderable_amount ?? 0;


        //
        $transaction->livemode = $paymentIntent->livemode;

        // Note we stop short of setting the status and success on the transaction
        // since those should be done through dedicated procedures.

        if ($save) {
            $transaction->save();
        }

        return $transaction;
    }


    /**
     * A helper to retrieve Stripe payment intent
     * @throws \Stripe\Exception\ApiErrorException — if the request fails
     */
    protected function retrievePaymentIntent(string $payment_intent_id): PaymentIntent
    {
        return  $this->client()
            ->paymentIntents->retrieve($payment_intent_id, []);
    }

    /**
     * A helper to retrieve Stripe payment method
     * @throws \Stripe\Exception\ApiErrorException — if the request fails
     */
    protected function retrievePaymentMethod(string $payment_method_id): PaymentMethod
    {
        return  $this->client()
            ->paymentMethods->retrieve($payment_method_id);
    }
}
