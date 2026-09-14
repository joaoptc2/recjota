@props(['href' => null, 'active' => false, 'icon' => null, 'soon' => false])

@php
    $base = 'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition';
    $state = $soon
        ? 'cursor-not-allowed text-slate-400 dark:text-slate-600'
        : ($active
            ? 'bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-300'
            : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800');
@endphp

@if ($soon)
    <span class="{{ $base }} {{ $state }}" aria-disabled="true" title="Disponível em uma fase seguinte">
        @if ($icon)<span class="shrink-0" aria-hidden="true">{!! $icon !!}</span>@endif
        <span class="truncate">{{ $slot }}</span>
        <span class="ml-auto text-[10px] uppercase tracking-wide">em breve</span>
    </span>
@else
    <a href="{{ $href }}" @if ($active) aria-current="page" @endif class="{{ $base }} {{ $state }}">
        @if ($icon)<span class="shrink-0" aria-hidden="true">{!! $icon !!}</span>@endif
        <span class="truncate">{{ $slot }}</span>
    </a>
@endif
