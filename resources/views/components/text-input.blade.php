@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'min-h-11 rounded-xl border-slate-300 bg-white text-slate-900 placeholder:text-slate-500 shadow-sm focus:border-blue-700 focus:ring-blue-700 disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-600']) }}>
