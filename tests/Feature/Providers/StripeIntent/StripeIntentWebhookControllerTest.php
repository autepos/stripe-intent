<?php

namespace Autepos\AiPayment\Tests\Feature\Providers\StripeIntent;

use Illuminate\Http\Request;
use Autepos\AiPayment\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Symfony\Component\HttpFoundation\Response;
use Autepos\AiPayment\Providers\StripeIntent\Events\StripeIntentWebhookHandled;
use Autepos\AiPayment\Providers\StripeIntent\Events\StripeIntentWebhookReceived;
use Autepos\AiPayment\Providers\StripeIntent\Http\Controllers\StripeIntentWebhookController;


class StripeIntentWebhookControllerTest extends TestCase
{
    public function test_proper_methods_are_called_based_on_stripe_event()
    {
        $request = $this->request('test.succeeded');

        Event::fake([
            StripeIntentWebhookHandled::class,
            StripeIntentWebhookReceived::class,
        ]);

        $response = (new WebhookControllerTestStub)->handleWebhook($request);

        Event::assertDispatched(StripeIntentWebhookReceived::class, function (StripeIntentWebhookReceived $event) use ($request) {
            return $request->getContent() == json_encode($event->payload);
        });

        Event::assertDispatched(StripeIntentWebhookHandled::class, function (StripeIntentWebhookHandled $event) use ($request) {
            return $request->getContent() == json_encode($event->payload);
        });

        $this->assertEquals('Webhook Handled', $response->getContent());
    }

    public function test_normal_response_is_returned_if_method_is_missing()
    {
        $request = $this->request('foo.bar');

        Event::fake([
            StripeIntentWebhookHandled::class,
            StripeIntentWebhookReceived::class,
        ]);

        $response = (new WebhookControllerTestStub)->handleWebhook($request);

        Event::assertDispatched(StripeIntentWebhookReceived::class, function (StripeIntentWebhookReceived $event) use ($request) {
            return $request->getContent() == json_encode($event->payload);
        });

        Event::assertNotDispatched(StripeIntentWebhookHandled::class);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertSame('Missing event type: foo.bar', $response->getContent());
    }

    private function request($event)
    {
        return Request::create(
            '/', 'POST', [], [], [], [], json_encode(['type' => $event, 'id' => 'event-id'])
        );
    }
}

class WebhookControllerTestStub extends StripeIntentWebhookController
{
    public function __construct()
    {
        
    }

    public function handleTestSucceeded()
    {
        return new Response('Webhook Handled', 200);
    }

    public function missingMethod($parameters = [])
    {
        return new Response('Missing event type: '.$parameters['type']);
    }
}