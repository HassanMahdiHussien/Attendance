<?php

namespace App\Enums;

enum DocumentType: string
{
    case DefenseLetter = 'defense_letter';
    case Recommendations = 'recommendations';
    case DecisionLetter = 'decision_letter';
}
