<?php

namespace App\Http\Controllers;

use App\Models\ContactInquiry;
use App\Services\NgWordFilter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class ContactController extends Controller
{
    private const UNSAFE_MAILERS = ['log', 'array'];

    public function create(): View
    {
        return view('legal.contact');
    }

    public function store(Request $request, NgWordFilter $ngWordFilter): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:'.config('contact.max_name_length')],
            'email' => ['required', 'email:rfc', 'max:255'],
            'subject' => ['required', 'string', 'max:'.config('contact.max_subject_length')],
            'message' => ['required', 'string', 'max:'.config('contact.max_message_length')],
        ]);
        $validated['message'] = $this->normalizeLineBreaks($validated['message']);

        if ($this->containsNgWord($validated, $ngWordFilter)) {
            throw ValidationException::withMessages([
                'message' => '不適切な表現が含まれています。内容を見直してから送信してください。',
            ]);
        }

        if ($this->canSendMail()) {
            try {
                Mail::send('emails.contact', ['contact' => $validated], function ($message) use ($validated): void {
                    $message
                        ->to(config('contact.to_address'), config('contact.to_name'))
                        ->replyTo($validated['email'], $validated['name'])
                        ->subject(config('contact.subject_prefix').': '.$validated['subject']);
                });
            } catch (Throwable $exception) {
                Log::warning('Contact mail notification failed.', [
                    'error_class' => $exception::class,
                ]);

                return back()
                    ->withErrors([
                        'message' => 'お問い合わせを送信できませんでした。時間をおいて再度お試しください。',
                    ])
                    ->withInput($validated);
            }
        }

        ContactInquiry::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'subject' => $validated['subject'],
            'message' => $validated['message'],
            'status' => ContactInquiry::STATUS_OPEN,
        ]);

        return back()
            ->with('success', 'お問い合わせを受け付けました。内容を確認し、必要に応じて返信します。')
            ->withInput([]);
    }

    /**
     * @param  array{name: string, email: string, subject: string, message: string}  $input
     */
    private function containsNgWord(array $input, NgWordFilter $ngWordFilter): bool
    {
        return $ngWordFilter->contains($input['name'])
            || $ngWordFilter->contains($input['subject'])
            || $ngWordFilter->contains($input['message']);
    }

    private function canSendMail(): bool
    {
        $toAddress = config('contact.to_address');
        $mailer = config('mail.default');

        return is_string($toAddress)
            && $toAddress !== ''
            && is_string($mailer)
            && ! in_array($mailer, self::UNSAFE_MAILERS, true);
    }

    private function normalizeLineBreaks(string $value): string
    {
        return str_replace(["\r\n", "\r"], "\n", $value);
    }
}
