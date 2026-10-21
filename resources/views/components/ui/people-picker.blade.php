@props(['kind', 'label', 'items', 'results', 'search' => '', 'free' => true, 'disabled' => false])
<div class="space-y-2" role="group" aria-label="{{ $label }}">
    <label for="people-{{ $kind }}" class="block text-sm font-medium text-ink">{{ $label }}</label>
    @if(count($items))
        <ul class="flex flex-wrap gap-2" aria-label="{{ __('Selected people') }}">
            @foreach($items as $index => $item)
                <li wire:key="person-{{ $kind }}-{{ $index }}" class="flex items-center gap-2 rounded border border-line bg-bg px-2 py-1 text-sm">
                    <span dir="auto">{{ $item['name'] }}</span>
                    @if(!$disabled)<button type="button" wire:click="removePerson('{{ $kind }}', {{ $index }})" aria-label="{{ __('Remove :name', ['name' => $item['name']]) }}" class="ui-link">&times;</button>@endif
                </li>
            @endforeach
        </ul>
    @endif
    @if(!$disabled)
        <input id="people-{{ $kind }}" type="search" wire:model.live.debounce.300ms="peopleSearch.{{ $kind }}" class="ui-control w-full" autocomplete="off" dir="auto" placeholder="{{ __('Search by name or employee number') }}" />
        @if(trim($search) !== '')
            <p role="status" aria-live="polite" class="text-xs text-gray-600">{{ __(':count matches', ['count' => count($results)]) }}</p>
            <ul class="max-h-56 overflow-y-auto rounded-lg border border-line bg-white" aria-label="{{ __('Search results') }}">
                @foreach($results as $person)
                    <li><button type="button" wire:click="addPerson('{{ $kind }}', {{ $person['id'] }})" class="w-full px-3 py-2 text-start text-sm hover:bg-primary-light focus:bg-primary-light"><span dir="auto">{{ $person['name'] }}</span> <span class="text-gray-500">{{ $person['name_en'] }} · <bdi>{{ $person['code'] }}</bdi></span></button></li>
                @endforeach
                @if($free)<li><button type="button" wire:click="addFreePerson('{{ $kind }}')" class="w-full border-t border-line px-3 py-2 text-start text-sm ui-link">{{ __('Add :name as a name not in the list', ['name' => trim($search)]) }}</button></li>@endif
            </ul>
        @endif
    @endif
    @error('peopleSearch.'.$kind)<p role="alert" class="text-sm text-red-700">{{ $message }}</p>@enderror
</div>
