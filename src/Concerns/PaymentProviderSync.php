<?php

namespace Autepos\AiPayment\Providers\StripeIntent\Concerns;


use Stripe\PaymentIntent;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
use Autepos\AiPayment\Models\Transaction;
use Autepos\AiPayment\Models\PaymentProviderCustomer;

trait PaymentProviderSync
{
    /**
     * Determines if refunds should be downloaded
     */
    private static $syncRefunds = true;

    /**
     * Determines if unsuccessful changes should be downloaded
     */
    private static $syncUnsuccessfulCharges = false;


    /**
     * Download and synchronise the local data with refunds held by Stripe that relate
     * to the given intent. Note that the transactions resulting from the refunds are
     * set to display_only for this stripe_intent provider implementation.
     * 
     */
    protected function syncRefunds(PaymentIntent $paymentIntent): bool
    {

        $payment_intent_id = $paymentIntent->id;

        $refunds = null;
        try {
            // Fetch all refunds at once
            $refunds = $this->client()
                ->refunds->all(['payment_intent' => $payment_intent_id]);
        } catch (ApiErrorException $ex) {
            Log::error('Updating unsuccessful charges:' . $ex->getMessage(), ['paymentIntent' => $paymentIntent]);
            return false;
        }

        //
        if ($refunds) {
            foreach ($refunds->data as $refund) {

                $transaction = new Transaction;

                //
                $transaction->orderable_amount = 0;
                $transaction->local_status = Transaction::LOCAL_STATUS_COMPLETE;
                $transaction->status = $refund->status;
                $transaction->success = $refund->status == 'succeeded';
                $transaction->transaction_family = Transaction::TRANSACTION_FAMILY_PAYMENT; //for Stripe, since the refund is tied to payment intent, we set the family to payment intent
                $transaction->transaction_family_id = $refund->payment_intent;
                $transaction->transaction_child_id = $refund->id; // i.e we set the child to refund object

                // Set amounts according to refund rules
                $transaction->currency = $refund->currency;
                $transaction->amount = 0;
                $transaction->amount_refunded = -abs($refund->amount);
                $transaction->refund = true;

                //
                $transaction->payment_provider = $this->getProvider();

                //
                $transaction->display_only = true;

                // Note that the reason the followings may be null is that the refund may
                // have been created outside of our API calls.
                $transaction->orderable_id = $refund->metadata->orderable_id ?? null;
                $transaction->cashier_id = $refund->metadata->cashier_id ?? null;
                $transaction->parent_id=null;
                if($refund->metadata->transaction_parent_pid){
                    $parentTransaction=Transaction::where('pid',$refund->metadata->transaction_parent_pid)->first();
                    $transaction->parent_id = $parentTransaction?$parentTransaction->id:null;
                }

                $transaction->livemode = $paymentIntent->livemode;


                $fillable = [
                    'currency', 'amount', 'orderable_amount', 'refund', 'cashier_id',
                    'amount_refunded', 'transaction_family',
                    'transaction_family_id', 'transaction_child_id', 'local_status',
                    'status', 'success', 'orderable_id', 'payment_provider',
                    'parent_id', 'display_only', 'livemode'
                ];
                $existingTransaction = Transaction::where([
                    'payment_provider' => $transaction->payment_provider,
                    'transaction_family' => $transaction->transaction_family,
                    'transaction_family_id' => $transaction->transaction_family_id,
                ])
                    ->whereRaw('COALESCE(orderable_id,0)=COALESCE(?,0)', [$transaction->orderable_id ?? null]) // We put orderable id of 0 when orderable id is null.
                    ->where(function ($query) use ($transaction) {
                        $query->where(function ($query) {
                            $query->whereNull('transaction_child_id')
                                ->where('success', false);
                        })
                            ->orWhere('transaction_child_id', $transaction->transaction_child_id);
                    })->first();

                if ($existingTransaction) {
                    foreach ($fillable as $attr) {
                        $existingTransaction->{$attr} = $transaction->{$attr};
                    }
                    $transaction = $existingTransaction;
                }

                $transaction->save();
            }
            return true;
        }
        return false;
    }

