<x-filament-panels::page>
    <div class="space-y-6">
        <div class="flex gap-4">
            <label>نوع المستند
                <select wire:model.live="documentType">
                    <option value="defense_letter">طلب دفاع</option>
                    <option value="recommendations">توصيات اللجنة</option>
                    <option value="decision_letter">كتاب القرار</option>
                </select>
            </label>
            <label>اللغة
                <select wire:model.live="language">
                    @foreach (\Filament\Facades\Filament::getTenant()->enabled_languages as $code)
                        <option value="{{ $code }}">{{ ['ar' => 'العربية', 'en' => 'English', 'ku' => 'کوردی'][$code] ?? $code }}</option>
                    @endforeach
                </select>
            </label>
        </div>
        @if ($this->needsCompanyWording())
            <p role="alert" class="rounded-lg border border-amber-400 bg-amber-50 p-3 text-amber-900">لا توجد صياغة افتراضية لهذه اللغة. يجب على الشركة توفير نص القالب قبل استخدامه.</p>
        @endif
        <p>المتغيرات المتاحة: @foreach (\App\Documents\TemplateValidator::PLACEHOLDERS as $placeholder) <code>&#123;{{ $placeholder }}&#125;</code> @endforeach</p>
        <p>ما ستتم طباعته، باستثناء الرقم المرجعي المؤقت وعلامة المسودة.</p>
        <form wire:key="template-{{ $documentType }}-{{ $language }}" wire:submit="save" class="space-y-4">
            {{ $this->form }}
            @if ($this->canSave())
                <x-filament::button type="submit">حفظ نسخة جديدة</x-filament::button>
            @endif
        </form>
        <h2>معاينة</h2>
        <iframe title="معاينة القالب" sandbox srcdoc="{{ $this->previewHtml() }}" style="width:100%;height:460px;border:1px solid #aaa"></iframe>
        <h2>سجل النسخ</h2>
        <ul>
            @foreach ($this->history() as $version)
                <li>نسخة {{ $version['version'] }} — {{ $version['created_at'] }}</li>
            @endforeach
        </ul>
    </div>
</x-filament-panels::page>
