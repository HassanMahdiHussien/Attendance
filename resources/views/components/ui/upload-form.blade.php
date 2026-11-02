{{-- Livewire's wire:loading state for an upload is lost when a poll request on the same page is cancelled mid-upload,
     which re-enabled the submit button on slow links and submitted with no file. Track the upload in Alpine instead. --}}
<form x-data="{ uploading: false, progress: 0, stale: false }"
    x-on:livewire-upload-start="uploading = true; progress = 0; stale = true"
    x-on:livewire-upload-progress="progress = $event.detail.progress"
    x-on:livewire-upload-finish="uploading = false"
    x-on:livewire-upload-error="uploading = false; stale = false"
    x-on:livewire-upload-cancel="uploading = false; stale = false"
    x-on:submit.capture="if (uploading) { $event.preventDefault(); $event.stopImmediatePropagation(); } else { stale = false; }"
    {{ $attributes }}>
    {{ $slot }}
    <p x-show="uploading" x-cloak role="status" class="text-sm text-gray-600">{{ __('messages.upload_form.uploading') }} <bdi dir="ltr"><span x-text="progress"></span>%</bdi></p>
</form>
