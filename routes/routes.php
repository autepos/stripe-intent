<?php

use Illuminate\Support\Facades\Route;
use Autepos\AiPayment\Providers\StripeIntent\StripeIntentPaymentProvider;
use Autepos\AiPayment\Providers\StripeIntent\Http\Controllers\StripeIntentWebhookController;

// Stripe Webhook - We are putting it in its own file so that we can have it 
// outside of the web middleware group to avoid csrf checks
Route::post(StripeIntentPaymentProvider::$webhookEndpoint, [StripeIntentWebhookController::class, 'handleWebhook']);
