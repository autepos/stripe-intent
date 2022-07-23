<?php

namespace Autepos\AiPayment\Providers\StripeIntent\Concerns;

use Exception;
use Stripe\PaymentIntent;
use Illuminate\Support\Facades\Log;
use Autepos\AiPayment\PaymentResponse;
use Autepos\AiPayment\Models\Transaction;

/**
 *
 */
trait PaymentProviderWebhook
{
    /**
     * Record a charge notified through webhook.
     *
     * @param PaymentIntent $webhookPaymentIntent
     * @return PaymentResponse
     */
    public function webhookChargeByRetrieval(PaymentIntent $webhookPaymentIntent): PaymentResponse
    {
        $paymentResponse = new PaymentResponse(PaymentResponse::newType('charge'));


        // Webhook should have been validated already but we are being pedantic
        // by going back to Stripe to re-retrieve the payment intent that we have
        // already got from webhook. We can't be too careful!
        $payment_intent_id = $webhookPaymentIntent->id;

        $paymentIntent = null;
        if ($payment_intent_id) {
            try {
                $paymentIntent = $this->client()
                    ->paymentIntents->retrieve($payment_intent_id, []);

                $paymentResponse->message = $paymentIntent->status;

                if ($paymentIntent and $paymentIntent->status == 'succeeded') {
                    $paymentResponse->success = true;
                } else {
                    $paymentResponse->success = false;
                }
            } catch (Exception $ex) {
                $paymentResponse->message = "Error ocurred in webhook processing";
                $paymentResponse->errors = ["There was an error while confirming payment through webhook"];
                Log::error(__METHOD__ . ': ' . $ex->getMessage(), ['webhookPaymentIntent' => $webhookPaymentIntent]);
            }
        }

        if (!$paymentIntent or $paymentIntent->status != 'succeeded') {
            return $paymentResponse;
        }

        //
        $transaction = Transaction::where('pid',$paymentIntent->metadata->transaction_pid)->first();
        if (!$transaction) {
            $transaction = $this->paymentIntentToTransaction($paymentIntent);
        }

        if (!$transaction) { // If we still cannot retrieve transaction then we will have no choice but to quit.
            $paymentResponse->message = 'Missing transaction';
            return $paymentResponse;
        }

        //
        $paymentResponse->transaction(
            $this->record($paymentIntent, $transaction, null, true, true)
        );

        $paymentResponse->success = true;
        $paymentResponse->message = $paymentIntent->status;
        return $paymentResponse;
    }
}
