@php($pageSeo = config('seo.pages')['legal.contact'] ?? [])

<x-legal-layout
    title="お問い合わせ"
    eyebrow="CONTACT"
    :description="$pageSeo['description']"
>
    <h2>お問い合わせフォーム</h2>
    <p>FurimaDeckの使い方、不具合、アカウント、個人情報の取り扱いに関するお問い合わせを受け付けています。</p>
    <p>状況が分かるように、発生した画面、操作内容、表示されたエラーの概要を添えてください。パスワード、APIキー、決済カード番号などの機密情報は入力しないでください。</p>

    <form method="POST" action="{{ route('legal.contact.store') }}" class="not-prose mt-8 space-y-6 rounded-lg border border-slate-200 bg-slate-50 p-5">
        @csrf

        @if (session('status'))
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm font-bold text-emerald-800">
                {{ session('status') }}
            </div>
        @endif

        <div>
            <label for="name" class="block text-sm font-black text-slate-700">お名前</label>
            <input id="name" name="name" type="text" value="{{ old('name') }}" maxlength="{{ config('contact.max_name_length') }}" class="mt-2 w-full rounded-lg border-slate-300 text-slate-950 shadow-sm focus:border-cyan-600 focus:ring-cyan-600" required>
            <x-input-error :messages="$errors->get('name')" class="mt-2" />
        </div>

        <div>
            <label for="email" class="block text-sm font-black text-slate-700">メールアドレス</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" maxlength="{{ config('contact.max_email_length') }}" class="mt-2 w-full rounded-lg border-slate-300 text-slate-950 shadow-sm focus:border-cyan-600 focus:ring-cyan-600" required>
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <div>
            <label for="subject" class="block text-sm font-black text-slate-700">件名</label>
            <input id="subject" name="subject" type="text" value="{{ old('subject') }}" maxlength="{{ config('contact.max_subject_length') }}" class="mt-2 w-full rounded-lg border-slate-300 text-slate-950 shadow-sm focus:border-cyan-600 focus:ring-cyan-600" required>
            <x-input-error :messages="$errors->get('subject')" class="mt-2" />
        </div>

        <div>
            <label for="message" class="block text-sm font-black text-slate-700">お問い合わせ内容</label>
            <textarea id="message" name="message" rows="7" maxlength="{{ config('contact.max_message_length') }}" class="mt-2 w-full rounded-lg border-slate-300 text-slate-950 shadow-sm focus:border-cyan-600 focus:ring-cyan-600" required>{{ old('message') }}</textarea>
            <x-input-error :messages="$errors->get('message')" class="mt-2" />
        </div>

        <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm font-bold text-amber-900">
            暴言、脅迫、差別的な表現、性的な嫌がらせ、スパムを含む内容は送信できません。
        </div>

        <button type="submit" class="rounded-md bg-cyan-700 px-6 py-3 text-sm font-black text-white hover:bg-cyan-800">送信する</button>
    </form>

    <h2>返信目安</h2>
    <p>内容を確認し、必要に応じて順次返信します。障害対応や詳細な調査が必要な場合は、返信まで時間がかかることがあります。</p>
</x-legal-layout>
