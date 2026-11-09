<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    // vendor-dir is build/vendor (not vendor/), so the framework's base-path
    // inference would land in build/ — resolve the real repo root here instead.
    public function createApplication()
    {
        $app = require dirname(__DIR__).'/bootstrap/app.php';

        $this->traitsUsedByTest = class_uses_recursive(static::class);

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }
}
