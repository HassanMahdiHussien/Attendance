<?php

namespace App\Enums;

enum SignaturePurpose: string
{
    case DefenseLetterReceipt = 'defense_letter_receipt';
    case ResponseSubmission = 'response_submission';
    case DecisionLetterReceipt = 'decision_letter_receipt';
    case CommitteeSignature = 'committee_signature';
    case UnionSignature = 'union_signature';
    case GeneralManagerSignature = 'general_manager_signature';
}
