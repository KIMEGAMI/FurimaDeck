<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\FurimaDeckSaleExpenseAccountingService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FurimaDeckSaleExpenseAccountingServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $connection = config('database.connections.sqlite');
        $connection['database'] = ':memory:';
        config()->set('database.connections.furimadeck', $connection);
        DB::purge('furimadeck');
        DB::setDefaultConnection('furimadeck');
        $this->artisan('migrate', ['--database' => 'furimadeck', '--path' => 'database/migrations/furimadeck'])->assertSuccessful();
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection('sqlite');
        DB::purge('furimadeck');
        parent::tearDown();
    }

    public function test_unchecked_confirmation_does_not_reuse_existing_evidence_approval(): void
    {
        $user = User::factory()->create();
        $existing = ['packing_cost' => [
            'document_type' => 'receipt',
            'tax_category' => 'standard_10',
            'invoice_issuer' => 'qualified',
            'date_matches_entry_confirmed' => true,
            'reviewed_at' => now()->toIso8601String(),
        ]];

        $normalized = app(FurimaDeckSaleExpenseAccountingService::class)->normalize(
            $user,
            ['packing_cost' => 500],
            ['packing_cost' => [
                'document_type' => 'receipt',
                'tax_category' => 'standard_10',
                'invoice_issuer' => 'qualified',
            ]],
            $existing,
        );

        $this->assertFalse($normalized['packing_cost']['date_matches_entry_confirmed']);
        $this->assertArrayNotHasKey('reviewed_at', $normalized['packing_cost']);
    }

    public function test_explicit_confirmation_records_a_fresh_review_timestamp(): void
    {
        $user = User::factory()->create();

        $normalized = app(FurimaDeckSaleExpenseAccountingService::class)->normalize(
            $user,
            ['packing_cost' => 500],
            ['packing_cost' => [
                'document_type' => 'receipt',
                'tax_category' => 'standard_10',
                'invoice_issuer' => 'qualified',
                'date_matches_entry_confirmed' => '1',
            ]],
        );

        $this->assertTrue($normalized['packing_cost']['date_matches_entry_confirmed']);
        $this->assertNotEmpty($normalized['packing_cost']['reviewed_at']);
    }
}
