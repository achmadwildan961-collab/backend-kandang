<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();
        if (config('database.default') === 'mysql' && config('database.connections.mysql.database') !== 'smart_kandang_test') {
            throw new \RuntimeException('Tests may only migrate the dedicated smart_kandang_test MySQL database.');
        }

        return $app;
    }
}
