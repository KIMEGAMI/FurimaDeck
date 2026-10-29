<?php

namespace Tests\Feature;

use App\Models\AccountingPurchaseCapReview;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FurimaDeckAccountingPurchaseCapReviewTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection('sqlite');
        DB::purge('furimadeck');
        parent::tearDown();
    }

    public function test_review_is_encrypted_displayed_and_overlapping_period_is_rejected(): void
    {
        $user = $this->premiumUser();
        $payload = $this->reviewPayload();

        $this->actingAs($user)
            ->post(route('furimadeck-accounting.purchase-caps.store'), $payload)
            ->assertRedirect(route('furimadeck-accounting.purchase-caps.index'));

        $review = AccountingPurchaseCapReview::query()->firstOrFail();
        $storedName = DB::connection('furimadeck')
            ->table('accounting_purchase_cap_reviews')
            ->where('id', $review->id)
            ->value('counterparty_name');

        $this->assertNotSame($payload['counterparty_name'], $storedName);
        $this->assertSame($payload['counterparty_name'], $review->counterparty_name);
        $this->actingAs($user)
            ->get(route('furimadeck-accounting.purchase-caps.index'))
            ->assertOk()
            ->assertSeeText($payload['counterparty_name']);

        $this->actingAs($user)
            ->post(route('furimadeck-accounting.purchase-caps.store'), $payload)
            ->assertSessionHasErrors('counterparty_name');

        $this->assertSame(1, AccountingPurchaseCapReview::query()->count());
    }

    public function test_review_cannot_be_updated_by_another_user(): void
    {
        $owner = $this->premiumUser();
        $other = $this->premiumUser();
        $this->actingAs($owner)->post(route('furimadeck-accounting.purchase-caps.store'), $this->reviewPayload())->assertRedirect();
        $review = AccountingPurchaseCapReview::query()->firstOrFail();

        $this->actingAs($other)
            ->put(route('furimadeck-accounting.purchase-caps.update', $review), [
                'external_qualifying_purchase_amount' => 500,
                'reviewed_through' => '2026-10-18',
                'furimadeck_history_complete' => '1',
                'external_history_complete' => '1',
            ])
            ->assertNotFound();

        $this->assertSame(100, $review->fresh()->external_qualifying_purchase_amount);
    }

    public function test_owner_can_revoke_history_completeness_to_block_future_accounting_exports(): void
    {
        $user = $this->premiumUser();
        $this->actingAs($user)
            ->post(route('furimadeck-accounting.purchase-caps.store'), $this->reviewPayload())
            ->assertRedirect();
        $review = AccountingPurchaseCapReview::query()->firstOrFail();

        $this->actingAs($user)
            ->put(route('furimadeck-accounting.purchase-caps.update', $review), [
                'external_qualifying_purchase_amount' => 100,
                'reviewed_through' => '2026-10-18',
            ])
            ->assertRedirect(route('furimadeck-accounting.purchase-caps.index'));

        $this->assertFalse($review->fresh()->furimadeck_history_complete);
        $this->assertFalse($review->fresh()->external_history_complete);
    }

    private function premiumUser(): User
    {
        return User::factory()->create([
            'email_verified_at' => now(),
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
        ]);
    }

    /** @return array<string, string> */
    private function reviewPayload(): array
    {
        return [
            'counterparty_name' => '外部テスト仕入先',
            'period_from' => '2026-10-01',
            'period_to' => '2027-09-30',
            'external_qualifying_purchase_amount' => '100',
            'reviewed_through' => '2026-10-18',
            'furimadeck_history_complete' => '1',
            'external_history_complete' => '1',
        ];
    }
}
