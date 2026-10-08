@props(['title', 'text' => null, 'icon' => 'folder'])
<div {{ $attributes->class(['px-5 py-10 text-center']) }}>
    <span class="mx-auto mb-3 grid h-11 w-11 place-items-center rounded-2xl bg-primary-light text-primary"><x-ui.icon :name="$icon" class="h-5 w-5" /></span>
    <p class="font-bold">{{ $title }}</p>
    @if($text)<p class="mt-1 text-sm text-gray-500">{{ $text }}</p>@endif
    @isset($action)<div class="mt-4">{{ $action }}</div>@endisset
</div>
