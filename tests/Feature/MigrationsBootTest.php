<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MigrationsBootTest extends TestCase
{
    use RefreshDatabase;

    public function test_core_tables_exist(): void
    {
        $this->assertTrue(Schema::hasTable('products'));
        $this->assertTrue(Schema::hasTable('wallet_transactions'));
        $this->assertTrue(Schema::hasTable('platform_withdrawals'));
        $this->assertTrue(Schema::hasColumn('products', 'origin_country'));
    }
}
