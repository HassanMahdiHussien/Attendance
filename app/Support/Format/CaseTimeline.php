<?php

namespace App\Support\Format;

use App\Enums\CaseStatus;

class CaseTimeline
{
    public static function statusLabel(CaseStatus|string $status): string
    {
        $value = $status instanceof CaseStatus ? $status->value : $status;

        return match ($value) {
            'draft' => 'مسودة',
            'open' => 'مفتوحة',
            'ai_drafting_defense_letter' => 'صياغة كتاب طلب الدفاع',
            'defense_letter_citation_check' => 'فحص استشهادات كتاب الدفاع',
            'defense_letter_ready_for_review' => 'كتاب الدفاع جاهز للمراجعة',
            'defense_letter_confirmed' => 'تأكيد كتاب الدفاع',
            'defense_letter_issued' => 'إصدار كتاب الدفاع',
            'awaiting_employee_response' => 'بانتظار رد الموظف',
            'response_received' => 'استلام رد الموظف',
            'response_refused_to_sign' => 'استلام الرد مع رفض التوقيع',
            'response_delivery_refused' => 'رفض تسليم الرد',
            'response_scanned_uploaded' => 'رفع مسح رد الموظف',
            'ai_ingested_response' => 'قراءة رد الموظف',
            'committee_session_held' => 'انعقاد جلسة اللجنة',
            'ai_drafting_recommendations' => 'صياغة توصيات اللجنة',
            'recommendations_citation_check' => 'فحص استشهادات التوصيات',
            'recommendations_awaiting_approvals' => 'التوصيات بانتظار الموافقات',
            'recommendations_confirmed' => 'تأكيد التوصيات',
            'recommendations_printed' => 'طباعة التوصيات',
            'awaiting_committee_signatures' => 'بانتظار توقيعات اللجنة',
            'signed_recommendations_uploaded' => 'رفع التوصيات الموقعة',
            'ai_verify_and_close' => 'فحص التوقيعات والإغلاق',
            'decision_letter_citation_check' => 'فحص استشهادات كتاب القرار',
            'awaiting_external_legal_consultation' => 'بانتظار الاستشارة القانونية',
            'decision_letter_ready_for_review' => 'كتاب القرار جاهز للمراجعة',
            'decision_issued' => 'إصدار كتاب القرار',
            'awaiting_appeal_window' => 'بانتظار انتهاء مهلة الطعن',
            'appeal_pending' => 'الطعن قيد المتابعة',
            'appeal_resolved' => 'حسم الطعن',
            'archived_final' => 'الأرشفة النهائية',
            default => 'حالة غير معروفة',
        };
    }

