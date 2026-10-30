<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AdminBulkMailTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_bulk_mail_page(): void
    {
        $admin = User::factory()->create([
            'is_admin' => true,
        ]);

        $response = $this
            ->actingAs($admin)
            ->get(route('admin.bulk-mail.index'));

        $response
            ->assertOk()
            ->assertSee('一斉メール送信', false)
            ->assertSee(config('admin_mail.from_address'), false);
    }

    public function test_regular_user_cannot_view_bulk_mail_page(): void
    {
        $user = User::factory()->create([
            'is_admin' => false,
        ]);

        $this
            ->actingAs($user)
            ->get(route('admin.bulk-mail.index'))
            ->assertForbidden();
    }

    public function test_admin_can_send_bulk_mail_to_each_registered_user(): void
    {
        Mail::fake();
        config([
            'mail.default' => 'smtp',
            'admin_mail.from_address' => 'info@kimegami.jp',
        ]);

        $admin = User::factory()->create([
            'is_admin' => true,
        ]);
        User::factory()->count(2)->create();

        $response = $this
            ->actingAs($admin)
            ->post(route('admin.bulk-mail.store'), [
                'subject' => 'サービスからのお知らせ',
                'body' => 'FURUGI MANAGERからのお知らせです。',
            ]);

        $response
            ->assertRedirect(route('admin.bulk-mail.index'))
            ->assertSessionHas('status');

        Mail::assertSentCount(3);
    }
}
