<?php

namespace Autepos\AiPayment\Tests\Feature\Providers\StripeIntent;

use Mockery;
use Stripe\Event;
use Stripe\PaymentMethod;
use Illuminate\Support\Facades\Log;
use Autepos\AiPayment\Tenancy\Tenant;
use Autepos\AiPayment\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Autepos\AiPayment\Models\PaymentProviderCustomer;
use Autepos\AiPayment\Providers\Contracts\PaymentProvider;
use Autepos\AiPayment\Models\PaymentProviderCustomerPaymentMethod;
use Autepos\AiPayment\Providers\StripeIntent\StripeIntentPaymentMethod;
use Autepos\AiPayment\Providers\StripeIntent\StripeIntentPaymentProvider;
use Autepos\AiPayment\Providers\StripeIntent\Http\Controllers\StripeIntentWebhookController;
use Autepos\AiPayment\Tests\Feature\Providers\StripeIntent\Stubs\StripeIntentWebhookControllerStub;

class StripeIntent_PaymentMethodWebhookEventHandlers_Test extends TestCase
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
     * Mock of StripeIntentPaymentMethod
     *
     * @var \Mockery\MockInterface
     */
    private $mockStripIntentPaymentMethod;

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
        //
        $this->mockStripIntentPaymentMethod = Mockery::mock(StripeIntentPaymentMethod::class);


        // Mock the payment provider
        $partialMockPaymentProvider = Mockery::mock(StripeIntentPaymentProvider::class)->makePartial();
        
        $partialMockPaymentProvider->config($rawConfig,false);
        $partialMockPaymentProvider->shouldReceive('configUsingFcn')
        ->byDefault()
        ->once()
        ->andReturnSelf();
        
        $partialMockPaymentProvider->shouldReceive('paymentMethodForWebhook')
            ->byDefault()
            ->once()
            ->andReturn($this->mockStripIntentPaymentMethod);

        // Use the mock to replace the payment provider in the manager and set the modified config
        $this->paymentManager()->extend($this->provider, function () use ($partialMockPaymentProvider) {
            return $partialMockPaymentProvider;
        });

        // Now empty the manager drivers cache to ensure that our new mock will be used 
        // to recreate the payment provider on the next access to the manager driver
        $this->paymentManager()->forgetDrivers();

        //
        $this->partialMockPaymentProvider = $partialMockPaymentProvider;

        // Use the stub controller
        app()->bind(StripeIntentWebhookController::class,StripeIntentWebhookControllerStub::class);
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
    public function test_can_handle_payment_method_updated_webhook_event(?string $webhook_secret)
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
        $this->mockStripIntentPaymentMethod->shouldReceive('webhookUpdatedOrAttached')
            ->with(Mockery::type(PaymentMethod::class))
            ->once()
            ->andReturn(true);

        $data = [
            'object' => [
                'id' => 'pm_id',
                'object' => PaymentMethod::OBJECT_NAME,
                'customer' => 'cus_id'
            ]
        ];

        $payload = [
            'id' => 'test_event',
            'object' => Event::OBJECT_NAME,
            'type' => Event::PAYMENT_METHOD_UPDATED,
            'data' => $data,
        ];


        $response = $this->postJson(StripeIntentPaymentProvider::$webhookEndpoint, $payload);
        $response->assertOk();
        $this->assertEquals('Webhook Handled', $response->getContent());
    }

    
    public function test_cannot_handle_payment_method_updated_webhook_event_on_error()
    {

        // Set a specific expectations
        $this->partialMockPaymentProvider->shouldNotReceive('configUsingFcn');
        $this->mockStripIntentPaymentMethod->shouldNotReceive('webhookUpdatedOrAttached');
        $this->partialMockPaymentProvider->shouldNotReceive('paymentMethodForWebhook');

        $data = [
            'object' => [
                'id' => 'pm_id',
                'object' => 'not_payment_method',
                'customer' => 'cus_id'
            ]
        ];

        $payload = [
            'id' => 'test_event',
            'object' => Event::OBJECT_NAME,
            'type' => Event::PAYMENT_METHOD_UPDATED,
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

    /**
     * @param string|null $webhook_secret The webhook secret that should be set in config.
     * @dataProvider webhookSecretConfigDataProvider
     *
     * @return void
     */
    public function test_can_handle_attached_webhook_event(?string $webhook_secret)
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

        // Set more specific expectation
        $this->mockStripIntentPaymentMethod->shouldReceive('webhookUpdatedOrAttached')
            ->byDefault()
            ->with(Mockery::type(PaymentMethod::class))
            ->once()
            ->andReturn(true);

        $data = [
            'object' => [
                'id' => 'pm_id',
                'object' => PaymentMethod::OBJECT_NAME,
                'customer' => 'cus_id'
            ]
        ];

        $payload = [
            'id' => 'test_event',
            'object' => Event::OBJECT_NAME,
            'type' => Event::PAYMENT_METHOD_ATTACHED,
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
    public function test_can_handle_detached_webhook_event(?string $webhook_secret)
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

        // Set more specific expectation
        $this->mockStripIntentPaymentMethod->shouldReceive('webhookDetached')
            ->with(Mockery::type(PaymentMethod::class))
            ->once()
            ->andReturn(true);

        $data = [
            'object' => [
                'id' => 'pm_id',
                'object' => PaymentMethod::OBJECT_NAME,
                'customer' => null
            ]
        ];

        $payload = [
            'id' => 'test_event',
            'object' => Event::OBJECT_NAME,
            'type' => Event::PAYMENT_METHOD_DETACHED,
            'data' => $data,
        ];


        $response = $this->postJson(StripeIntentPaymentProvider::$webhookEndpoint, $payload);
        $response->assertOk();
        $this->assertEquals('Webhook Handled', $response->getContent());
    }

    public function test_cannot_handle_detached_webhook_event_when_on_error()
    {
        // Set a specific expectations
        $this->partialMockPaymentProvider->shouldNotReceive('configUsingFcn');
        $this->partialMockPaymentProvider->shouldNotReceive('paymentMethodForWebhook');

        $data = [
            'object' => [
                'id' => 'pm_id',
                'object' => 'not_payment_method',
                'customer' => null
            ]
        ];

        $payload = [
            'id' => 'test_event',
            'object' => Event::OBJECT_NAME,
            'type' => Event::PAYMENT_METHOD_DETACHED,
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



    //======================================================================
    //===============TESTING THAT WE CAN OBTAIN TENANT ID===================
    //======================================================================
    
    /**
     * 
     *
     * @return void
     */
    public function test_can_return_tenant_id_using_stripe_payment_method(){
        // Set a specific expectations
        $this->partialMockPaymentProvider->shouldNotReceive('configUsingFcn');
        $this->partialMockPaymentProvider->shouldNotReceive('paymentMethodForWebhook');
        
        $tenant_id=1007;
        $user_type='testing-user';
        $user_id='testing-user-id';

        $customer_id='cus_id';

        $payment_method_id='pm_id';
        $data = [
            'object' => [
                'id' =>  $payment_method_id,
                'customer'=>$customer_id,
                'object' => PaymentMethod::OBJECT_NAME,
            ]
        ];

        $paymentMethod=PaymentMethod::constructFrom($data['object']);
        
        //
        $paymentProviderCustomer=PaymentProviderCustomer::factory()->create([
            'payment_provider' => $this->providerInstance()->getProvider(),
            'payment_provider_customer_id' =>  $customer_id,
            'user_type' => $user_type,
            'user_id' => $user_id,
            Tenant::getColumnName()=>$tenant_id

        ]);
        //
        PaymentProviderCustomerPaymentMethod::factory()->create([
            'payment_provider' => $this->providerInstance()->getProvider(),
            'payment_provider_customer_id' =>  $paymentProviderCustomer->id,
            'payment_provider_payment_method_id' =>  $payment_method_id,
            Tenant::getColumnName()=>$tenant_id

        ]);

        //
        $stripeIntentWebhookControllerStubWithProxy=new class extends StripeIntentWebhookController{
            public function __construct(){}
            public function stripePaymentMethodToTenantIdProxy(PaymentMethod $paymentMethod,PaymentProvider $paymentProvider){
                $this->paymentProvider=$paymentProvider;
                return $this->stripePaymentMethodToTenantId($paymentMethod);
            }
        };


        $result=$stripeIntentWebhookControllerStubWithProxy->stripePaymentMethodToTenantIdProxy($paymentMethod,$this->providerInstance());

        $this->assertEquals($tenant_id,$result);
    }

        /**
     * 
     *
     * @return void
     */
    public function test_can_log_critical_for_multiple_models_when_returning_tenant_id_using_stripe_payment_method(){
        // Set a specific expectations
        $this->partialMockPaymentProvider->shouldNotReceive('configUsingFcn');
        $this->partialMockPaymentProvider->shouldNotReceive('paymentMethodForWebhook');
        
        $tenant_id=1007;
        $user_type='testing-user';
        $user_id='testing-user-id';

        $customer_id='cus_id';

        $payment_method_id='pm_id';
        $data = [
            'object' => [
                'id' =>  $payment_method_id,
                'customer'=>$customer_id,
                'object' => PaymentMethod::OBJECT_NAME,
            ]
        ];

        $paymentMethod=PaymentMethod::constructFrom($data['object']);
        
        //
        $paymentProviderCustomer=PaymentProviderCustomer::factory()->create([
            'payment_provider' => $this->providerInstance()->getProvider(),
            'payment_provider_customer_id' =>  $customer_id,
            'user_type' => $user_type,
            'user_id' => $user_id,
            Tenant::getColumnName()=>$tenant_id

        ]);
        //
        PaymentProviderCustomerPaymentMethod::factory()->create([
            'payment_provider' => $this->providerInstance()->getProvider(),
            'payment_provider_customer_id' =>  $paymentProviderCustomer->id,
            'payment_provider_payment_method_id' =>  $payment_method_id,
            Tenant::getColumnName()=>$tenant_id

        ]);

        // Another payment method to create a duplicate of some sort
        PaymentProviderCustomerPaymentMethod::factory()->create([
            'payment_provider' => $this->providerInstance()->getProvider(),
            'payment_provider_customer_id' =>  $paymentProviderCustomer->id,
            'payment_provider_payment_method_id' =>  $payment_method_id,
            Tenant::getColumnName()=>$tenant_id

        ]);

        //
        $stripeIntentWebhookControllerStubWithProxy=new class extends StripeIntentWebhookController{
            public function __construct(){}
            public function stripePaymentMethodToTenantIdProxy(PaymentMethod $paymentMethod,PaymentProvider $paymentProvider){
                $this->paymentProvider=$paymentProvider;
                return $this->stripePaymentMethodToTenantId($paymentMethod);
            }
        };
        
        Log::shouldReceive('critical')->once();

        $result=$stripeIntentWebhookControllerStubWithProxy->stripePaymentMethodToTenantIdProxy($paymentMethod,$this->providerInstance());

        $this->assertNull($result);
    }

        /**
     * Test that we can retrieve a tenant id with a given stripe customer
     *
     * @return void
     */
    public function test_can_log_alert_for_missing_model_when_returning_tenant_id_using_stripe_payment_method(){
        // Set a specific expectations
        $this->partialMockPaymentProvider->shouldNotReceive('configUsingFcn');
        $this->partialMockPaymentProvider->shouldNotReceive('paymentMethodForWebhook');
        
        $customer_id='cus_id';
        $payment_method_id='pm_id';
        $data = [
            'object' => [
                'id' =>  $payment_method_id,
                'customer'=>$customer_id,
                'object' => PaymentMethod::OBJECT_NAME,
            ]
        ];

        $paymentMethod=PaymentMethod::constructFrom($data['object']);
    
        //
        $stripeIntentWebhookControllerStubWithProxy=new class extends StripeIntentWebhookController{
            public function __construct(){}
            public function stripePaymentMethodToTenantIdProxy(PaymentMethod $paymentMethod,PaymentProvider $paymentProvider){
                $this->paymentProvider=$paymentProvider;
                return $this->stripePaymentMethodToTenantId($paymentMethod);
            }
        };
        
        Log::shouldReceive('alert')->once();

        $result=$stripeIntentWebhookControllerStubWithProxy->stripePaymentMethodToTenantIdProxy($paymentMethod,$this->providerInstance());

        $this->assertNull($result);

    }
}
