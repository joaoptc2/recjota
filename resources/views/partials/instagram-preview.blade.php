@props(['type', 'account', 'media', 'caption', 'truncated'])

@php
    $handle = $account?->username ?? 'suaconta';
    $limite = config('agency.limits.caption_truncate_at');
    $primeira = $media->first();
    $legendaCurta = $truncated ? mb_substr($caption, 0, $limite) : $caption;
@endphp

{{-- Preview fiel: moldura, tipografia e o ponto de corte reais do Instagram. --}}
<div class="mx-auto w-full max-w-[380px]">
    @if ($type->value === 'story')
        <div class="relative overflow-hidden rounded-[28px] border-[6px] border-slate-900 bg-slate-950 shadow-xl dark:border-slate-700">
            <div class="relative aspect-[9/16] bg-slate-800">
                @if ($primeira)
                    <img src="{{ $primeira->thumbUrl() }}" alt="" class="absolute inset-0 size-full object-cover">
                @endif
                <div class="absolute inset-x-3 top-3 h-0.5 rounded bg-white/60"></div>
                <div class="absolute inset-x-0 top-6 flex items-center gap-2 px-3">
                    <span class="flex size-7 items-center justify-center rounded-full bg-gradient-to-tr from-amber-400 to-fuchsia-600 text-[10px] font-bold text-white">
                        {{ mb_substr($handle, 0, 1) }}
                    </span>
                    <span class="text-xs font-semibold text-white drop-shadow">{{ $handle }}</span>
                </div>
            </div>
        </div>
        <p class="mt-3 text-center text-xs text-slate-500 dark:text-slate-400">Stories não têm legenda.</p>

    @elseif ($type->value === 'reel')
        <div class="relative overflow-hidden rounded-[28px] border-[6px] border-slate-900 bg-slate-950 shadow-xl dark:border-slate-700">
            <div class="relative aspect-[9/16] bg-slate-800">
                @if ($primeira)
                    <img src="{{ $primeira->thumbUrl() }}" alt="" class="absolute inset-0 size-full object-cover">
                @endif
                <div class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/80 to-transparent p-3">
                    <p class="text-xs font-semibold text-white">{{ $handle }}</p>
                    <p class="mt-1 line-clamp-2 text-[11px] leading-snug text-white/90">{{ $legendaCurta }}</p>
                </div>
            </div>
        </div>

    @else
        <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div class="flex items-center gap-2 px-3 py-2.5">
                <span class="flex size-8 items-center justify-center rounded-full bg-gradient-to-tr from-amber-400 to-fuchsia-600 text-[11px] font-bold text-white">
                    {{ mb_substr($handle, 0, 1) }}
                </span>
                <span class="text-[13px] font-semibold">{{ $handle }}</span>
            </div>

            <div class="relative aspect-square bg-slate-100 dark:bg-slate-800">
                @if ($primeira)
                    <img src="{{ $primeira->thumbUrl() }}" alt="" class="absolute inset-0 size-full object-cover">
                @else
                    <div class="flex h-full items-center justify-center text-xs text-slate-400">sem mídia</div>
                @endif

                @if ($media->count() > 1)
                    <span class="absolute right-2.5 top-2.5 rounded-full bg-black/60 px-2 py-0.5 text-[11px] font-medium text-white">
                        1/{{ $media->count() }}
                    </span>
                @endif
            </div>

            @if ($media->count() > 1)
                <div class="flex justify-center gap-1 py-2">
                    @foreach ($media as $i => $item)
                        <span class="size-1.5 rounded-full {{ $i === 0 ? 'bg-sky-500' : 'bg-slate-300 dark:bg-slate-600' }}"></span>
                    @endforeach
                </div>
            @endif

            <div class="px-3 pb-3 pt-1 text-[13px] leading-snug">
                <span class="font-semibold">{{ $handle }}</span>
                <span class="whitespace-pre-line">{{ ' '.$legendaCurta }}</span>
                @if ($truncated)
                    {{-- Este é o ponto exato em que o feed corta. --}}
                    <span class="text-slate-400">… mais</span>
                @endif
            </div>
        </div>
    @endif
</div>
