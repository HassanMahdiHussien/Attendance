<?php

// Readable field names for Livewire properties; messages come from Laravel's own English file (merged with this one).
return [
    'custom' => [
        'occurredAt' => ['before_or_equal' => 'The :attribute cannot be in the future.'],
        'violationTime' => ['before_or_equal' => 'The :attribute cannot be in the future.'],
    ],

    'attributes' => [
        'assignedHrUserId' => 'assigned HR officer', 'assigneeId' => 'assignee',
        'attemptLocation' => 'delivery attempt place', 'attemptedAt' => 'delivery attempt time', 'attemptedBy' => 'delivered by',
        'bodies.*' => 'text', 'branchId' => 'branch', 'caseDescription' => 'case description', 'category' => 'category',
        'chairId' => 'chair', 'clearanceReason' => 'clearance reason', 'comment' => 'comment',
        'consultationCancelReason' => 'cancellation reason', 'correctionReason' => 'correction reason',
        'decisionSignaturePresence.general_manager' => 'general manager signature', 'decisionSignaturePresence.union' => 'union representative signature',
        'defenseAcceptance' => 'defense acceptance', 'description' => 'description', 'email' => 'email',
        'employeeId' => 'employee', 'filedAt' => 'appeal filing date', 'handoffPurpose' => 'hand-off purpose',
        'handoffStatus' => 'hand-off status', 'incidentClosureReason' => 'closure reason', 'incidentEmployeeId' => 'employee',
        'locale' => 'language', 'locationId' => 'location', 'mapViolationTypeId' => 'violation type', 'minutes' => 'session minutes',
        'minutesTypedBy' => 'minutes author', 'note' => 'note', 'occurredAt' => 'incident date and time', 'outcome' => 'outcome',
        'password' => 'password', 'place' => 'place', 'presence' => 'signature status', 'reason' => 'reason',
        'reference' => 'reference number', 'relatedCaseId' => 'related case', 'relatedReason' => 'link reason',
        'responseCorrectionReason' => 'correction reason', 'responseOrigin' => 'response source', 'responseScan' => 'response scan',
        'responseText' => 'response text', 'responsibleEmployeeId' => 'responsible person', 'responsibleName' => 'responsible person name',
        'scan' => 'scan', 'selected' => 'attendees', 'signedAt' => 'signing date', 'violationTime' => 'incident date and time',
        'violationType' => 'violation', 'violationTypeId' => 'violation type', 'witnessNames' => 'witness names',
        'witnessOne' => 'first witness', 'witnessTwo' => 'second witness',
    ],
];
