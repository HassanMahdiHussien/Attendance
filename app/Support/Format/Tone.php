<?php

namespace App\Support\Format;

use App\Enums\CaseStatus;
use App\Enums\DocumentStatus;

class Tone
{
    public static function case(CaseStatus|string $status): string
    {
        $value = $status instanceof CaseStatus ? $status->value : $status;

        return match ($value) {
            CaseStatus::ArchivedFinal->value, CaseStatus::DecisionIssued->value, CaseStatus::AppealResolved->value => 'green',
            CaseStatus::AwaitingEmployeeResponse->value, CaseStatus::AwaitingCommitteeSignatures->value,
            CaseStatus::RecommendationsAwaitingApprovals->value, CaseStatus::AwaitingExternalLegalConsultation->value,
            CaseStatus::AppealPending->value => 'amber',
            CaseStatus::Draft->value => 'gray',
            default => 'indigo',
        };
    }

    public static function document(DocumentStatus|string $status): string
    {
        $value = $status instanceof DocumentStatus ? $status->value : $status;

        return match ($value) {
            'issued', 'confirmed', 'approved' => 'green',
            'ready_for_review', 'awaiting_approvals', 'pending' => 'amber',
            'rejected', 'blocked' => 'red',
            'draft' => 'gray',
            default => 'indigo',
        };
    }

    public static function deadline(?int $days): string
    {
        return $days === null ? 'gray' : ($days <= 2 ? 'red' : ($days <= 5 ? 'amber' : 'indigo'));
    }

    public static function report(string $status): string
    {
        return match ($status) {
            'received', 'under_review' => 'amber',
            'closed', 'case_created', 'resolved' => 'green',
            'rejected' => 'red',
            default => 'gray',
        };
    }

    public static function approval(string $status): string
    {
        return match ($status) {
            'approved' => 'green', 'rejected' => 'red', 'pending' => 'amber', default => 'gray',
        };
    }
}
