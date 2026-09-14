@props(['title', 'action' => null, 'href' => null])

{{-- Estado vazio sempre com o próximo passo, nunca só "nenhum registro" (Seção 9.1). --}}
<div class="flex flex-col items-center justify-center gap-2 px-6 py-10 text-center">
    <p class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ $title }}</p>
    <p class="max-w-sm text-sm text-slate-500 dark:text-slate-400">{{ $slot }}</p>

    @if ($action && $href)
        <a href="{{ $href }}" class="btn-primary mt-2">{{ $action }}</a>
    @endif
</div>
