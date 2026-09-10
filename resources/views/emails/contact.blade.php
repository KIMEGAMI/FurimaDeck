@php
    $messageText = str_replace(["\r\n", "\r"], "\n", $contact['message']);
@endphp

<!doctype html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <title>FurimaDeck お問い合わせ</title>
</head>
<body style="margin:0; background:#f8fafc; color:#0f172a; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif; line-height:1.7;">
    <div style="max-width:680px; margin:0 auto; padding:24px;">
        <h1 style="margin:0 0 20px; font-size:20px; line-height:1.4;">FurimaDeckへのお問い合わせが届きました</h1>

        <div style="margin:0 0 18px; padding:16px; background:#ffffff; border:1px solid #e2e8f0; border-radius:8px;">
            <div style="margin:0 0 6px; color:#475569; font-size:13px; font-weight:700;">お名前</div>
            <div style="font-size:15px;">{{ $contact['name'] }}</div>
        </div>

        <div style="margin:0 0 18px; padding:16px; background:#ffffff; border:1px solid #e2e8f0; border-radius:8px;">
            <div style="margin:0 0 6px; color:#475569; font-size:13px; font-weight:700;">メールアドレス</div>
            <div style="font-size:15px;">{{ $contact['email'] }}</div>
        </div>

        <div style="margin:0 0 18px; padding:16px; background:#ffffff; border:1px solid #e2e8f0; border-radius:8px;">
            <div style="margin:0 0 6px; color:#475569; font-size:13px; font-weight:700;">件名</div>
            <div style="font-size:15px;">{{ $contact['subject'] }}</div>
        </div>

        <div style="margin:0; padding:16px; background:#ffffff; border:1px solid #e2e8f0; border-radius:8px;">
            <div style="margin:0 0 10px; color:#475569; font-size:13px; font-weight:700;">お問い合わせ内容</div>
            <div style="font-size:15px; line-height:1.8;">
                @foreach (explode("\n", $messageText) as $line)
                    {{ $line }}@if (! $loop->last)<br>@endif
                @endforeach
            </div>
        </div>
    </div>
</body>
</html>
