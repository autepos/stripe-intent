<?php

namespace Autepos\AiPayment\Providers\StripeIntent;

use Exception;
use Stripe\Event;
use Illuminate\Support\Facades\Log;
use Autepos\AiPayment\SimpleResponse;
use Autepos\AiPayment\PaymentResponse;
use Stripe\Exception\ApiErrorException;
use Autepos\AiPayment\Models\Transaction;
use Autepos\AiPayment\Contracts\CustomerData;
use Illuminate\Contracts\Auth\Authenticatable;
use Autepos\AiPayment\Providers\Contracts\PaymentProvider;
use Autepos\AiPayment\Providers\Contracts\ProviderCustomer;
use Autepos\AiPayment\Providers\Contracts\ProviderPaymentMethod;
use Autepos\AiPayment\Providers\StripeIntent\Concerns\PaymentProviderSync;
use Autepos\AiPayment\Providers\StripeIntent\Concerns\PaymentProviderUtils;
use Autepos\AiPayment\Providers\StripeIntent\Concerns\PaymentProviderWebhook;

class StripeIntentPaymentProvider extends PaymentProvider
{
    use PaymentProviderUtils;
    use PaymentProviderSync;
    use PaymentProviderWebhook;

    /**
     * The Provider tag
     * @var string
     */
    public const PROVIDER = 'stripe_intent';
    /**
     * The provider library version.
     *
     * @var string
     */
    const VERSION = "1.0.0-beta5";

    /**
     * The Stripe API version.
     *
     * @var string
     */
    const STRIPE_VERSION = '2020-08-27';

    /**
     * The endpoint path for webhook.
     */
    public static $webhookEndpoint = 'stripe/webhook';



    public function up(): SimpleResponse
    {
        $response = new SimpleResponse(SimpleResponse::newType('save'));




        try {

            // Delete webhook if it already exists
            $this->deleteWebhook();


            // Go ahead and create new webhook
            $endpoint = $this->client()->webhookEndpoints->create([
                'enabled_events' => [
                    Event::PAYMENT_INTENT_SUCCEEDED,
                    Event::PAYMENT_METHOD_ATTACHED,
                    Event::PAYMENT_METHOD_DETACHED,
                    Event::PAYMENT_METHOD_UPDATED,
                    Event::PAYMENT_METHOD_AUTOMATICALLY_UPDATED,
                    Event::CUSTOMER_DELETED,
                ],
                'url' => $this->webhookEndpointUrl(),
                'api_version' => static::STRIPE_VERSION,
            ]);
            $response->success = true;
            $response->message = "The Stripe webhook was created successfully. Retrieve the webhook secret in your Stripe dashboard and set it in your Stripe config";
        } catch (Exception $ex) {
            $response->message = 'An error occurred';
            $response->errors = [$ex->getMessage()];
        }
        return $response;
    }

    public function down(): SimpleResponse
    {

        $response = new SimpleResponse(SimpleResponse::newType('save'));

        try {
            $this->deleteWebhook();

            $response->success = true;
            $response->message = "Webhooks has been deleted";
        } catch (Exception $ex) {
            $response->message = 'An error occurred';
            $response->errors = [$ex->getMessage()];
        }
        return $response;
    }

    public function ping(): SimpleResponse
    {
        $SimpleResponse = new SimpleResponse(SimpleResponse::newType('ping'));

        try {
            $paymentIntents = $this->client()->paymentIntents->all(['limit' => 1]);

            if ($paymentIntents->object == 'list' and is_array($paymentIntents->data)) {
                $SimpleResponse->success = true;
                return $SimpleResponse;
            }
        } catch (\Exception $ex) { // Catch anything
            $SimpleResponse->message = $ex->getMessage();
        }

        $SimpleResponse->message = $SimpleResponse->message ?? 'A likely communication issue';
        $SimpleResponse->errors = ['There might be an issue with communicating with Stripe API with the current configurations'];
        return $SimpleResponse;
    }

