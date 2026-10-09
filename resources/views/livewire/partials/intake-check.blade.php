<div class="space-y-3" @if($intakeCheck?->status === 'pending') wire:poll.2s @endif>
    <x-ui.button type="button" variant="secondary" wire:click="checkDescription">{{ __('messages.intake.check_description') }}</x-ui.button>
    @error('description')<p role="alert" class="text-sm text-red-700">{{ $message }}</p>@enderror
    @if($intakeCheck?->status === 'pending')
        <p role="status" class="text-sm text-gray-600">{{ __('messages.intake.checking') }}</p>
    @elseif($intakeCheck?->status === 'ready' && $intakeCheck->result['complete'])
        <p role="status" class="text-sm text-green-700">{{ __('messages.intake.looks_complete') }}</p>
    @elseif($intakeCheck && in_array($intakeCheck->status, ['ready', 'checklist'], true))
        @if($intakeCheck->status === 'checklist')<x-ui.alert tone="amber">{{ __('messages.intake.checklist_fallback') }}</x-ui.alert>@endif
        <div class="space-y-3" aria-label="{{ __('messages.intake.open_points') }}">
            @foreach($intakeCheck->result['questions'] as $index => $question)
                <x-ui.field :label="$question['question']" for="intake-answer-{{ $index }}">
                    <x-ui.textarea id="intake-answer-{{ $index }}" wire:model="intakeAnswers.{{ $index }}" rows="2" dir="auto" />
                    @error('intakeAnswers.'.$index)<p class="text-sm text-red-700">{{ $message }}</p>@enderror
                </x-ui.field>
            @endforeach
            <p class="text-xs text-gray-600">{{ __('messages.intake.optional_answers') }}</p>
        </div>
    @endif
    @if(isset($existingIntakeQuestions) && $existingIntakeQuestions->isNotEmpty())
        <div class="space-y-2">
            <h3 class="font-semibold">{{ __('messages.intake.previous_points') }}</h3>
            @foreach($existingIntakeQuestions as $question)
                <div class="text-sm" dir="auto"><strong>{{ $question->question }}</strong> — {{ $question->answer ?? __('messages.intake.open') }}
                    @if($question->answer === null)
                        <x-ui.textarea wire:model="existingIntakeAnswers.{{ $question->id }}" rows="2" dir="auto" :aria-label="__('messages.intake.your_answer')" />
                        @error('existingIntakeAnswers.'.$question->id)<p class="text-sm text-red-700">{{ $message }}</p>@enderror
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
