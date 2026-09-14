@props(['classes' => 'bg-slate-100 text-slate-600 ring-slate-500/20 dark:bg-slate-500/10 dark:text-slate-300 dark:ring-slate-400/30'])

<span {{ $attributes->merge(['class' => 'badge '.$classes]) }}>{{ $slot }}</span>
