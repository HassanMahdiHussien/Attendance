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
        return [Action::make('import')->label('استيراد')->schema([
            FileUpload::make('source')->label('ملف PDF (اختياري: المصدر المحفوظ)')
                ->acceptedFileTypes(['application/pdf'])->storeFiles(false),
            TextInput::make('page_from')->label('من صفحة')->numeric()->minValue(1)->live(onBlur: true),
            TextInput::make('page_to')->label('إلى صفحة')->numeric()->minValue(1)->live(onBlur: true),
            Select::make('mode')->label('طريقة القراءة')->options(['auto' => 'تلقائي', 'text' => 'طبقة النص', 'ocr' => 'OCR'])
                ->default($this->record->import_mode)->required(),
            Placeholder::make('range_preview')->label('الصفحة الأولى والأخيرة')
                ->content(fn (Get $get): HtmlString => $this->previewHtml($this->record, $get('source'), $get('page_from'), $get('page_to'))),
            Checkbox::make('range_confirmed')->label('تحققت من بداية القانون في الصفحة الأولى ونهايته في الأخيرة'),
        ])->action(function (array $data): void {
            abort_unless(auth()->user()->can('import', $this->record), 403);
            $from = filled($data['page_from'] ?? null) ? (int) $data['page_from'] : null;
            $to = filled($data['page_to'] ?? null) ? (int) $data['page_to'] : null;
            if (($this->record->requires_page_range || $from !== null || $to !== null) && ! ($data['range_confirmed'] ?? false)) {
                throw ValidationException::withMessages(['range_confirmed' => 'يجب تأكيد بداية القانون ونهايته.']);
            }
            if ($from !== null && $to !== null) {
                app(LawImportService::class)->previewPages($this->record, $data['source'] ?? null, $from, $to);
            }
            app(LawImportService::class)->start($this->record, auth()->user(), $data['source'] ?? null,
                $from, $to, ImportMode::from($data['mode']));
            $this->record->refresh();
            $this->fillForm();
        })];
    }

    private function previewHtml(Law $law, mixed $source, mixed $from, mixed $to): HtmlString
    {
        if (! $from || ! $to) {
            return new HtmlString('أدخل نطاق الصفحات لعرض طرفيه.');
        }
        try {
            $paths = app(LawImportService::class)->previewPages($law, $source, (int) $from, (int) $to);
        } catch (Throwable) {
            return new HtmlString('تعذر عرض النطاق. تحقق من الملف وأرقام الصفحات.');
        }
        $html = '<div dir="rtl" style="display:flex;gap:1rem;overflow:auto">';
        foreach ($paths as $number => $path) {
            $bytes = Storage::disk('local')->get($path);
            $html .= '<figure><figcaption>صفحة '.e((string) $number).'</figcaption><img style="max-width:18rem" src="data:image/jpeg;base64,'.base64_encode($bytes).'" alt="صفحة '.e((string) $number).'"></figure>';
        }

        return new HtmlString($html.'</div>');
    }
}
