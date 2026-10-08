<?php

namespace App\Filament\Admin\Pages;

use App\Documents\PlaceholderResolver;
use App\Documents\TemplateResolver;
use App\Documents\TemplateValidator;
use App\Enums\DocumentType;
use App\Enums\EmployeeGender;
use App\Enums\UserRole;
use App\Exceptions\IssuanceBlocked;
use App\Models\CompanyLetterhead;
use App\Models\DocumentTemplate;
use App\Services\Documents\TemplateService;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class DocumentTemplates extends Page
{
    private const BLOCK_LABELS = [
        'title' => 'Title',
        'title_warning' => 'Warning title',
        'title_termination' => 'Termination title',
        'from_label' => 'From label',
        'from_value' => 'From text',
        'to_label' => 'To label',
        'cc_label' => 'Reference label',
        'cc_value' => 'Reference text',
        'subject_label' => 'Subject label',
        'subject_value' => 'Subject text',
        'date_label' => 'Date label',
        'reference_label' => 'Reference number label',
        'salutation' => 'Salutation',
        'opening' => 'Opening paragraph',
        'deadline_sentence' => 'Deadline sentence',
        'prior_record' => 'Prior record paragraph',
        'employee_block' => 'Employee signature block',
        'mandate_sentence' => 'Administrative order sentence',
        'lead_in' => 'Lead-in',
        'findings_intro' => 'Findings introduction',
        'recommendations_intro' => 'Recommendations introduction',
        'role_legal' => 'Legal role label',
        'role_hr' => 'HR role label',
        'role_labor_union' => 'Union representative role label',
        'chair_label' => 'Committee chair label',
        'chair_label_female' => 'Female committee chair label',
        'member_label' => 'Committee member label',
        'member_label_female' => 'Female committee member label',
        'signature_line' => 'Signature line',
        'defense_outcome_rejected' => 'Defense outcome (rejected)',
        'defense_outcome_partial' => 'Defense outcome (partially accepted)',
        'defense_outcome_accepted' => 'Defense outcome (fully accepted)',
        'decision_sentence' => 'Decision sentence',
        'gm_title' => 'General Manager signature',
        'union_title' => 'Union representative signature',
        'acknowledgment' => 'Employee acknowledgment',
    ];

    private const REQUIRED_BLOCKS = [
        'defense_letter' => ['title', 'from_label', 'from_value', 'to_label', 'subject_label', 'subject_value', 'date_label', 'salutation', 'opening', 'deadline_sentence'],
        'recommendations' => ['title', 'mandate_sentence', 'findings_intro', 'recommendations_intro', 'role_legal', 'role_hr', 'role_labor_union', 'chair_label', 'member_label'],
        'decision_letter' => ['title_warning', 'title_termination', 'salutation', 'opening', 'defense_outcome_rejected', 'defense_outcome_partial', 'defense_outcome_accepted', 'decision_sentence', 'gm_title', 'union_title'],
    ];

    public static function getNavigationLabel(): string
    {
        return __('Document templates');
    }

    public function getTitle(): string
    {
        return __('Document templates');
    }

    protected string $view = 'filament.admin.pages.document-templates';

    public string $documentType = 'defense_letter';

    public string $language = 'ar';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return in_array(auth()->user()?->primary_role, [UserRole::CompanyAdmin, UserRole::PlatformAdmin, UserRole::HrStaff], true);
    }

    public function canSave(): bool
    {
        return in_array(auth()->user()?->primary_role, [UserRole::CompanyAdmin, UserRole::PlatformAdmin], true);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess() && Filament::getTenant(), 403);
        $this->loadTemplate();
    }

    public function updatedDocumentType(): void
    {
        $this->hasCachedForms = false;
        unset($this->cachedSchemas['form']);
        $this->loadTemplate();
    }

    public function updatedLanguage(): void
    {
        $this->hasCachedForms = false;
        unset($this->cachedSchemas['form']);
        $this->loadTemplate();
    }

    public function form(Schema $schema): Schema
    {
        $defaults = $this->resolved()['blocks'];
        $fields = [];
        foreach (array_keys($defaults) as $key) {
            $fields[] = Textarea::make($key)->label(__(self::BLOCK_LABELS[$key] ?? $key))->default($defaults[$key])
                ->hint($key)->required(in_array($key, self::REQUIRED_BLOCKS[$this->documentType], true))->rows(3)
                ->extraInputAttributes(['dir' => $this->language === 'en' ? 'ltr' : 'rtl'])
                ->live(debounce: 400);
        }

        return $schema->statePath('data')->components([Section::make()->statePath('blocks')->schema($fields)]);
    }

    public function loadTemplate(): void
    {
        $template = $this->resolved();
        $this->data = ['blocks' => $template['blocks']];
        $this->form->fill($this->data);
    }

    public function needsCompanyWording(): bool
    {
        return ($this->resolved()['missing_default'] ?? false) === true;
    }

    public function save(): void
    {
        abort_unless($this->canSave(), 403);
        $company = Filament::getTenant();
        abort_unless($company, 404);
        $blocks = $this->form->getState()['blocks'] ?? [];
        if ($blocks == $this->resolved()['blocks']) {
            return;
        }
        try {
            app(TemplateService::class)->saveVersion($company, $this->documentType, $this->language,
                $blocks, $this->resolved()['options'], auth()->user());
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError("data.blocks.{$field}", $messages[0]);
            }

            return;
        }
    }

    public function previewHtml(): string
    {
        $company = Filament::getTenant();
        $template = $this->resolved();
        $letterhead = CompanyLetterhead::query()->where('company_id', $company->id)
            ->where('language_code', $this->language)->orderByDesc('version')->first();
        $facts = array_fill_keys(TemplateValidator::PLACEHOLDERS, 'Sample');
        $facts['company_name'] = $letterhead?->name ?? $company->name;
        $english = $this->language === 'en';
        $facts['employee_name'] = $english ? 'Sample Employee' : 'موظف تجريبي';
        $facts['employee_first_name'] = $english ? 'Sample' : 'موظف';
        $facts['hire_date'] = $english ? '1 January 2026' : '1 – كانون الثاني – 2026';
        $facts['position'] = $english ? 'Operator' : 'مشغل';
        $facts['issue_date'] = $english ? '5 October 2026' : '5 – تشرين الأول – 2026';
        $facts['reference_number'] = 'SAMPLE';
        $facts['response_deadline'] = $english ? '8 October 2026' : '8 – تشرين الأول – 2026';
        $facts['penalty'] = $english ? 'written warning' : 'إنذار خطي';
        $facts['gm_name'] = $english ? 'Sample Manager' : 'مدير تجريبي';
        $facts['union_rep_name'] = $english ? 'Sample Representative' : 'ممثل تجريبي';
        $blocks = array_replace($template['blocks'], $this->data['blocks'] ?? []);
        foreach ($blocks as $key => $value) {
            if (! is_string($value)) {
                continue;
            }
            try {
                $blocks[$key] = app(PlaceholderResolver::class)->resolve($value, $facts, EmployeeGender::Male);
            } catch (\Throwable) {
                // An unfinished edit stays visible in the preview without executing it.
            }
        }
        $page = [
            'language' => $this->language, 'letterhead' => $letterhead ?? (object) ['name' => $company->name,
                'address_lines' => null, 'phone' => null, 'email' => null, 'footer_text' => 'CONFIDENTIAL'],
            'blocks' => $blocks, 'facts' => $facts,
            'extra' => ['prior_cases' => [], 'defense_acceptance' => 'rejected', 'attendees' => [
                ['name' => 'Sample Legal', 'role' => 'legal', 'is_chair' => true, 'is_female' => true],
                ['name' => 'Sample HR', 'role' => 'hr', 'is_chair' => false, 'is_female' => false],
                ['name' => 'Sample Union', 'role' => 'labor_union', 'is_chair' => false, 'is_female' => false],
            ]],
            'body' => '<p>Sample case facts supplied by HR.</p>',
            'secondary' => '<p>Sample committee recommendation.</p>',
            'logo' => $this->previewImage($letterhead?->logo_path, $letterhead?->logo_sha256),
            'headerImage' => $this->previewImage($letterhead?->header_image_path, $letterhead?->header_image_sha256),
            'options' => array_replace($template['options'], ['header_mode' => $letterhead?->header_mode ?? 'logo_text']),
            'type' => $this->documentType,
        ];

        return view('documents.layout', ['pages' => [$page], 'draft' => true])->render();
    }

    public function history(): array
    {
        $company = Filament::getTenant();
        if (! $company) {
            return [];
        }

        return DocumentTemplate::query()->where('company_id', $company->id)
            ->where('document_type', $this->documentType)->where('language_code', $this->language)
            ->orderByDesc('version')->get(['version', 'created_at', 'created_by'])->toArray();
    }

    private function resolved(): array
    {
        $company = Filament::getTenant();
        abort_unless($company && DocumentType::tryFrom($this->documentType)
            && in_array($this->language, $company->enabled_languages, true), 404);

        try {
            $template = app(TemplateResolver::class)->resolve($company->id, $this->documentType, $this->language);
            if ($this->documentType === 'recommendations') {
                $path = app_path("Documents/Defaults/recommendations.{$this->language}.php");
                $defaults = is_file($path) ? require $path : [];
                foreach (['chair_label_female', 'member_label_female'] as $key) {
                    if (blank($template['blocks'][$key] ?? null)) {
                        $template['blocks'][$key] = $defaults[$key] ?? $template['blocks'][str_replace('_female', '', $key)];
                    }
                }
            }

            return $template;
        } catch (IssuanceBlocked $exception) {
            if (is_file(app_path("Documents/Defaults/{$this->documentType}.{$this->language}.php"))) {
                throw $exception;
            }
            $keys = array_keys(require app_path("Documents/Defaults/{$this->documentType}.ar.php"));

            return ['blocks' => array_fill_keys($keys, ''), 'options' => ['show_footer' => true, 'header_mode' => 'logo_text'], 'missing_default' => true];
        }
    }

    private function previewImage(?string $path, ?string $sha): ?string
    {
        if (! $path || ! $sha || ! Storage::disk('local')->exists($path)) {
            return null;
        }
        $bytes = Storage::disk('local')->get($path);
        $mime = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => 'image/png', 'jpg', 'jpeg' => 'image/jpeg', default => null,
        };
        if (! $mime || strlen($bytes) > 2 * 1024 * 1024 || hash('sha256', $bytes) !== $sha
            || ($mime === 'image/png' && ! str_starts_with($bytes, "\x89PNG\r\n\x1a\n"))
            || ($mime === 'image/jpeg' && ! str_starts_with($bytes, "\xFF\xD8"))) {
            return null;
        }

        return 'data:'.$mime.';base64,'.base64_encode($bytes);
    }
}
