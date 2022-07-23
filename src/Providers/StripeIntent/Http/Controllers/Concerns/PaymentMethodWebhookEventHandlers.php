<?php

namespace Autepos\AiPayment\Providers\StripeIntent\Http\Controllers\Concerns;

use Stripe\StripeObject;
use Stripe\PaymentMethod;
use Illuminate\Support\Facades\Log;
use Autepos\AiPayment\Tenancy\Tenant;
use Autepos\AiPayment\Models\PaymentProviderCustomerPaymentMethod;


trait PaymentMethodWebhookEventHandlers
{
    /**
     * Use a stripe payment method to get tenant id
     *
     * @return int|string|null
     */
    protected function stripePaymentMethodToTenantId(PaymentMethod $paymentMethod){
            // If Stripe $paymentMethod->id is not universally unique then it is possible for us to 
            // have more than one tenant with different customers with payment methods that happen to have the 
            // same $paymentMethod->id. This means that we cannot uniquely identify the correct 
            // tenant. In this super SUPER extremely RARE case we will halt and log the error.
            // BUT THIS IS PEDANTIC AS STRIPE PaymentMethod ID SHOULD BE UNIVERSALLY UNIQUE, 
            // SO THIS SHOULD NOT HAPPEN. BUT WE CANNOT CURRENTLY CONFIRM THE UNIVERSAL UNIQUENESS.
            $tenant_column_name=Tenant::getColumnName();
            $paymentProviderCustomerPaymentMethod=PaymentProviderCustomerPaymentMethod::query()
            ->whereHas('customer',function($query)use($paymentMethod){
                $query->where('payment_provider_customer_id',$paymentMethod->customer)
                ->where('payment_provider',$this->paymentProvider->getProvider());
            })
            ->where('payment_provider_payment_method_id',$paymentMethod->id)
            ->where('payment_provider',$this->paymentProvider->getProvider())
            ->get();
            

            if($paymentProviderCustomerPaymentMethod->count()>1){
                $msg='A rare error in: ' . __METHOD__ . '- It was not possible to identify 
                tenant for stripe webhook because more 
                than one payment have the same Stripe payment method id. Stripe PaymentMethod id may not be universally unique after all, very strange.';
                Log::critical($msg,[
                    'paymentProviderCustomerPaymentMethod'=>$paymentProviderCustomerPaymentMethod,
                    'paymentMethod'=>$paymentMethod
                ]);
                $paymentProviderCustomerPaymentMethod=collect();
            }elseif($paymentProviderCustomerPaymentMethod->count()==0){
                $msg='A strange error in: ' . __METHOD__ . '- It was not possible to identify because there was no matching PaymentProviderCustomerPaymentMethod model';
                Log::alert($msg,[
                    'paymentMethod'=>$paymentMethod
                ]);
            }

            
            //
            $paymentProviderCustomerPaymentMethod=$paymentProviderCustomerPaymentMethod->first();
            return $paymentProviderCustomerPaymentMethod
                    ? $paymentProviderCustomerPaymentMethod->{$tenant_column_name}
                    :null;
    }
    /**
     * Handle payment_method.updated
     *
     * @param StripeObject $event
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function handlePaymentMethodUpdated(StripeObject $event)
    {

        $paymentMethod = $event->data->object;

        if ($paymentMethod instanceof PaymentMethod) {

            $tenant_id=$this->stripePaymentMethodToTenantId($paymentMethod);
            
            if($tenant_id){
                $this->prepareToHandleRequest($tenant_id);
                

                //
                $result = $this->paymentProvider->paymentMethodForWebhook()
                    ->webhookUpdatedOrAttached($paymentMethod);

                if ($result) {
                    return $this->successMethod();
                }
            }
        }
        Log::error('Stripe webhook - received : ' . __METHOD__ . ' - But it could not be handled correctly:', ['webhook_event' => $event]);

        return response('There was an issue with processing the webhook', 422);
    }
    /**
     * Handle payment_method.automatically_updated
     *
     * @param StripeObject $event
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function handlePaymentMethodAutomaticallyUpdated(StripeObject $event)
    {
        return $this->handlePaymentMethodUpdated($event);
    }

    /**
     * Handle payment_method.attached
     *
     * @param StripeObject $event
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function handlePaymentMethodAttached(StripeObject $event)
    {
        return $this->handlePaymentMethodUpdated($event);
    }

    /**
     * Handle payment_method.detached
     *
     * @param StripeObject $event
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function handlePaymentMethodDetached(StripeObject $event)
    {

        $paymentMethod = $event->data->object;

        if ($paymentMethod instanceof PaymentMethod) {
            $tenant_id=$this->stripePaymentMethodToTenantId($paymentMethod);
            if($tenant_id){
                $this->prepareToHandleRequest($tenant_id);

                $result = $this->paymentProvider->paymentMethodForWebhook()
                    ->webhookDetached($paymentMethod);

                if ($result) {
                    return $this->successMethod();
                }
            }
        }

        Log::error('Stripe webhook - received : ' . __METHOD__ . ' - But it could not be handled correctly:', ['webhook_event' => $event]);
        return response('There was an issue with processing the webhook', 422);
    }
}
