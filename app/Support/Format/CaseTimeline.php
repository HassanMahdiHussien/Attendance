<?php

namespace App\Support\Format;

use App\Enums\CaseStatus;

class CaseTimeline
{
    public static function statusLabel(CaseStatus|string $status): string
    {
        $value = $status instanceof CaseStatus ? $status->value : $status;

        return CaseStatus::tryFrom($value) !== null
            ? __('messages.case_status.'.$value)
            : __('messages.case_status.unknown');
    }

    public static function format(string $type, array $payload = [], array $context = []): string
    {
        return match ($type) {
            'response_reading_requested', 'response_reading_ready', 'response_reading_failed',
            'response_transcription_confirmed', 'signed_recommendations_scan_uploaded',
            'signature_presence_ready', 'signature_presence_failed', 'ai_request_superseded',
            'ai_request_expired', 'ai_draft_requested', 'ai_draft_auto_requested', 'ai_draft_auto_skipped',
            'ai_draft_saved', 'ai_draft_failed', 'case_related_linked', 'case_related_link_in_error',
            'citation_relevance_checked', 'citation_overridden', 'appeal_filed',
            'approvals_invalidated' => __('messages.timeline.'.$type),
            'response_transcription_correction' => __('messages.timeline.response_transcription_correction').self::detail($payload['reason'] ?? null),
            'status_changed' => __('messages.timeline.status_changed', ['status' => self::statusLabel($payload['to'] ?? '')]),
            'appeal_outcome_recorded' => __('messages.timeline.appeal_outcome_recorded').self::detail($payload['outcome'] ?? null),
            'case_assigned' => empty($payload['user_id']) ? __('messages.timeline.case_unassigned')
                : __('messages.timeline.case_assigned', ['assignee' => $context['assignee_name'] ?? __('messages.timeline.user_number', ['number' => Ui::number($payload['user_id'])])]),
            'case_draft_created', 'case_draft_updated', 'case_opened',
            'recommendations_draft_created', 'recommendations_approval_requested',
            'signed_recommendations_scan_recorded', 'document_draft_saved', 'decision_draft_saved',
            'decision_submitted_for_review', 'decision_signature_boxes_recorded',
            'document_pdf_failed', 'document_second_reviewed', 'document_submitted_for_review',
            'duplicate_report_linked' => __('messages.timeline.'.$type),
            'committee_session_recorded' => __('messages.timeline.committee_session_recorded').(filled($context['minutes_typed_by'] ?? null) ? ' — '.__('messages.timeline.minutes_author', ['name' => $context['minutes_typed_by']]) : ''),
            'empty_signature_box_overridden', 'legal_consultation_cancelled', 'conflict_cleared',
            'document_corrected_issued', 'document_correction_started', 'document_sent_back' => __('messages.timeline.'.$type).self::detail($payload['reason'] ?? null),
            'deadline_alert_sent' => __('messages.timeline.deadline_alert_sent', ['alert' => self::alertLabel($payload['alert_type'] ?? '')]),
            'document_issued' => __('messages.timeline.document_issued').(filled($payload['reference_number'] ?? null) ? ' '.__('messages.timeline.with_reference', ['reference' => Ui::number((string) $payload['reference_number'])]) : ''),
            'hand_off_recorded', 'response_handoff_recorded' => self::handOff($payload, $context),
            'legal_consultation_completed', 'legal_consultation_requested', 'response_not_received',
            'response_scan_uploaded', 'termination_leave_confirmed' => __('messages.timeline.'.$type),
            'recommendations_approved' => __('messages.timeline.recommendations_approved', ['category' => self::approvalCategory($payload['category'] ?? '')]),
            'recommendations_rejected' => __('messages.timeline.recommendations_rejected', ['category' => self::approvalCategory($payload['category'] ?? '')])
                .self::detail($payload['comment'] ?? null),
            'report_dismissed' => __('messages.timeline.report_dismissed').self::detail($payload['reason'] ?? null),
            default => __('messages.timeline.unknown', ['type' => $type]),
        };
    }

    private static function detail(?string $value): string
    {
        return filled($value) ? ' — '.$value : '';
    }

    private static function alertLabel(string $type): string
    {
        return match ($type) {
            'procedure_approaching' => __('messages.alert.procedure_approaching'),
            'procedure_overdue' => __('messages.alert.procedure_overdue'),
            'response_overdue' => __('messages.alert.response_overdue'),
            'consultation_pending' => __('messages.alert.consultation_pending'),
            'appeal_window_ending' => __('messages.alert.appeal_window_ending'),
            default => __('messages.alert.legal_deadline'),
        };
    }

    private static function handOff(array $payload, array $context): string
    {
        $purpose = match ($payload['purpose'] ?? '') {
            'defense_letter_receipt' => __('messages.timeline.defense_letter_receipt'),
            'response_submission' => __('messages.timeline.response_submission'),
            'decision_letter_receipt' => __('messages.timeline.decision_letter_receipt'),
            default => __('messages.timeline.paper_document'),
        };
        $status = match ($payload['status'] ?? '') {
            'signed' => __('messages.timeline.signed'),
            'refused_to_sign' => __('messages.timeline.refused_to_sign'),
            'delivery_refused' => __('messages.timeline.delivery_refused'),
            default => __('messages.timeline.recorded_status'),
        };
        $witnesses = array_filter($context['witnesses'] ?? []);

        return __('messages.timeline.handoff_recorded', ['purpose' => $purpose, 'status' => $status])
            .($witnesses ? ' — '.__('messages.timeline.witnesses', ['names' => implode(__('messages.timeline.witness_separator'), $witnesses)]) : '');
    }

    private static function approvalCategory(string $category): string
    {
        return match ($category) {
            'legal' => __('messages.timeline.legal_representative'), 'hr' => __('messages.timeline.hr_representative'),
            'labor_union' => __('messages.timeline.union_representative'), default => __('messages.timeline.committee_member'),
        };
    }
}
