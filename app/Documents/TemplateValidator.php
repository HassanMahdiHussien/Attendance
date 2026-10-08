<?php

namespace App\Documents;

use Illuminate\Validation\ValidationException;

class TemplateValidator
{
    public const PLACEHOLDERS = ['outcome', 'company_name', 'employee_name', 'employee_first_name', 'employee_code', 'position', 'department', 'branch', 'hire_date', 'issue_date', 'reference_number', 'response_deadline', 'complaint_date', 'mandate_number', 'mandate_date', 'session_date', 'penalty', 'gm_name', 'union_rep_name'];

    private const REQUIRED = [
        'defense_letter' => ['title', 'from_label', 'from_value', 'to_label', 'subject_label', 'subject_value', 'date_label', 'salutation', 'opening', 'deadline_sentence'],
        'recommendations' => ['title', 'mandate_sentence', 'findings_intro', 'recommendations_intro', 'outcome_sentence', 'role_legal', 'role_hr', 'role_labor_union', 'chair_label', 'member_label'],
        'decision_letter' => ['title_warning', 'title_termination', 'salutation', 'opening', 'defense_outcome_rejected', 'defense_outcome_partial', 'defense_outcome_accepted', 'decision_sentence', 'gm_title', 'union_title'],
    ];

    public function validate(string $type, array $blocks): void
    {
        foreach (self::REQUIRED[$type] ?? [] as $key) {
            if (blank($blocks[$key] ?? null)) {
                throw ValidationException::withMessages([$key => "يلزم إدخال قسم القالب: {$key}."]);
            }
        }
        $requiredTokens = ['salutation' => ['employee_first_name'], 'opening' => ['hire_date', 'position'],
            'deadline_sentence' => ['response_deadline'], 'mandate_sentence' => ['mandate_number', 'mandate_date', 'session_date'],
            'decision_sentence' => ['penalty'], 'outcome_sentence' => ['outcome'], 'gm_title' => ['gm_name'], 'union_title' => ['union_rep_name']];
        foreach ($blocks as $key => $block) {
            if (! is_string($block)) {
                throw ValidationException::withMessages([$key => 'يجب أن يكون قسم القالب نصاً.']);
            }
            $withoutGender = preg_replace_callback('/\{m:([^{}|]+)\|f:([^{}|]+)\}/u', fn () => '', $block);
            preg_match_all('/\{([^{}]+)\}/u', $withoutGender, $matches);
            foreach ($matches[1] as $token) {
                if (! in_array($token, self::PLACEHOLDERS, true)) {
                    throw ValidationException::withMessages([$key => "خانة القالب {$token} غير معروفة. الخانات المتاحة: ".implode(', ', self::PLACEHOLDERS)]);
                }
            }
            // Known placeholders were validated above; anything brace-like left after removing them is malformed.
            $leftover = preg_replace('/\{[^{}]+\}/u', '', $withoutGender);
            if (str_contains($leftover, '{') || str_contains($leftover, '}')) {
                throw ValidationException::withMessages([$key => 'صيغة خانة القالب غير صحيحة أو الأقواس غير متطابقة.']);
            }
            foreach ($requiredTokens[$key] ?? [] as $token) {
                if (! str_contains($block, '{'.$token.'}')) {
                    throw ValidationException::withMessages([$key => 'خانة القالب المطلوبة {'.$token.'} غير موجودة.']);
                }
            }
        }
    }

    public function validateOptions(array $options): void
    {
        foreach (array_keys($options) as $key) {
            if (! in_array($key, ['show_footer', 'header_mode'], true)) {
                throw ValidationException::withMessages([$key => "خيار القالب {$key} غير معروف."]);
            }
        }
        if (isset($options['show_footer']) && ! is_bool($options['show_footer'])) {
            throw ValidationException::withMessages(['show_footer' => 'يجب تحديد خيار التذييل بنعم أو لا.']);
        }
        if (isset($options['header_mode']) && ! in_array($options['header_mode'], ['logo_text', 'image'], true)) {
            throw ValidationException::withMessages(['header_mode' => 'يجب أن يكون وضع الترويسة logo_text أو image.']);
        }
    }
}
