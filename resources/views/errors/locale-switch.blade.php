<form method="POST" action="{{ route('locale.update') }}">
    @csrf
    <input type="hidden" name="locale" value="{{ app()->getLocale() === 'ar' ? 'en' : 'ar' }}">
    <button type="submit" style="border: 0; background: transparent; color: #A9B6C9; cursor: pointer; font: inherit;" lang="{{ app()->getLocale() === 'ar' ? 'en' : 'ar' }}">{{ app()->getLocale() === 'ar' ? __('English') : trans('messages.locale.arabic', [], 'ar') }}</button>
</form>
