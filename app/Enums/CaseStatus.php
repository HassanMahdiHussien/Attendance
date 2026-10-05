<?php

namespace App\Enums;

enum CaseStatus: string
{
    case Draft = 'draft';
    case Open = 'open';
    case AiDraftingDefenseLetter = 'ai_drafting_defense_letter';
    case DefenseLetterCitationCheck = 'defense_letter_citation_check';
    case DefenseLetterReadyForReview = 'defense_letter_ready_for_review';
    case DefenseLetterConfirmed = 'defense_letter_confirmed';
    case DefenseLetterIssued = 'defense_letter_issued';
    case AwaitingEmployeeResponse = 'awaiting_employee_response';
    case ResponseReceived = 'response_received';
    case ResponseRefusedToSign = 'response_refused_to_sign';
    case ResponseDeliveryRefused = 'response_delivery_refused';
    case ResponseScannedUploaded = 'response_scanned_uploaded';
    case AiIngestedResponse = 'ai_ingested_response';
    case CommitteeSessionHeld = 'committee_session_held';
    case AiDraftingRecommendations = 'ai_drafting_recommendations';
    case RecommendationsCitationCheck = 'recommendations_citation_check';
    case RecommendationsAwaitingApprovals = 'recommendations_awaiting_approvals';
    case RecommendationsConfirmed = 'recommendations_confirmed';
    case RecommendationsPrinted = 'recommendations_printed';
    case AwaitingCommitteeSignatures = 'awaiting_committee_signatures';
    case SignedRecommendationsUploaded = 'signed_recommendations_uploaded';
    case AiVerifyAndClose = 'ai_verify_and_close';
    case DecisionLetterCitationCheck = 'decision_letter_citation_check';
    case AwaitingExternalLegalConsultation = 'awaiting_external_legal_consultation';
    case DecisionLetterReadyForReview = 'decision_letter_ready_for_review';
    case DecisionIssued = 'decision_issued';
    case AwaitingAppealWindow = 'awaiting_appeal_window';
    case AppealPending = 'appeal_pending';
    case AppealResolved = 'appeal_resolved';
    case ArchivedFinal = 'archived_final';
}
