<?php

namespace App\Workflow;

use App\Enums\CaseStatus as S;

class CaseTransitions
{
    public const MAP = [
        S::Draft->value => [S::Open],
        S::Open->value => [S::AiDraftingDefenseLetter, S::DefenseLetterCitationCheck],
        S::AiDraftingDefenseLetter->value => [S::DefenseLetterCitationCheck, S::Open],
        S::DefenseLetterCitationCheck->value => [S::DefenseLetterReadyForReview],
        S::DefenseLetterReadyForReview->value => [S::DefenseLetterConfirmed, S::Open],
        S::DefenseLetterConfirmed->value => [S::DefenseLetterIssued],
        S::DefenseLetterIssued->value => [S::AwaitingEmployeeResponse],
        S::AwaitingEmployeeResponse->value => [S::ResponseReceived, S::ResponseRefusedToSign, S::ResponseDeliveryRefused, S::ResponseScannedUploaded, S::CommitteeSessionHeld],
        S::ResponseReceived->value => [S::ResponseScannedUploaded, S::CommitteeSessionHeld],
        S::ResponseRefusedToSign->value => [S::ResponseScannedUploaded, S::CommitteeSessionHeld],
        S::ResponseDeliveryRefused->value => [S::ResponseScannedUploaded, S::CommitteeSessionHeld],
        S::ResponseScannedUploaded->value => [S::AiIngestedResponse, S::CommitteeSessionHeld],
        S::AiIngestedResponse->value => [S::CommitteeSessionHeld, S::ResponseScannedUploaded],
        S::CommitteeSessionHeld->value => [S::AiDraftingRecommendations, S::RecommendationsCitationCheck],
        S::AiDraftingRecommendations->value => [S::RecommendationsCitationCheck, S::CommitteeSessionHeld],
        S::RecommendationsCitationCheck->value => [S::RecommendationsAwaitingApprovals],
        S::RecommendationsAwaitingApprovals->value => [S::RecommendationsConfirmed, S::RecommendationsCitationCheck],
        S::RecommendationsConfirmed->value => [S::RecommendationsCitationCheck, S::RecommendationsPrinted],
        S::RecommendationsPrinted->value => [S::AwaitingCommitteeSignatures],
        S::AwaitingCommitteeSignatures->value => [S::SignedRecommendationsUploaded],
        S::SignedRecommendationsUploaded->value => [S::AiVerifyAndClose, S::DecisionLetterCitationCheck, S::ArchivedFinal],
        S::AiVerifyAndClose->value => [S::DecisionLetterCitationCheck, S::ArchivedFinal, S::SignedRecommendationsUploaded],
        S::DecisionLetterCitationCheck->value => [S::AwaitingExternalLegalConsultation, S::DecisionLetterReadyForReview],
        S::AwaitingExternalLegalConsultation->value => [S::DecisionLetterReadyForReview],
        S::DecisionLetterReadyForReview->value => [S::DecisionIssued, S::DecisionLetterCitationCheck],
        S::DecisionIssued->value => [S::AwaitingAppealWindow],
        S::AwaitingAppealWindow->value => [S::AppealPending, S::ArchivedFinal],
        S::AppealPending->value => [S::AppealResolved],
        S::AppealResolved->value => [S::ArchivedFinal],
        S::ArchivedFinal->value => [],
    ];

    public static function allows(S $from, S $to): bool
    {
        return in_array($to, self::MAP[$from->value] ?? [], true);
    }
}
