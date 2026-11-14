<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\FurimaDeckAccountingBatchItemClaimService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FurimaDeckAccountingBatchItemClaimTest extends TestCase
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

    public function test_a_batch_item_can_only_be_claimed_once_by_its_provider(): void
    {
        $user = User::factory()->create();
        $batch = $user->accountingSyncBatches()->create([
            'provider' => 'freee',
            'period_from' => CarbonImmutable::parse('2026-01-01'),
            'period_to' => CarbonImmutable::parse('2026-12-31'),
            'status' => 'queued',
            'total_count' => 1,
        ]);
        $item = $batch->items()->create([
            'accounting_entry_id' => 1,
            'status' => 'queued',
        ]);
        $claim = app(FurimaDeckAccountingBatchItemClaimService::class);

        $this->assertFalse($claim->claim($batch->id, $item->id, 'money_forward'));
        $this->assertTrue($claim->claim($batch->id, $item->id, 'freee'));
        $this->assertFalse($claim->claim($batch->id, $item->id, 'freee'));
        $this->assertSame('running', $item->fresh()->status);
        $this->assertSame('running', $batch->fresh()->status);
    }
}
