<x-app-layout>
    <div class="min-h-screen bg-slate-100 py-8">
        <div class="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">
            <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p class="text-sm font-black text-blue-700">ADMIN</p>
                    <h1 class="mt-2 text-3xl font-black text-slate-950">一斉メール送信</h1>
                    <p class="mt-2 text-sm font-bold text-slate-700">
                        登録ユーザーへ個別にメールを送信します。受信者同士のメールアドレスは表示されません。
                    </p>
                </div>

                <a href="{{ route('admin.users.index') }}" class="inline-flex w-fit items-center justify-center rounded-xl bg-slate-900 px-5 py-3 text-sm font-black text-white hover:bg-slate-800">
                    ユーザー一覧へ戻る
                </a>
            </div>

            @if (session('status'))
                <div class="mb-5 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-bold text-emerald-800">
                    {{ session('status') }}
                </div>
            @endif

            @if (session('error'))
                <div class="mb-5 rounded-2xl border border-red-200 bg-red-50 p-4 text-sm font-bold text-red-800">
                    {{ session('error') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="mb-5 rounded-2xl border border-red-200 bg-red-50 p-4 text-sm font-bold text-red-800">
                    入力内容を確認してください。
                </div>
            @endif

            <section class="rounded-3xl bg-white p-6 shadow-xl">
                <div class="grid gap-4 sm:grid-cols-3">
                    <div class="rounded-2xl bg-slate-50 p-4">
                        <p class="text-xs font-black tracking-widest text-slate-500">送信対象</p>
                        <p class="mt-2 text-2xl font-black text-slate-950">{{ number_format($recipientCount) }}件</p>
                    </div>
                    <div class="rounded-2xl bg-slate-50 p-4 sm:col-span-2">
                        <p class="text-xs font-black tracking-widest text-slate-500">送信元</p>
                        <p class="mt-2 break-all text-sm font-black text-slate-950">{{ $fromName }} &lt;{{ $fromAddress }}&gt;</p>
                    </div>
                </div>

                <form method="POST" action="{{ route('admin.bulk-mail.store') }}" class="mt-6 space-y-5" data-confirm="登録ユーザー全員へ一斉メールを送信します。実行しますか？">
                    @csrf

                    <div>
                        <label for="subject" class="block text-sm font-black text-slate-700">件名</label>
                        <input
                            id="subject"
                            name="subject"
                            type="text"
                            value="{{ old('subject') }}"
                            maxlength="{{ config('admin_mail.max_subject_length') }}"
                            class="mt-2 w-full rounded-lg border-slate-300 text-slate-950 shadow-sm focus:border-cyan-600 focus:ring-cyan-600"
                            required
                        >
                        <x-input-error :messages="$errors->get('subject')" class="mt-2" />
                    </div>

                    <div>
                        <label for="body" class="block text-sm font-black text-slate-700">本文</label>
                        <textarea
                            id="body"
                            name="body"
                            rows="12"
                            maxlength="{{ config('admin_mail.max_body_length') }}"
                            class="mt-2 w-full rounded-lg border-slate-300 text-slate-950 shadow-sm focus:border-cyan-600 focus:ring-cyan-600"
                            required
                        >{{ old('body') }}</textarea>
                        <x-input-error :messages="$errors->get('body')" class="mt-2" />
                    </div>

                    <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm font-bold leading-7 text-amber-900">
                        送信前に件名と本文を必ず確認してください。送信後の取り消しはできません。
                    </div>

                    <button type="submit" class="inline-flex w-full items-center justify-center rounded-xl bg-cyan-700 px-5 py-3 text-sm font-black text-white shadow hover:bg-cyan-800 sm:w-auto">
                        一斉メールを送信
                    </button>
                </form>
            </section>
        </div>
    </div>
</x-app-layout>
