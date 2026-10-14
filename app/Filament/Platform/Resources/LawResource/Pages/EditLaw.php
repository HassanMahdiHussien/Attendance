<?php

namespace App\Filament\Platform\Resources\LawResource\Pages;

use App\Enums\ImportMode;
use App\Filament\Platform\Resources\LawResource;
use App\LegalLibrary\LawImportService;
use App\LegalLibrary\LawManagement;
use App\Models\Law;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use Throwable;

class EditLaw extends EditRecord
{
    protected static string $resource = LawResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['jurisdiction_values'] = $this->record->jurisdictions()->pluck('jurisdiction')
            ->map(fn ($value): string => $value instanceof \BackedEnum ? $value->value : $value)->all();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $jurisdictions = $data['jurisdiction_values'] ?? [];
        unset($data['jurisdiction_values']);
        $repealedAt = $data['repealed_at'] ?? null;
        unset($data['repealed_at']);
        app(LawManagement::class)->update($record, auth()->user(), $data);
        if ($repealedAt && $repealedAt !== $record->repealed_at) {
            app(LawManagement::class)->repeal($record, auth()->user(), $repealedAt);
        }
        app(LawManagement::class)->setJurisdictions($record, auth()->user(), $jurisdictions);

        return $record;
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('import')->label(__('Import'))->schema([
            FileUpload::make('source')->label(__('PDF file (optional stored source)'))
                ->acceptedFileTypes(['application/pdf'])->storeFiles(false),
            TextInput::make('page_from')->label(__('From page'))->numeric()->minValue(1)->live(onBlur: true),
            TextInput::make('page_to')->label(__('To page'))->numeric()->minValue(1)->live(onBlur: true),
            Select::make('mode')->label(__('Reading method'))->options(['auto' => __('Automatic'), 'text' => __('Text layer'), 'ocr' => 'OCR'])
                ->default($this->record->import_mode)->required(),
            Select::make('reading_mode')->label(__('AI reading mode'))
                ->options(['batch' => __('Batch reading'), 'now' => __('Read now')])
                ->default($this->record->kind->value === 'statute' ? 'batch' : 'now')->required(),
            FileUpload::make('transcription')->label(__('Page transcription (JSON)'))
                ->acceptedFileTypes(['application/json', 'text/plain'])->storeFiles(false),
            Textarea::make('transcription_note')->label(__('Transcription source note')),
            FileUpload::make('secondary')->label(__('Independent secondary text (PDF or TXT)'))
                ->acceptedFileTypes(['application/pdf', 'text/plain'])->storeFiles(false),
            Textarea::make('secondary_note')->label(__('Secondary source note')),
            Placeholder::make('range_preview')->label(__('First and last page'))
                ->content(fn (Get $get): HtmlString => $this->previewHtml($this->record, $get('source'), $get('page_from'), $get('page_to'))),
            Checkbox::make('range_confirmed')->label(__('I verified the law starts on the first page and ends on the last')),
        ])->action(function (array $data): void {
            abort_unless(auth()->user()->can('import', $this->record), 403);
            $from = filled($data['page_from'] ?? null) ? (int) $data['page_from'] : null;
            $to = filled($data['page_to'] ?? null) ? (int) $data['page_to'] : null;
            if (($this->record->requires_page_range || $from !== null || $to !== null) && ! ($data['range_confirmed'] ?? false)) {
                throw ValidationException::withMessages(['range_confirmed' => __('The start and end of the law must be confirmed.')]);
            }
            if ($from !== null && $to !== null) {
                app(LawImportService::class)->previewPages($this->record, $data['source'] ?? null, $from, $to);
            }
            app(LawImportService::class)->start($this->record, auth()->user(), $data['source'] ?? null,
                $from, $to, ImportMode::from($data['mode']), $data['reading_mode'],
                $data['transcription'] ?? null, $data['secondary'] ?? null,
                $data['transcription_note'] ?? null, $data['secondary_note'] ?? null);
            $this->record->refresh();
            $this->fillForm();
        })];
    }

    private function previewHtml(Law $law, mixed $source, mixed $from, mixed $to): HtmlString
    {
        if (! $from || ! $to) {
            return new HtmlString(__('Enter a page range to show both ends.'));
        }
        try {
            $paths = app(LawImportService::class)->previewPages($law, $source, (int) $from, (int) $to);
        } catch (Throwable) {
            return new HtmlString(__('Could not display the range. Check the file and page numbers.'));
        }
        $html = '<div dir="rtl" style="display:flex;gap:1rem;overflow:auto">';
        foreach ($paths as $number => $path) {
            $bytes = Storage::disk('local')->get($path);
            $html .= '<figure><figcaption>'.e(__('Page')).' '.e((string) $number).'</figcaption><img style="max-width:18rem" src="data:image/jpeg;base64,'.base64_encode($bytes).'" alt="'.e(__('Page')).' '.e((string) $number).'"></figure>';
        }

        return new HtmlString($html.'</div>');
    }
}
