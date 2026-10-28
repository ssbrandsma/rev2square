@props(['user', 'size' => 'md'])

@php
$classes = match ($size) {
    'xs' => 'h-6 w-6 text-[10px]',
    'sm' => 'h-8 w-8 text-xs',
    'lg' => 'h-20 w-20 text-2xl',
    'xl' => 'h-24 w-24 text-3xl',
    default => 'h-10 w-10 text-sm',
};
@endphp

@if($user->avatarUrl())
    <img src="{{ $user->avatarUrl() }}" alt="" {{ $attributes->merge(['class' => $classes.' shrink-0 rounded-full object-cover']) }}>
@else
    <span {{ $attributes->merge(['class' => $classes.' flex shrink-0 items-center justify-center rounded-full bg-emerald-700 font-bold text-white']) }} aria-hidden="true">{{ $user->initials() }}</span>
@endif
