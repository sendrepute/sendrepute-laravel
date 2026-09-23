<?php

namespace SendRepute\Laravel\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use SendRepute\Laravel\SendReputeServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [SendReputeServiceProvider::class];
    }
}