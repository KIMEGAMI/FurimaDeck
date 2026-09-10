<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\View;
use RuntimeException;
use Tests\TestCase;

class ContactFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_contact_form_can_be_rendered(): void
    {
        $response = $this->get('/contact');

        $response->assertOk();
        $response->assertSee('お問い合わせフォーム', false);
        $response->assertSee('name="message"', false);
    }

    public function test_contact_form_remains_available_after_furimadeck_cutover(): void
    {
        config()->set('furimadeck.cutover_enabled', true);

        $response = $this->get('/contact');

        $response->assertOk();
        $response->assertSee('お問い合わせフォーム', false);
    }

    public function test_contact_form_rejects_ng_words(): void
    {
        $response = $this->from('/contact')->post('/contact', [
            'name' => 'Test User',
            'email' => 'sender@example.com',
            'subject' => '確認',
            'message' => '死ね',
        ]);

        $response->assertRedirect('/contact');
        $response->assertSessionHasErrors('message');
    }

    public function test_contact_form_accepts_clean_message_without_logging_mail(): void
    {
        Mail::fake();

        $response = $this->from('/contact')->post('/contact', [
            'name' => 'Test User',
            'email' => 'sender@example.com',
            'subject' => '使い方について',
            'message' => '商品登録の使い方を確認したいです。',
        ]);

        $response->assertRedirect('/contact');
        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('success');
        Mail::assertNothingSent();
        $this->assertDatabaseHas('contact_inquiries', [
            'email' => 'sender@example.com',
            'status' => 'open',
        ]);
    }

    public function test_contact_mail_keeps_message_line_breaks_and_escapes_content(): void
    {
        $html = View::make('emails.contact', [
            'contact' => [
                'name' => 'Test User',
                'email' => 'sender@example.com',
                'subject' => '改行確認',
                'message' => "1行目\n2行目\n\n<script>alert('xss')</script>",
            ],
        ])->render();

        $this->assertStringContainsString('1行目<br>', $html);
        $this->assertStringContainsString('2行目<br>', $html);
        $this->assertMatchesRegularExpression('/<br>\s*&lt;script&gt;/', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(&#039;xss&#039;)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString("<script>alert('xss')</script>", $html);
    }

    public function test_contact_form_shows_error_when_mail_transport_fails(): void
    {
        config([
            'contact.to_address' => 'admin@example.com',
            'mail.default' => 'smtp',
        ]);

        Mail::shouldReceive('send')
            ->once()
            ->andThrow(new RuntimeException('smtp unavailable'));

        $response = $this->from('/contact')->post('/contact', [
            'name' => 'Test User',
            'email' => 'sender@example.com',
            'subject' => 'SMTP確認',
            'message' => "1行目\r\n2行目",
        ]);

        $response->assertRedirect('/contact');
        $response->assertSessionHasErrors([
            'message' => 'お問い合わせを送信できませんでした。時間をおいて再度お試しください。',
        ]);
        $response->assertSessionMissing('success');
        $this->assertDatabaseMissing('contact_inquiries', [
            'email' => 'sender@example.com',
            'subject' => 'SMTP確認',
        ]);
    }
}
