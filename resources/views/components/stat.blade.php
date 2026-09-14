@props(['label', 'value', 'hint' => null, 'tone' => 'default'])

@php
    $tones = [
        'default' => 'text-slate-900 dark:text-slate-50',
        'alert' => 'text-rose-600 dark:text-rose-400',
        'warn' => 'text-amber-600 dark:text-amber-400',
        'good' => 'text-emerald-600 dark:text-emerald-400',
    ];
@endphp

<div class="card p-4">
    <p class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ $label }}</p>
    <p class="mt-1 text-2xl font-semibold {{ $tones[$tone] }}">{{ $value }}</p>
    @if ($hint)
        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $hint }}</p>
    @endif
</div>
