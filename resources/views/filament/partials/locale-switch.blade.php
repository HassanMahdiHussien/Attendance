<form method="POST" action="{{ route('locale.update') }}" class="flex items-center justify-center gap-2 pt-4">
    @csrf
    <label for="filament-locale" class="text-sm">{{ __('Language') }}</label>
    <select id="filament-locale" name="locale" class="rounded-lg border border-gray-300 bg-white px-2 py-1 text-sm dark:border-gray-700 dark:bg-gray-900" onchange="this.form.submit()">
        <option value="en" @selected(app()->getLocale() === 'en')>{{ __('English') }}</option>
        <option value="ar" @selected(app()->getLocale() === 'ar')>{{ __('Arabic') }}</option>
    </select>
    <noscript><button type="submit">{{ __('Switch language') }}</button></noscript>
</form>
