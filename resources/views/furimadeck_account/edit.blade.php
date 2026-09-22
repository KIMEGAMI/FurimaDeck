<x-app-layout>
    <x-slot name="header"><h2 class="text-2xl font-black text-cyan-200">アカウント</h2></x-slot>

    <div class="min-h-screen bg-slate-100 py-8">
        <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
            @if (session('status') === 'profile-updated')
                <p class="mb-4 rounded bg-emerald-50 p-4 font-bold text-emerald-800">アカウント情報を更新しました。</p>
            @endif            @if (session('status') === 'data-deleted')
                <p class="mb-4 rounded bg-emerald-50 p-4 font-bold text-emerald-800">FurimaDeckのデータを削除しました。アカウント情報は残っています。</p>
            @endif            @if (session('status') === 'monthly-data-deleted')
                <p class="mb-4 rounded bg-emerald-50 p-4 font-bold text-emerald-800">指定月の売上関連データを削除しました。商品・出品情報は残っています。</p>
            @endif
            @if (session('verification_status') === 'verification-link-sent')
                <p class="mb-4 rounded bg-emerald-50 p-4 font-bold text-emerald-800">確認メールを送信しました。メール内のリンクを開いて認証を完了してください。</p>
            @elseif (session('verification_status') === 'verification-link-send-failed')
                <p class="mb-4 rounded bg-amber-50 p-4 font-bold text-amber-900">確認メールを送信できませんでした。認証画面から時間をおいて再送してください。</p>
            @endif

            <section class="rounded bg-white p-6 shadow">
                <div class="mb-6 rounded border border-emerald-200 bg-emerald-50 p-5">
                    <p class="text-sm font-black text-emerald-900">累計売上</p>
                    <p class="mt-2 text-2xl font-black text-slate-950">¥{{ number_format($salesTotal) }}</p>
                    <p class="mt-1 text-xs font-bold text-emerald-800">キャンセル・返品を除く販売確定分</p>
                </div>
                <form method="POST" action="{{ route('furimadeck-account.update') }}" class="space-y-5">
                    @csrf
                    @method('patch')
                    <div>
                        <label for="name" class="block text-sm font-bold text-slate-800">表示名</label>
                        <input id="name" name="name" type="text" value="{{ old('name', $user->name) }}" required maxlength="255" class="mt-2 w-full rounded border-slate-300 text-slate-900 shadow-sm">
                        @error('name')<p class="mt-2 text-sm font-bold text-red-700">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="email" class="block text-sm font-bold text-slate-800">メールアドレス</label>
                        <input id="email" name="email" type="email" value="{{ old('email', $user->email) }}" required maxlength="255" class="mt-2 w-full rounded border-slate-300 text-slate-900 shadow-sm">
                        @error('email')<p class="mt-2 text-sm font-bold text-red-700">{{ $message }}</p>@enderror
                    </div>
                    <button class="rounded bg-cyan-600 px-4 py-2 font-black text-white hover:bg-cyan-700">更新</button>
                </form>
            </section>
            <section class="mt-6 rounded bg-white p-6 shadow">
                <h3 class="text-lg font-black text-slate-900">パスワード変更</h3>
                <form method="POST" action="{{ route('password.update') }}" class="mt-4 space-y-4">
                    @csrf
                    @method('put')
                    <div>
                        <label for="current-password" class="block text-sm font-bold text-slate-800">現在のパスワード</label>
                        <input id="current-password" name="current_password" type="password" autocomplete="current-password" required class="mt-2 w-full rounded border-slate-300 text-slate-900 shadow-sm">
                        @error('current_password', 'updatePassword')<p class="mt-2 text-sm font-bold text-red-700">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="new-password" class="block text-sm font-bold text-slate-800">新しいパスワード</label>
                        <input id="new-password" name="password" type="password" autocomplete="new-password" required class="mt-2 w-full rounded border-slate-300 text-slate-900 shadow-sm">
                        @error('password', 'updatePassword')<p class="mt-2 text-sm font-bold text-red-700">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="new-password-confirmation" class="block text-sm font-bold text-slate-800">新しいパスワード（確認）</label>
                        <input id="new-password-confirmation" name="password_confirmation" type="password" autocomplete="new-password" required class="mt-2 w-full rounded border-slate-300 text-slate-900 shadow-sm">
                    </div>
                    <button class="rounded bg-slate-800 px-4 py-2 font-black text-white hover:bg-slate-900">パスワードを変更</button>
                    @if (session('status') === 'password-updated')<p class="text-sm font-bold text-emerald-800">パスワードを変更しました。</p>@endif
                </form>
            </section>
            <section class="mt-6 rounded border border-amber-200 bg-amber-50 p-6">
                <h3 class="text-lg font-black text-amber-900">FurimaDeckデータの全削除</h3>
                <p class="mt-2 text-sm font-bold text-amber-900">商品、画像、出品、販売、CSV取込履歴、会計データなど、このアカウントのFurimaDeckデータだけを削除します。アカウント自体と契約情報は削除しません。</p>
                <a href="{{ route('furimadeck-account.data-delete.confirm') }}" class="mt-4 inline-flex rounded bg-amber-700 px-4 py-2 font-black text-white hover:bg-amber-800">データ全削除の確認へ</a>                <a href="{{ route('furimadeck-account.monthly-data-delete.confirm', ['month' => now()->format('Y-m')]) }}" class="mt-4 ml-2 inline-flex rounded border border-amber-700 bg-white px-4 py-2 font-black text-amber-900 hover:bg-amber-100">期間（月）の削除へ</a>
            </section>            <section class="mt-6 rounded border border-red-200 bg-red-50 p-6">
                <h3 class="text-lg font-black text-red-900">アカウント削除</h3>
                <form method="POST" action="{{ route('furimadeck-account.destroy') }}" class="mt-4 space-y-4">
                    @csrf
                    @method('delete')
                    <div>
                        <label for="delete-password" class="block text-sm font-bold text-red-900">現在のパスワード</label>
                        <input id="delete-password" name="password" type="password" required class="mt-2 w-full rounded border-red-300 text-slate-900 shadow-sm">
                        @error('password', 'userDeletion')<p class="mt-2 text-sm font-bold text-red-700">{{ $message }}</p>@enderror
                    </div>
                    <label class="flex items-start gap-2 text-sm font-bold text-red-900"><input name="confirm_deletion" value="1" type="checkbox" required class="mt-1">商品、出品、販売、画像を削除することを確認しました。</label>
                    @error('confirm_deletion', 'userDeletion')<p class="text-sm font-bold text-red-700">{{ $message }}</p>@enderror
                    <button class="rounded bg-red-700 px-4 py-2 font-black text-white hover:bg-red-800">アカウントを削除</button>
                </form>
            </section>
        </div>
    </div>
</x-app-layout>
