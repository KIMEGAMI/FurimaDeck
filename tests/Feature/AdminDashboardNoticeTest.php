<?php

namespace Tests\Feature;

use App\Models\Notice;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDashboardNoticeTest extends TestCase
{
    use RefreshDatabase;

    public function test_maintenance_page_does_not_show_retry_estimate_text(): void
    {
        SystemSetting::putValue(SystemSetting::KEY_MAINTENANCE_ENABLED, '1'); // nosemgrep: php.lang.security.exec-use.exec-use -- test fixture database write only.

        $response = $this->get('/dashboard');

        $response->assertStatus(503);
        $response->assertDontSee('目安:', false);
        $response->assertDontSee('分後に再度お試しください', false);
    }

    public function test_admin_can_view_dashboard_during_maintenance_mode(): void
    {
        SystemSetting::putValue(SystemSetting::KEY_MAINTENANCE_ENABLED, '1'); // nosemgrep: php.lang.security.exec-use.exec-use -- test fixture database write only.

        $admin = User::factory()->create([
            'is_admin' => true,
        ]);

        $this
            ->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk();
    }

    public function test_regular_user_cannot_view_dashboard_during_maintenance_mode(): void
    {
        SystemSetting::putValue(SystemSetting::KEY_MAINTENANCE_ENABLED, '1'); // nosemgrep: php.lang.security.exec-use.exec-use -- test fixture database write only.

        $user = User::factory()->create([
            'is_admin' => false,
        ]);

        $this
            ->actingAs($user)
            ->get(route('dashboard'))
            ->assertStatus(503);
    }

    public function test_admin_can_publish_dashboard_notice_and_user_can_open_detail_page(): void
    {
        $admin = User::factory()->create([
            'is_admin' => true,
        ]);
        $user = User::factory()->create();

        $this
            ->actingAs($admin)
            ->post(route('admin.notices.store'), [
                'title' => 'メンテナンス完了のお知らせ',
                'body' => '本日の更新作業が完了しました。',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.maintenance.index'));

        $notice = Notice::query()->firstOrFail();

        $dashboardResponse = $this
            ->actingAs($user)
            ->get(route('dashboard'));

        $dashboardResponse->assertOk();
        $dashboardResponse->assertSee('管理者からのお知らせ', false);
        $dashboardResponse->assertSee('メンテナンス完了のお知らせ', false);
        $dashboardResponse->assertDontSee('本日の更新作業が完了しました。', false);
        $dashboardResponse->assertSee(route('notices.show', $notice), false);

        $this
            ->actingAs($user)
            ->get(route('notices.show', $notice))
            ->assertOk()
            ->assertSee('メンテナンス完了のお知らせ', false)
            ->assertSee('本日の更新作業が完了しました。', false);
    }

    public function test_dashboard_shows_only_latest_five_notices(): void
    {
        $admin = User::factory()->create([
            'is_admin' => true,
        ]);
        $user = User::factory()->create();

        foreach (range(1, Notice::DASHBOARD_LIMIT + 1) as $index) {
            Notice::query()->create([
                'user_id' => $admin->id,
                'title' => 'お知らせ'.$index,
                'body' => '本文'.$index,
                'published_at' => now()->subMinutes(Notice::DASHBOARD_LIMIT + 1 - $index),
            ]);
        }

        $response = $this
            ->actingAs($user)
            ->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('お知らせ'.(Notice::DASHBOARD_LIMIT + 1), false);
        $response->assertSee('お知らせ2', false);
        $response->assertDontSee('お知らせ1', false);
        $response->assertSee(route('notices.index'), false);
    }

    public function test_notice_index_is_paginated(): void
    {
        $admin = User::factory()->create([
            'is_admin' => true,
        ]);
        $user = User::factory()->create();

        foreach (range(1, Notice::PAGE_SIZE + 1) as $index) {
            Notice::query()->create([
                'user_id' => $admin->id,
                'title' => 'ページ送りお知らせ'.$index,
                'body' => '本文'.$index,
                'published_at' => now()->subMinutes(Notice::PAGE_SIZE + 1 - $index),
            ]);
        }

        $response = $this
            ->actingAs($user)
            ->get(route('notices.index'));

        $response->assertOk();
        $response->assertSee('ページ送りお知らせ'.(Notice::PAGE_SIZE + 1), false);
        $response->assertDontSee('>ページ送りお知らせ1<', false);
        $response->assertSee('page=2', false);
    }
}
