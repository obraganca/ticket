<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Feature: app Laravel + Postgres de teste (ticketing_test), cada teste em uma transação revertida.
uses(TestCase::class, RefreshDatabase::class)->in('Feature');

// Concurrency: COMMITS reais em vários processos => sem RefreshDatabase; o próprio teste
// limpa as tabelas (tests/Support/Truncates.php).
uses(TestCase::class)->in('Concurrency');

// Unit: lógica pura, sem app nem banco.
