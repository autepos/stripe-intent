<?php

namespace Autepos\AiPayment\Tests;

use Autepos\AiPayment\AiPaymentServiceProvider;
use Autepos\AiPayment\Providers\StripeIntent\StripeIntentServiceProvider;



abstract class TestCase extends \Orchestra\Testbench\TestCase
{
    protected function getEnvironmentSetUp($app)
    {

        config()->set('database.connections.mysql.engine', 'InnoDB');
    }


    /**
     * Get package providers.
     *
     * @param  \Illuminate\Foundation\Application  $app
     *
     * @return array
     */
    protected function getPackageProviders($app)
    {
        return [
            AiPaymentServiceProvider::class,
            StripeIntentServiceProvider::class,
        ];
    }
}
