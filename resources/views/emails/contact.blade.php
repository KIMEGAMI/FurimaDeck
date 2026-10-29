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

        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 20px; border:1px solid #cbd5e1; border-collapse:collapse; background:#ffffff;">
            <tr>
                <th align="left" width="150" style="padding:12px; border-bottom:1px solid #e2e8f0; background:#f1f5f9; color:#334155; font-size:13px;">お名前</th>
                <td style="padding:12px; border-bottom:1px solid #e2e8f0; font-size:15px; word-break:break-word;">{{ $contact['name'] }}</td>
            </tr>
            <tr>
                <th align="left" style="padding:12px; border-bottom:1px solid #e2e8f0; background:#f1f5f9; color:#334155; font-size:13px;">メールアドレス</th>
                <td style="padding:12px; border-bottom:1px solid #e2e8f0; font-size:15px; word-break:break-all;">{{ $contact['email'] }}</td>
            </tr>
            <tr>
                <th align="left" style="padding:12px; background:#f1f5f9; color:#334155; font-size:13px;">件名</th>
                <td style="padding:12px; font-size:15px; word-break:break-word;">{{ $contact['subject'] }}</td>
            </tr>
        </table>

        <div style="margin:0; border:1px solid #cbd5e1; background:#ffffff;">
            <div style="padding:12px; background:#f1f5f9; border-bottom:1px solid #e2e8f0; color:#334155; font-size:13px; font-weight:700;">お問い合わせ内容</div>
            <div style="padding:16px; font-size:15px; line-height:1.8; white-space:pre-wrap; word-break:break-word;">{{ $messageText }}</div>
        </div>
    </div>
</body>
</html>
