@props(['type' => 'info'])

@php
    $styles = [
        'info' => 'border-sky-200 bg-sky-50 text-sky-800 dark:border-sky-900 dark:bg-sky-950 dark:text-sky-200',
        'success' => 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-200',
        'error' => 'border-rose-200 bg-rose-50 text-rose-800 dark:border-rose-900 dark:bg-rose-950 dark:text-rose-200',
    ];
@endphp

<div {{ $attributes->merge(['class' => 'rounded-lg border px-4 py-3 text-sm '.$styles[$type]]) }} role="status">
    {{ $slot }}
</div>
