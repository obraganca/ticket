<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Trava de segurança: RefreshDatabase/truncate jamais rodam fora de um banco *_test.
        $database = config('database.connections.'.config('database.default').'.database');
        if (! str_ends_with((string) $database, '_test')) {
            $this->fail("Recusando rodar testes no banco '{$database}': só bancos *_test são permitidos.");
        }
    }
}