    /**
     * Make API call to sync local held unsuccessful charges with the data the provider holds for
     * the given intent. Note that the transactions resulting from the charges are
     * set to display_only for this stripe_intent provider implementation.
     *
     */
    protected function syncUnsuccessfulCharges(PaymentIntent $paymentIntent): bool
    {


        $charges = null;
        try {
            // Fetch all charges at once
            $charges = $this->client()
                ->charges->all(['payment_intent' => $paymentIntent->id]);
        } catch (\Stripe\Exception\ApiErrorException $ex) {
            Log::error('Updating unsuccessful charges:' . $ex->getMessage(), ['paymentIntent' => $paymentIntent]);
            return false;
        }

        //
        if ($charges) {
            foreach ($charges->data as $charge) {
                if ($charge->status == 'succeeded') {
                    continue; // We ae only interested in unsuccessful charges here.
                }

                //
                $transaction = new Transaction;

                //
                $transaction->orderable_amount = $charge->amount;
                $transaction->local_status = Transaction::LOCAL_STATUS_COMPLETE;
                $transaction->status = $charge->status;
                $transaction->success = $charge->status == 'succeeded';
                $transaction->transaction_family = Transaction::TRANSACTION_FAMILY_PAYMENT;
                $transaction->transaction_family_id = $paymentIntent->id;
                $transaction->transaction_child_id = $charge->id;

                //
                $transaction->amount = 0;
                $transaction->currency = $charge->currency;
                $transaction->display_only = true;

                //
                if ($paymentIntent->customer and ($providerCustomer = PaymentProviderCustomer::fromPaymentProviderId($paymentIntent->customer, $this))) {
                    $transaction->user_type = $providerCustomer->user_type;
                    $transaction->user_id = $providerCustomer->user_id;
                } else {
                    // TODO: Note that the first conditions is unnecessary since we can always get the user_type and user_id from metadata.
                    $transaction->user_type = $paymentIntent->metadata->user_type;
                    $transaction->user_id = $paymentIntent->metadata->user_id;
                }

                //
                $transaction->orderable_id = $paymentIntent->metadata->orderable_id;
                $transaction->cashier_id = $paymentIntent->metadata->cashier_id;

                //
                $transaction->payment_provider = $this->getProvider();

                //
                $transaction->livemode = $paymentIntent->livemode;


                //
                $fillable = [
                    'currency', 'amount', 'orderable_amount', 'cashier_id', 'user_type', 'user_id',
                    'transaction_family',
                    'transaction_family_id', 'transaction_child_id', 'local_status',
                    'status', 'success', 'orderable_id', 'payment_provider',
                    'display_only', 'livemode'
                ];
                $existingTransaction = Transaction::where([
                    'payment_provider' => $transaction->payment_provider,
                    'transaction_family' => $transaction->transaction_family,
                    'transaction_family_id' => $transaction->transaction_family_id,
                ])
                    ->whereRaw('COALESCE(orderable_id,0)=COALESCE(?,0)', [$transaction->orderable_id ?? null]) // We put orderable id of 0 when orderable id is null.
                    ->where(function ($query) use ($transaction) {
                        $query->where(function ($query) {
                            $query->whereNull('transaction_child_id')
                                ->where('success', false);
                        })
                            ->orWhere('transaction_child_id', $transaction->transaction_child_id);
                    })->first();

                if ($existingTransaction) {
                    foreach ($fillable as $attr) {
                        $existingTransaction->{$attr} = $transaction->{$attr};
                    }
                    $transaction = $existingTransaction;
                }

                $transaction->save();
            }
            return true;
        }
        return false;
    }

    /**
     * Set whether refunds should be downloaded
     */
    public function setSyncRefunds(bool $state = true)
    {
        static::$syncRefunds = $state;
    }

    /**
     * Set whether refunds should be downloaded
     */
    public function setSyncUnsuccessfulCharges(bool $state = false)
    {
        static::$syncUnsuccessfulCharges = $state;
    }
}
