<?php

namespace Autepos\AiPayment\Tests\Feature\Providers\StripeIntent;

use Mockery;
use Stripe\Event;
use Stripe\PaymentIntent;
use Illuminate\Support\Facades\Log;
use Autepos\AiPayment\Tests\TestCase;
use Autepos\AiPayment\PaymentResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Autepos\AiPayment\Providers\StripeIntent\StripeIntentPaymentProvider;

class StripeIntent_PaymentProviderWebhookEventHandlers_Test extends TestCase
{
    use StripeIntentTestHelpers;
    use RefreshDatabase;

    private $provider = StripeIntentPaymentProvider::PROVIDER;

    /**
     * Mock of StripeIntentPaymentProvider
     *
     * @var \Mockery\MockInterface
     */
    private $partialMockPaymentProvider;

    /**
     * The config for the payment provider
     *
     * @var array
     */
    private $rawConfig=[];

    /**
     * The webhook secret that should be set in config.
     */
    const WEBHOOK_SECRET='secret';



    public function setUp(): void
    {
        parent::setUp();

        // Turn off webhook secret to disable webhook verification so we do not 
        // have to sign the request we make to the webhook endpoint.
        $paymentProvider = $this->providerInstance();

        $rawConfig = $paymentProvider->getRawConfig();
        $rawConfig['webhook_secret'] = null;
        $this->rawConfig=$rawConfig;


        // Mock the payment provider
        $partialMockPaymentProvider = Mockery::mock(StripeIntentPaymentProvider::class)->makePartial();
        
        $partialMockPaymentProvider->config($rawConfig,false);
        $partialMockPaymentProvider->shouldReceive('configUsingFcn')
        ->byDefault()
        ->once()
        ->andReturnSelf();
        
        $partialMockPaymentProvider->shouldReceive('webhookChargeByRetrieval')
            ->byDefault()
            ->with(Mockery::type(PaymentIntent::class))
            ->once()
            ->andReturn(new PaymentResponse(PaymentResponse::newType('charge'), true));

        // Use the mock to replace the payment provider in the manager and set the modified config
        $this->paymentManager()->extend($this->provider, function () use ($partialMockPaymentProvider) {
            return $partialMockPaymentProvider;
        });

        // Now empty the manager drivers cache to ensure that our new mock will be used 
        // to recreate the payment provider on the next access to the manager driver
        $this->paymentManager()->forgetDrivers();

        //
        $this->partialMockPaymentProvider = $partialMockPaymentProvider;

    }

    /**
     * Data provider for config webhook secret
     *
     * @return array
     */
    public function webhookSecretConfigDataProvider(){
        return [
            'webhook secret set in config'=>[static::WEBHOOK_SECRET],
            'NO webhook secret set in config'=>[null]
        ];
    }

    /**
     * @param string|null $webhook_secret The webhook secret that should be set in config.
     * @dataProvider webhookSecretConfigDataProvider
     *
     * @return void
     */
    public function test_can_handle_payment_intent_succeeded_webhook_event(?string $webhook_secret)
    {
        // Set specific assertions
        if($webhook_secret){
            // If webhook secret is set in config, we must ensure that webhook is verified. 
            $this->partialMockPaymentProvider->shouldReceive('verifyWebhookHeader')
            ->once()
            ->andReturn(true);
        }
        
        // Set the required configuration
        $this->rawConfig['webhook_secret'] = $webhook_secret;
        $this->partialMockPaymentProvider->config($this->rawConfig,false);


        //
        $payment_intent_id='pi_id';
        

        // Now post an intent with a succeeded status
        $data = [
            'object' => [
                'id' => $payment_intent_id,
                'status' => PaymentIntent::STATUS_SUCCEEDED,
                'object' => PaymentIntent::OBJECT_NAME,
                'metadata'=>['tenant_id'=>2]
            ]
        ];

        $payload = [
            'id' => 'test_event',
            'object' => Event::OBJECT_NAME,
            'type' => Event::PAYMENT_INTENT_SUCCEEDED,
            'data' => $data,
        ];


        $response = $this->postJson(StripeIntentPaymentProvider::$webhookEndpoint, $payload);
        $response->assertOk();
        $this->assertEquals('Webhook Handled', $response->getContent());
    }

    /**
     * @param string|null $webhook_secret The webhook secret that should be set in config.
     * @dataProvider webhookSecretConfigDataProvider
     *
     * @return void
     */
    public function test_can_handle_payment_intent_succeeded_webhook_event_when_data_recording_fails(?string $webhook_secret)
    {

        // Set specific assertions
        if($webhook_secret){
            // If webhook secret is set in config, we must ensure that webhook is verified. 
            $this->partialMockPaymentProvider->shouldReceive('verifyWebhookHeader')
            ->once()
            ->andReturn(true);
        }
        
        // Set the required configuration
        $this->rawConfig['webhook_secret'] = $webhook_secret;
        $this->partialMockPaymentProvider->config($this->rawConfig,false);


        // Set a specific expectation on the payment provider partial mock
        $this->partialMockPaymentProvider->shouldReceive('webhookChargeByRetrieval')
            ->once()
            ->with(Mockery::type(PaymentIntent::class))
            ->andReturn(new PaymentResponse(PaymentResponse::newType('charge'), false));

        
        $payment_intent_id='pi_id';
        

        // Now post an intent with a succeeded status
        $data = [
            'object' => [
                'id' => $payment_intent_id,
                'status' => PaymentIntent::STATUS_SUCCEEDED,
                'object' => PaymentIntent::OBJECT_NAME,
                'metadata'=>[
                    'tenant_id'=>2,
                ],
            ]
        ];

        $payload = [
            'id' => 'test_event',
            'object' => Event::OBJECT_NAME,
            'type' => Event::PAYMENT_INTENT_SUCCEEDED,
            'data' => $data,
        ];

        //
        Log::shouldReceive('error')
            ->once();

        //
        $response = $this->postJson(StripeIntentPaymentProvider::$webhookEndpoint, $payload);
        $response->assertStatus(422);
        $this->assertEquals('There was an issue with processing the webhook', $response->getContent());
    }


    public function test_cannot_handle_payment_intent_succeeded_webhook_event_when_the_intent_cannot_be_extracted()
    {
        
       
        // Set a specific expectation on the payment provider partial mock
        $this->partialMockPaymentProvider->shouldNotReceive('configUsingFcn');
        $this->partialMockPaymentProvider->shouldNotReceive('webhookChargeByRetrieval');

        // Now post an intent with a succeeded status
        $data = [
            'object' => [
                'id' => 'pi_id',
                'status' => PaymentIntent::STATUS_SUCCEEDED,
                'object' => 'not_payment_intent',
                'metadata'=>[
                    'tenant_id'=>2,
                ],
            ]
        ];

        $payload = [
            'id' => 'test_event',
            'object' => Event::OBJECT_NAME,
            'type' => Event::PAYMENT_INTENT_SUCCEEDED,
            'data' => $data,
        ];

        //
        Log::shouldReceive('error')
            ->once();

        //
        $response = $this->postJson(StripeIntentPaymentProvider::$webhookEndpoint, $payload);
        $response->assertStatus(422);
        $this->assertEquals('There was an issue with processing the webhook', $response->getContent());
    }
   
}
