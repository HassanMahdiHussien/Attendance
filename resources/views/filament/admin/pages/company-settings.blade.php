<x-filament-panels::page>
    @foreach ($this->needsReviewLanguages() as $language)
        <p role="alert" style="color:#b45309">ترويسة {{ $language }} تحتاج إلى مراجعة قبل إصدار المستندات.</p>
    @endforeach
    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}
        <x-filament::button type="submit">حفظ الإعدادات</x-filament::button>
    </form>
</x-filament-panels::page>