    /**
     * @inheritDoc
     * @param array $data [
     *      'payment_provider_payment_method_id'=>(string) \Stripe\PaymentMethod::id which should be used to init the payment intent.
     *      'payment_provider_customer_payment_method_pid'=>(string) A pid of a user saved payment method i.e PaymentProviderCustomerPaymentMethod model (representing a \Stripe\PaymentMethod) that should be used to init the intent. It will be ignored if 'payment_provider_payment_method_id' data is provided.
     *      'payment_provider_customer_payment_method_id'=>(integer) An id of a user saved payment method i.e PaymentProviderCustomerPaymentMethod model (representing a \Stripe\PaymentMethod) that should be used to init the intent. It will be ignored if 'payment_provider_payment_method_id' or 'payment_provider_customer_payment_method_pid' data is provided.
     * ]
     */
    public function init(int $amount = null, array $data = [], Transaction $transaction = null): PaymentResponse
    {
        $response = new PaymentResponse(PaymentResponse::newType('init'));

        $order = $this->order;

        // Amount
        $amount = $amount ?? $order->getAmount();


        //
        $transaction = $this->getInitTransaction($amount, $transaction);

        // Get the payment_intent
        $payment_intent_id = $transaction->transaction_family_id;


        //
        $orderable_id = $order->getKey();
        $customer = $this->getCustomerData();
        $paymentIntent_data = [
            'amount' => $amount,
            'currency' => $transaction->currency,
            // Verify your integration in this guide by including this parameter
            'metadata' => [
                'tenant_id'=>static::getTenant(),
                'transaction_pid' => $transaction->pid,

                // We store enough info here so that we can recreate the transaction
                // from payment intent if the transaction goes missing for some reason.
                'orderable_id' => $orderable_id,
                'cashier_id' => $transaction->cashier_id,
                'user_type' => $customer->user_type,
                'user_id' => $customer->user_id,
                'orderable_amount' => $transaction->orderable_amount,
                //'orderable_detail_ids' => $transaction->orderable_detail_ids,
            ],
            'statement_descriptor' => 'Order #' . $orderable_id,
            'receipt_email' => $customer->email,
            'description' => $order->getDescription(),


        ];


        $paymentProviderCustomer = ProviderCustomer::isGuest($customer)
            ? null
            : $this->customer()->toPaymentProviderCustomerOrCreate($customer);

        if ($paymentProviderCustomer) { //if ($paymentProviderCustomer = PaymentProviderCustomer::fromCustomerData($customer, $this)) {
            $paymentIntent_data['customer'] = $paymentProviderCustomer->payment_provider_customer_id;
            $paymentIntent_data['setup_future_usage'] = 'on_session'; // 'off_session' can cause more decline according Stripe.


            // Check if Stripe payment method is sent along
            if (isset($data['payment_provider_payment_method_id'])) {
                $paymentProviderCustomerPaymentMethod = $paymentProviderCustomer->paymentMethods()
                    ->where('payment_provider_payment_method_id', $data['payment_provider_payment_method_id'])
                    ->first();

                if ($paymentProviderCustomerPaymentMethod) {
                    $paymentIntent_data['payment_method'] = $paymentProviderCustomerPaymentMethod->payment_provider_payment_method_id;
                }
            }
            // Check if a pid of a saved payment method is sent along
            elseif (isset($data['payment_provider_customer_payment_method_pid'])) {
                $paymentProviderCustomerPaymentMethod = $paymentProviderCustomer->paymentMethods()
                    ->where('pid',$data['payment_provider_customer_payment_method_pid'])->first();

                if ($paymentProviderCustomerPaymentMethod) {
                    $paymentIntent_data['payment_method'] = $paymentProviderCustomerPaymentMethod->payment_provider_payment_method_id;
                }
            }
            // Check if an id of a saved payment method is sent along
            elseif (isset($data['payment_provider_customer_payment_method_id'])) {
                $paymentProviderCustomerPaymentMethod = $paymentProviderCustomer->paymentMethods()
                    ->find($data['payment_provider_customer_payment_method_id']);

                if ($paymentProviderCustomerPaymentMethod) {
                    $paymentIntent_data['payment_method'] = $paymentProviderCustomerPaymentMethod->payment_provider_payment_method_id;
                }
            }
        }


        $paymentIntent = null;
        try {
            // Try to reuse payment intent instead of creating another
            if ($payment_intent_id) {
                try {
                    $paymentIntent = $this->client()
                        ->paymentIntents->update($payment_intent_id, $paymentIntent_data, [
                            //'idempotency_key'=>$transaction->pid,//TODO: we may need to implement idenpotency. We are updating the intent which means it won't have the same parameters as the previous request, so we should not give it idempotency_key of previous request. So a new idenpotency key is needed each time we update.
                        ]);
                } catch (\Stripe\Exception\ApiErrorException $ex) {
                    // Just swallow the error, we will create a new intent below instead
                }
            }

            if (!$paymentIntent) {
                // Proceed to make a new intent
                $paymentIntent = $this->client()
                    ->paymentIntents->create($paymentIntent_data, [
                        //'idempotency_key'=>$transaction->pid,// TODO: we may need to implement idenpotency
                    ]);
            }


            // Process the intent

            // Update the transaction
            if (($transaction->transaction_family_id != $paymentIntent->id) or $transaction->amount != $amount) {
                $transaction->transaction_family_id = $paymentIntent->id;
                $transaction->orderable_amount = $amount;
                $transaction->amount = 0;
                //$transaction->save();
            }

            if ($transaction->isDirty() or !$transaction->exists) {
                $transaction->save();
            }

            //
            $success = $paymentIntent->client_secret ? true : false;
            $response->success = $success;

            $output = [
                'publishable_key' => $this->getConfig()['publishable_key'],
                'client_secret' => $paymentIntent->client_secret,
            ];
            foreach ($output as $key => $val) {
                $response->setClientSideData($key, $val);
            }
        } catch (\Stripe\Exception\ApiErrorException $ex) {
            $response->message = "Error ocurred";
            $response->errors = ["There was an error while initialising payment. Please contact us"];
            Log::error($ex->getMessage(), ['orderable' => $this->order, 'amount' => $amount, 'data' => $data, 'transaction' => $transaction, 'customer' => $customer]);
        } catch (Exception $ex) {
            $response->message = "Error ocurred";
            $response->errors = ["A problem prevented initialising payment, please try again"];
            Log::error($ex->getMessage(), ['orderable' => $this->order, 'amount' => $amount, 'data' => $data, 'transaction' => $transaction, 'customer' => $customer]);
        }


        return $response->transaction($transaction);
    }


