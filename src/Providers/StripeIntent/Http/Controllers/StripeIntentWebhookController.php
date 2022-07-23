<?php

namespace Autepos\AiPayment\Providers\StripeIntent\Http\Controllers;

use Stripe\Webhook;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use Autepos\AiPayment\Contracts\PaymentProviderFactory;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Autepos\AiPayment\Providers\StripeIntent\StripeIntentPaymentProvider;
use Autepos\AiPayment\Providers\StripeIntent\Events\StripeIntentWebhookHandled;
use Autepos\AiPayment\Providers\StripeIntent\Events\StripeIntentWebhookReceived;
use Autepos\AiPayment\Providers\StripeIntent\Http\Controllers\Concerns\CustomerWebhookEventHandlers;
use Autepos\AiPayment\Providers\StripeIntent\Http\Controllers\Concerns\PaymentMethodWebhookEventHandlers;
use Autepos\AiPayment\Providers\StripeIntent\Http\Controllers\Concerns\PaymentProviderWebhookEventHandlers;

class StripeIntentWebhookController extends Controller
{
    use  PaymentProviderWebhookEventHandlers;
    use  PaymentMethodWebhookEventHandlers;
    use  CustomerWebhookEventHandlers;

    /**
     * @var \Autepos\AiPayment\Providers\StripeIntent\StripeIntentPaymentProvider
     */
    protected $paymentProvider;

    /**
     * The request from the payment provider i.e the webhook request object.
     *
     * @var Request
     */
    protected $request;

    public function __construct(PaymentProviderFactory $paymentManager)
    {
        /**
         * @var \Autepos\AiPayment\Providers\StripeIntent\StripeIntentPaymentProvider
         */
        $this->paymentProvider = $paymentManager->driver(StripeIntentPaymentProvider::PROVIDER);
    }

    /**
     * Validate request
     * NOTE: Unfortunately for testing purposes, we have to make this method public,
     * otherwise it should be protected.
     *
     * @return bool
     * @throws AccessDeniedHttpException If the request fails validation
     */
    public function validateWebhookRequest(Request $request)
    {

        $payload = $request->getContent();
        $sig_header = $request->header('Stripe-Signature');

        //
        $config = $this->paymentProvider->getConfig();

        $endpoint_secret = $config['webhook_secret'];
        $tolerance = $config['webhook_tolerance'] ?? Webhook::DEFAULT_TOLERANCE;

        try {
            $this->paymentProvider->verifyWebhookHeader(
                $payload,
                $sig_header,
                $endpoint_secret,
                $tolerance
            );
        } catch (SignatureVerificationException $exception) {
            throw new AccessDeniedHttpException($exception->getMessage(), $exception);
        }

        return true;
    }

    /**
     * Prepare to handle the request and validate the request only if it is possible
     * 
     * @param mixed $tenant_id
     * @return bool
     * @throws AccessDeniedHttpException If the request fails validation
     */
    protected function prepareToHandleRequest($tenant_id)
    {
        // Set the tenant
        StripeIntentPaymentProvider::tenant($tenant_id);

        // Configure the payment provider using a callback
        $this->paymentProvider->configUsingFcn();

        // Validate request if possible
        $config = $this->paymentProvider->getConfig();
        $endpoint_secret = $config['webhook_secret'];

        // Validate webhook if webhook secret is set
        if (!is_null($endpoint_secret)) {
            $this->validateWebhookRequest($this->request);
        }

        return true;
    }


    /**
     * Handle a Stripe webhook call.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handleWebhook(Request $request)
    {
        $this->request = $request;

        //
        $payload = $request->getContent();
        $data = \json_decode($payload, true);
        $jsonError = \json_last_error();
        if (null === $data && \JSON_ERROR_NONE !== $jsonError) {

            Log::error('Error decoding Stripe webhook', ['payload' => $payload, 'json_last_error' => $jsonError]);
            return new Response('Invalid input', 400);
        }

        $event = \Stripe\Event::constructFrom($data);


        $method = 'handle' . Str::studly(str_replace('.', '_', $event->type));

        StripeIntentWebhookReceived::dispatch($data);

        if (method_exists($this, $method)) {
            $response = $this->{$method}($event);

            StripeIntentWebhookHandled::dispatch($data);

            return $response;
        }

        return $this->missingMethod($data);
    }


    /**
     * Handle successful calls on the controller.
     *
     * @param  array  $parameters
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function successMethod($parameters = [])
    {
        return new Response('Webhook Handled', 200);
    }

    /**
     * Handle calls to missing methods on the controller.
     *
     * @param  array  $parameters
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function missingMethod($parameters = [])
    {
        return response('Unknown webhook - it may not have been set up', 404);
    }
}