    public static function format(string $type, array $payload = [], array $context = []): string
    {
        return match ($type) {
            'response_reading_requested' => 'طُلبت قراءة مسح الرد بالذكاء الاصطناعي',
            'response_reading_ready' => 'أُعدت محاولة نقل نص الرد للمراجعة',
            'response_reading_failed' => 'تعذرت قراءة الرد بالذكاء الاصطناعي',
            'response_transcription_confirmed' => 'أكدت الموارد البشرية نص الرد المنقول',
            'response_transcription_correction' => 'بدأ تصحيح نص الرد'.self::detail($payload['reason'] ?? null),
            'signed_recommendations_scan_uploaded' => 'رُفع مسح التوصيات الموقعة',
            'signature_presence_ready' => 'أُعدت اقتراحات حضور خانات التوقيع',
            'signature_presence_failed' => 'تعذرت قراءة خانات التوقيع بالذكاء الاصطناعي',
            'ai_request_superseded' => 'أُلغي طلب القراءة بعد تسجيل الموارد البشرية',
            'ai_request_expired' => 'انتهت مهلة طلب القراءة بالذكاء الاصطناعي',
            'ai_draft_requested' => 'طُلبت صياغة بالذكاء الاصطناعي',
            'ai_draft_saved' => 'حُفظت مسودة الذكاء الاصطناعي',
            'ai_draft_failed' => 'تعذرت الصياغة بالذكاء الاصطناعي',
            'citation_relevance_checked' => 'فُحصت ملاءمة الاستشهادات',
            'citation_overridden' => 'تجاوز ملاحظة استشهاد',
            'status_changed' => 'انتقلت الحالة إلى: '.self::statusLabel($payload['to'] ?? ''),
            'appeal_filed' => 'سُجل تقديم طعن',
            'appeal_outcome_recorded' => 'سُجلت نتيجة الطعن'.self::detail($payload['outcome'] ?? null),
            'approvals_invalidated' => 'أُلغيت موافقات التوصيات بعد تعديل النص',
            'case_assigned' => empty($payload['user_id']) ? 'أُلغي تكليف الموارد البشرية'
                : 'كُلّف بمتابعة الحالة: '.($context['assignee_name'] ?? 'مستخدم رقم '.ArabicDigits::convert($payload['user_id'])),
            'case_draft_created' => 'أُنشئت مسودة الحالة',
            'case_draft_updated' => 'حُدثت وقائع مسودة الحالة',
            'case_opened' => 'فُتحت الحالة',
            'committee_session_recorded' => 'سُجلت جلسة اللجنة'.(filled($context['minutes_typed_by'] ?? null) ? ' — كتب المحضر: '.$context['minutes_typed_by'] : ''),
            'recommendations_draft_created' => 'أُنشئت مسودة توصيات اللجنة',
            'recommendations_approval_requested' => 'أُرسلت التوصيات للموافقات',
            'signed_recommendations_scan_recorded' => 'رُفع مسح التوصيات وسُجلت حالة خانات التوقيع',
            'empty_signature_box_overridden' => 'سُجل سبب تجاوز خانة توقيع تبدو فارغة'.self::detail($payload['reason'] ?? null),
            'legal_consultation_cancelled' => 'أُلغيت الاستشارة القانونية'.self::detail($payload['reason'] ?? null),
            'conflict_cleared' => 'سُجل تحقق تعارض الأسماء'.self::detail($payload['reason'] ?? null),
            'deadline_alert_sent' => 'أُرسل تنبيه: '.self::alertLabel($payload['alert_type'] ?? ''),
            'document_corrected_issued' => 'صدر تصحيح المستند'.self::detail($payload['reason'] ?? null),
            'document_correction_started' => 'بدأ تصحيح المستند'.self::detail($payload['reason'] ?? null),
            'document_draft_saved' => 'حُفظت مسودة المستند',
            'decision_draft_saved' => 'حُفظت مسودة كتاب القرار',
            'decision_submitted_for_review' => 'أُرسل كتاب القرار للمراجعة',
            'decision_signature_boxes_recorded' => 'سُجل حضور توقيعي المدير العام وممثل النقابة على الورق',
            'document_issued' => 'صدر المستند'.(filled($payload['reference_number'] ?? null) ? ' بالعدد '.ArabicDigits::convert((string) $payload['reference_number']) : ''),
            'document_pdf_failed' => 'تعذر إعداد ملف PDF للمستند',
            'document_second_reviewed' => 'اكتملت المراجعة الثانية للمستند',
            'document_sent_back' => 'أُعيد المستند للتحرير'.self::detail($payload['reason'] ?? null),
            'document_submitted_for_review' => 'أُرسل المستند للمراجعة',
            'duplicate_report_linked' => 'رُبط بلاغ مكرر بهذه الحالة',
            'hand_off_recorded', 'response_handoff_recorded' => self::handOff($payload, $context),
            'legal_consultation_completed' => 'اكتملت الاستشارة القانونية الخارجية',
            'legal_consultation_requested' => 'طُلبت استشارة قانونية خارجية',
            'recommendations_approved' => 'اعتُمدت توصيات اللجنة من '.self::approvalCategory($payload['category'] ?? ''),
            'recommendations_rejected' => 'رُفضت توصيات اللجنة من '.self::approvalCategory($payload['category'] ?? '')
                .self::detail($payload['comment'] ?? null),
            'response_not_received' => 'لم يرد رد الموظف حتى انتهاء المهلة',
            'response_scan_uploaded' => 'رُفع مسح رد الموظف',
            'termination_leave_confirmed' => 'أكدت الموارد البشرية وضع الإجازة قبل قرار إنهاء الخدمة',
            'report_dismissed' => 'رُفض البلاغ'.self::detail($payload['reason'] ?? null),
            default => 'حدث غير مصنف: '.$type,
        };
    }

    private static function detail(?string $value): string
    {
        return filled($value) ? ' — '.$value : '';
    }

    private static function alertLabel(string $type): string
    {
        return match ($type) {
            'procedure_approaching' => 'مهلة الإجراء تقترب',
            'procedure_overdue' => 'انتهت مهلة الإجراء',
            'response_overdue' => 'انتهت مهلة الرد',
            'consultation_pending' => 'الاستشارة القانونية معلقة',
            'appeal_window_ending' => 'مهلة الطعن تقترب من النهاية',
            default => 'مهلة قانونية',
        };
    }

    private static function handOff(array $payload, array $context): string
    {
        $purpose = match ($payload['purpose'] ?? '') {
            'defense_letter_receipt' => 'كتاب طلب الدفاع',
            'response_submission' => 'رد الموظف',
            'decision_letter_receipt' => 'كتاب القرار',
            default => 'المستند الورقي',
        };
        $status = match ($payload['status'] ?? '') {
            'signed' => 'بتوقيع المستلم',
            'refused_to_sign' => 'مع رفض التوقيع',
            'delivery_refused' => 'مع رفض الاستلام',
            default => 'بحالة مسجلة',
        };
        $witnesses = array_filter($context['witnesses'] ?? []);

        return 'سُجل تسليم '.$purpose.' '.$status
            .($witnesses ? ' — الشاهدان: '.implode('، ', $witnesses) : '');
    }

    private static function approvalCategory(string $category): string
    {
        return match ($category) {
            'legal' => 'الممثل القانوني', 'hr' => 'ممثل الموارد البشرية',
            'labor_union' => 'ممثل نقابة العمال', default => 'أحد أعضاء اللجنة',
        };
    }
}
