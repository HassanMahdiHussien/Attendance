<?php

namespace App\Enums;

enum Outcome: string
{
    case RecommendationOnly = 'recommendation_only';
    case Warning = 'warning';
    case Termination = 'termination';
}
