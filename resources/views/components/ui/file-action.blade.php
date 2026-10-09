@props(['item', 'items' => null, 'index' => 0, 'showDownload' => true])
<span {{ $attributes->class(['inline-flex flex-wrap items-center gap-2']) }}>
    @if($item['view_url'])
        <x-ui.button variant="secondary" size="small" data-file-list="{{ json_encode($items ?? [$item]) }}" x-on:click="$dispatch('open-file', { items: JSON.parse($el.dataset.fileList), index: {{ $index }} })">{{ __('View') }}</x-ui.button>
    @endif
    @if($showDownload && $item['download_url'])
        <x-ui.button variant="ghost" size="small" :href="$item['download_url']">{{ __('Download') }}</x-ui.button>
    @endif
</span>
