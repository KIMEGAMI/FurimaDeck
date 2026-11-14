<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex min-h-11 items-center justify-center rounded-xl border border-transparent bg-red-700 px-5 py-3 text-sm font-bold text-white transition hover:bg-red-800 focus:outline-none focus:ring-2 focus:ring-red-700 focus:ring-offset-2 active:bg-red-900 disabled:cursor-not-allowed disabled:opacity-60']) }}>
    {{ $slot }}
</button>
