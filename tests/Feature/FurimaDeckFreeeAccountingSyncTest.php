<?php

namespace Tests\Feature;

use App\Models\AccountingConnection;
use App\Models\AccountingEntry;
use App\Models\Marketplace;
use App\Models\User;
use App\Services\FurimaDeckFreeeAccountingSyncService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class FurimaDeckFreeeAccountingSyncTest extends TestCase
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
        config()->set('furimadeck.cutover_enabled', true);
        config()->set('furimadeck.product_management_enabled', true);
        config()->set('furimadeck.accounting.freee_sync', [
            'api_base' => 'https://api.freee.test',
            'company_id' => '123',
            'tax_code' => '999',
            'account_item_ids' => [
                'settlement' => '1',
                'sales' => '2',
                'fees' => '3',
                'cost' => '4',
                'inventory' => '5',
            ],
        ]);
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection('sqlite');
        DB::purge('furimadeck');
        parent::tearDown();
    }

    public function test_confirmed_entry_is_sent_once_and_external_id_is_saved(): void
    {
        [$user, $entry] = $this->entryFixture();
        AccountingConnection::query()->create([
            'user_id' => $user->id,
            'provider' => 'freee',
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_at' => now()->addHour(),
            'status' => 'connected',
        ]);
        Http::fake([
            'https://api.freee.test/api/1/manual_journals' => Http::response([
                'manual_journal' => ['id' => 456],
            ], 201),
        ]);

        $externalId = app(FurimaDeckFreeeAccountingSyncService::class)->send($user, $entry);

        $entry->refresh();
        $this->assertSame('456', $externalId);
        $this->assertSame('synced', $entry->sync_status);
        $this->assertSame('456', $entry->external_transaction_id);
        $this->assertNotNull($entry->synced_at);

        $this->expectExceptionMessage('すでに送信済み');
        app(FurimaDeckFreeeAccountingSyncService::class)->send($user, $entry);

        Http::assertSent(function ($request): bool {
            $payload = $request->data();

            return $request->url() === 'https://api.freee.test/api/1/manual_journals'
                && count($payload['details']) === 6
                && collect($payload['details'])->sum('amount') === 24000;
        });
    }

    public function test_synced_entry_is_rejected_without_an_external_request(): void
    {
        [$user, $entry] = $this->entryFixture();
        $entry->update(['sync_status' => 'synced', 'external_transaction_id' => '456']);
        Http::fake();

        try {
            app(FurimaDeckFreeeAccountingSyncService::class)->send($user, $entry);
            $this->fail('送信済みデータは拒否される必要があります。');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('すでに送信済み', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_api_failure_is_recorded_without_marking_the_entry_synced(): void
    {
        [$user, $entry] = $this->entryFixture();
        $connection = AccountingConnection::query()->create([
            'user_id' => $user->id,
            'provider' => 'freee',
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_at' => now()->addHour(),
            'status' => 'connected',
        ]);
        Http::fake([
            'https://api.freee.test/api/1/manual_journals' => Http::response(['error' => 'rejected'], 422),
        ]);

        try {
            app(FurimaDeckFreeeAccountingSyncService::class)->send($user, $entry);
            $this->fail('APIエラーは例外になる必要があります。');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('送信に失敗', $exception->getMessage());
        }

        $this->assertSame('not_synced', $entry->fresh()->sync_status);
        $this->assertSame('sync_failed', $connection->fresh()->last_error);
    }

    public function test_connection_failure_is_recorded_without_marking_the_entry_synced(): void
    {
        [$user, $entry] = $this->entryFixture();
        $connection = AccountingConnection::query()->create([
            'user_id' => $user->id,
            'provider' => 'freee',
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_at' => now()->addHour(),
            'status' => 'connected',
        ]);
        Http::fake(function (): void {
            throw new ConnectionException('offline');
        });

        try {
            app(FurimaDeckFreeeAccountingSyncService::class)->send($user, $entry);
            $this->fail('通信例外は送信失敗として扱う必要があります。');
        } catch (\Throwable $exception) {
            $this->assertInstanceOf(ConnectionException::class, $exception);
        }

        $this->assertSame('not_synced', $entry->fresh()->sync_status);
        $this->assertSame('sync_failed', $connection->fresh()->last_error);
    }

    public function test_another_user_cannot_send_the_entry(): void
    {
        [$owner, $entry] = $this->entryFixture();
        $otherUser = User::factory()->create();
        AccountingConnection::query()->create([
            'user_id' => $owner->id,
            'provider' => 'freee',
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_at' => now()->addHour(),
            'status' => 'connected',
        ]);
        Http::fake();

        $this->expectException(HttpException::class);
        app(FurimaDeckFreeeAccountingSyncService::class)->send($otherUser, $entry);
        Http::assertNothingSent();
    }

    public function test_returned_sale_entry_is_not_sent(): void
    {
        [$user, $entry] = $this->entryFixture();
        $entry->sale()->update(['status' => 'returned']);
        Http::fake();

        $this->expectException(ModelNotFoundException::class);
        try {
            app(FurimaDeckFreeeAccountingSyncService::class)->send($user, $entry);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_unconfirmed_entry_is_not_sent(): void
    {
        [$user, $entry] = $this->entryFixture();
        $entry->update(['source_status' => 'ready']);
        $this->expectExceptionMessage('Confirm済み');
        app(FurimaDeckFreeeAccountingSyncService::class)->send($user, $entry);
        Http::assertNothingSent();
    }

    /** @return array{0: User, 1: AccountingEntry} */
    private function entryFixture(): array
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'subscription_plan' => User::SUBSCRIPTION_ACTIVE, 'subscription_status' => 'active']);
        $product = $user->products()->create([
            'internal_sku' => 'FREEE-'.uniqid(),
            'product_name' => 'freee送信商品',
            'condition' => 'used',
            'purchase_unit_cost' => 1000,
            'purchase_quantity' => 1,
            'quantity_available' => 0,
            'inventory_status' => 'out_of_stock',
        ]);
        $marketplace = Marketplace::query()->create([
            'code' => 'freee-'.uniqid(),
            'name' => 'freee販売先',
            'base_url' => 'https://example.test',
            'fee_type' => 'percentage',
            'default_fee_rate' => 0,
            'is_active' => true,
        ]);
        $sale = $user->sales()->create([
            'product_id' => $product->id,
            'marketplace_id' => $marketplace->id,
            'internal_sku_snapshot' => $product->internal_sku,
            'product_name_snapshot' => $product->product_name,
            'quantity' => 1,
            'sold_price' => 10000,
            'sold_at' => '2026-01-10',
            'status' => 'completed',
            'cost_basis' => 1000,
            'net_profit' => 9000,
        ]);
        $entry = AccountingEntry::query()->create([
            'user_id' => $user->id,
            'sale_id' => $sale->id,
            'transaction_date' => '2026-01-10',
            'management_id' => $product->internal_sku,
            'title' => $product->product_name,
            'sale_amount' => 10000,
            'purchase_cost' => 1000,
            'selling_fee' => 500,
            'shipping_cost' => 500,
            'furimadeck_actual_profit' => 8000,
            'source_status' => 'confirmed',
            'sync_status' => 'not_synced',
        ]);

        return [$user, $entry];
    }
}