    /**
     * @inheritDoc
     * @param array $data [
     *      'save_payment_method'=>(int{0,1}) When 1 the payment method used to charge payment in saved if payment is successful. Default is 0.
     * ]
     */
    public function charge(Transaction $transaction, array $data = []): PaymentResponse
    {
        return $this->chargeByRetrieval($transaction, null, $data);
    }

    /**
     * @inheritDoc
     * @param array $data [
     *      'save_payment_method'=>(int{0,1}) When 1 the payment method used to charge payment in saved if payment is successful. Default is 0.
     * ]
     */
    public function cashierCharge(Authenticatable $cashier, Transaction $transaction, array $data = []): PaymentResponse
    {
        return $this->chargeByRetrieval($transaction, $cashier, $data);
    }




    public function refund(Authenticatable $cashier, Transaction $transaction, int $amount = null, string $description = null): PaymentResponse
    {

        $paymentResponse = new PaymentResponse(PaymentResponse::newType('refund'));

        

        $amount = $amount ?? $transaction->amount;

        $payment_intent_id = $transaction->transaction_family_id;

        // Make api call
        $refund = null;
        try {
            $refund = $this->client()
                ->refunds->create([
                    'amount' => $amount,
                    'payment_intent' => $payment_intent_id,
                    'reason' => $description,
                    'metadata' => [
                        'tenant_id'=>static::getTenant(),
                        'orderable_id' => $transaction->orderable_id,
                        'transaction_parent_pid' => $transaction->pid,
                        'cashier_id' => $cashier->getAuthIdentifier(),
                    ],
                ]);
        } catch (ApiErrorException $ex) {
            $paymentResponse->message = $ex->getMessage();
        } catch (Exception $ex) {
            $paymentResponse->message = $ex->getMessage();
        }



        if ($refund) {
            if ($refund->status == 'succeeded') {
                $syncPaymentResponse = $this->syncTransaction($transaction);

                if ($syncedTransaction = $syncPaymentResponse->getTransaction()) {
                    $paymentResponse->transaction($syncedTransaction);
                }

                $paymentResponse->success = true;
            } else {
                $paymentResponse->success = false;
            }
        }

        return $paymentResponse;
    }

    public function getProvider(): string
    {
        return self::PROVIDER;
    }

    public function syncTransaction(Transaction $transaction): PaymentResponse
    {


        $paymentResponse = new PaymentResponse(PaymentResponse::newType('sync'));

        $payment_intent_id = $transaction->transaction_family_id;

        if (!$payment_intent_id) {
            $paymentResponse->message = 'Missing payment intent id';
            return $paymentResponse;
        }

        //
        $paymentIntent = $this->client()
            ->paymentIntents->retrieve($payment_intent_id, []);

        if ($paymentIntent) {
            $recordedTransaction = $this->record($paymentIntent, $transaction);
            $paymentResponse->transaction($recordedTransaction);
            $paymentResponse->success = true; // In any case sync is successful since we retrieved the intent, the status of the intent is irrelevant.
        } else {
            $paymentResponse->message = 'Could not retrieve payment intent';
        }

        // Bonus operations which are not necessary to process or visualise
        // payment results.
        try {
            if ($paymentIntent) {
                // TODO: the following should either be queued or done through event listener. rather than doing them here.
                if (static::$syncRefunds) {
                    $this->syncRefunds($paymentIntent);
                }
                if (static::$syncUnsuccessfulCharges) {
                    $this->syncUnsuccessfulCharges($paymentIntent);
                }
            }
        } catch (Exception $ex) {
            // Note : We don't really expect errors but if they occur it this fine as
            // the operations are not necessary to process or visualise payment results.
        }

        //
        return $paymentResponse;
    }

    public function customer(): ?ProviderCustomer
    {
        return (new StripeIntentCustomer)
            ->provider($this);
    }

    public function paymentMethod(CustomerData $customerData): ?ProviderPaymentMethod
    {
        return (new StripeIntentPaymentMethod)
            ->provider($this)
            ->customerData($customerData);
    }
}
