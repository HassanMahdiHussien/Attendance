<div @if($status['state'] === 'working') wire:poll.15s @endif>
    @if($status['state'] === 'working')
        <div role="status" class="border-b border-line bg-route-navy-wash px-4 py-2 text-center text-sm text-route-navy">
            {{ __('messages.processing_status.notice', ['step' => $status['step']]) }}
            @if($uploadUrl)<a class="ui-link ms-2" href="{{ $uploadUrl }}">{{ __('messages.processing_status.view_uploads') }}</a>@endif
        </div>
    @endif
</div>
