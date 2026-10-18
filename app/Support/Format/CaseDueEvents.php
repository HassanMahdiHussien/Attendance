<?php

namespace App\Support\Format;

use App\Enums\CaseStatus;
use App\Models\DisciplinaryCase;
use App\Services\Cases\Deadlines;

class CaseDueEvents
{
    public static function for(DisciplinaryCase $case, Deadlines $deadlines): array
    {
        $closed = $case->status === CaseStatus::ArchivedFinal;
        $finalStage = $closed ? __('Closed') : StageIndicator::stageLabel(5);

        return [
            ['title' => __('Response'), 'stage' => StageIndicator::stageLabel(3),
                'due' => $deadlines->responseDue($case), 'state' => $deadlines->responseStatus($case)],
            ['title' => __('Procedure'), 'stage' => $finalStage,
                'due' => $deadlines->procedureDue($case), 'state' => $deadlines->procedureStatus($case)],
            ['title' => __('Appeal'), 'stage' => $finalStage,
                'due' => $deadlines->appealEnd($case),
                'state' => $closed && $case->decision_issued_at === null ? 'not_applicable' : $deadlines->appealStatus($case)],
        ];
    }
}
