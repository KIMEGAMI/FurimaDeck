@php($pageSeo = config('seo.pages')['legal.privacy'] ?? [])

<x-legal-layout title="プライバシーポリシー" eyebrow="PRIVACY" :description="$pageSeo['description']">
    <h2>1. 取得する情報</h2>
    <p>当サービスは、アカウント登録時の氏名または表示名、メールアドレス、ログイン認証に必要な情報を取得します。Googleログインを選択した場合は、認証に必要なGoogleアカウント識別子、メールアドレス、表示名、メール確認状態を受け取ります。</p>
    <p>商品、商品画像、仕入先、仕入・在庫・販売・売上、会計データの準備に必要な情報、お問い合わせフォームに入力された氏名・メールアドレス・件名・本文を取得します。freeeまたはMoney Forwardとの連携を利用する場合は、連携用トークン、事業者情報、CSV設定に必要な勘定科目・税区分等の情報を取り扱います。</p>
    <p>アカウントの安全確保のため、ログイン日時、直近のログインIPアドレス、ブラウザ識別情報のハッシュ値、セッションCookie、操作記録、エラー記録を保存する場合があります。</p>

    <h2>2. 利用目的</h2>
    <p>取得した情報は、ログインと本人確認、商品・在庫・仕入・売上の管理、分析、会計サービス用CSVの作成、Premium機能の提供、決済・契約状態の確認、メール通知、お問い合わせ対応、不正利用防止、障害調査、サービスの維持・改善、法令対応のために利用します。</p>
    <p>利用者の同意なく、目的外利用は行いません。</p>

    <h2>3. 決済情報の取り扱い</h2>
    <p>Premiumプランの支払いはStripeの決済画面を通じて行います。当サービスは、クレジットカード番号、セキュリティコードなどの詳細な決済情報を直接保存しません。</p>
    <p>当サービスは、Stripeから提供される顧客ID、契約ID、契約状態、請求期間など、契約管理に必要な情報を保存する場合があります。</p>

    <h2>4. 外部サービスとの通信・委託</h2>
    <ul>
        <li>Googleログインを選択すると、Googleの認証画面と通信します。Googleから受け取る情報はログインに使用します。<a href="https://policies.google.com/privacy?hl=ja" target="_blank" rel="noopener noreferrer" class="text-cyan-800 underline hover:text-cyan-950">Googleのプライバシーポリシー</a></li>
        <li>Premiumの申込み・支払いにはStripeの決済画面を使用します。カード番号等はStripeの画面で入力され、当サービスでは直接保存しません。当サービスでは契約管理に必要な顧客ID、契約ID、契約状態、請求期間等を保存する場合があります。<a href="https://stripe.com/jp/privacy" target="_blank" rel="noopener noreferrer" class="text-cyan-800 underline hover:text-cyan-950">Stripeのプライバシーポリシー</a></li>
        <li>認証メール、パスワード再設定、問い合わせ受付などのメール配信にはResendを利用します。配信に必要な宛先メールアドレス、件名、本文がResendへ送信されます。ResendのDPAには主な処理拠点が米国と記載されているため、メールに含まれる情報が米国で処理される場合があります。<a href="https://resend.com/legal/privacy-policy" target="_blank" rel="noopener noreferrer" class="text-cyan-800 underline hover:text-cyan-950">Resendのプライバシーポリシー</a>・<a href="https://resend.com/legal/dpa" target="_blank" rel="noopener noreferrer" class="text-cyan-800 underline hover:text-cyan-950">データ処理契約（DPA）</a>・<a href="https://resend.com/legal/subprocessors" target="_blank" rel="noopener noreferrer" class="text-cyan-800 underline hover:text-cyan-950">委託先一覧</a></li>
        <li>商品画像は、環境ごとに設定された保存先へ保存します。Cloudflare R2を保存先に設定した場合、画像ファイルがCloudflare R2に保存され、アプリの認可を通じて配信されます。<a href="https://www.cloudflare.com/ja-jp/privacypolicy/" target="_blank" rel="noopener noreferrer" class="text-cyan-800 underline hover:text-cyan-950">Cloudflareのプライバシーポリシー</a></li>
        <li>freeeまたはMoney Forwardとの連携は利用者が選択した場合に行います。連携時はOAuth認証を行い、CSV設定に必要な情報を読み取ります。連携トークンは暗号化して保存します。FurimaDeck 1.0では会計仕訳のAPI送信を行いません。<a href="https://www.freee.co.jp/privacy_policy/" target="_blank" rel="noopener noreferrer" class="text-cyan-800 underline hover:text-cyan-950">freeeのプライバシーポリシー</a>・<a href="https://corp.moneyforward.com/privacy/" target="_blank" rel="noopener noreferrer" class="text-cyan-800 underline hover:text-cyan-950">Money Forwardのプライバシーポリシー</a></li>
        <li>一部の画面では、jQueryをcode.jquery.comから、グラフ表示用のChart.jsをjsDelivrから読み込みます。ブラウザがこれらの配信元へ接続する際、通信に必要な情報が送られます。</li>
    </ul>
    <p>当サービスは、法令に基づく場合または利用者の同意を得た場合を除き、個人情報を第三者へ提供しません。サービス運営に必要な業務を外部事業者へ委託する場合は、その目的に必要な範囲で情報を取り扱います。</p>

    <h2>5. 安全管理</h2>
    <p>当サービスは、不正アクセス、漏えい、改ざん、紛失を防ぐため、必要かつ適切な安全管理措置を講じます。</p>
    <p>パスワード、APIキー、シークレットなどの機密情報をお問い合わせフォームに入力しないでください。</p>

    <h2>6. Cookie・ログ</h2>
    <p>当サービスは、ログイン状態の維持、CSRF対策、セキュリティのためセッションCookieを使用します。ブラウザ設定によりCookieを無効化できますが、その場合は一部機能を利用できないことがあります。アクセス解析を目的とした外部トラッキングタグは、現行のアプリ画面では使用していません。</p>

    <h2>7. データの保管と削除</h2>
    <p>アカウント削除時には、関連する登録データや商品画像を削除する設計です。ただし、法令対応、不正利用調査、障害調査、バックアップ運用上必要な情報は一定期間保持する場合があります。</p>

    <h2>8. 開示・訂正・削除</h2>
    <p>利用者本人から個人情報の開示、訂正、利用停止、削除の依頼があった場合、本人確認のうえ、法令に従い対応します。</p>

    <h2>9. お問い合わせ</h2>
    <p>個人情報の取り扱いに関する質問や削除依頼は、<a href="{{ route('legal.contact') }}" class="text-cyan-800 underline hover:text-cyan-950">お問い合わせフォーム</a>からご連絡ください。</p>

    <h2>10. 改定</h2>
    <p>本ポリシーを変更する場合、当サービス上での表示その他適切な方法により通知します。</p>

</x-legal-layout>
