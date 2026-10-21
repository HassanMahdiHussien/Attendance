<?php

namespace App\Ai;

use App\Enums\DocumentType;

class DraftPrompt
{
    public function schema(array $languages, DocumentType $type): array
    {
        $body = ['type' => 'object', 'properties' => ['body' => ['type' => 'string']],
            'required' => ['body'], 'additionalProperties' => false];
        if ($type === DocumentType::Recommendations) {
            $body['properties']['secondary_body'] = ['type' => 'string'];
            $body['required'][] = 'secondary_body';
        }

        return ['type' => 'object', 'properties' => [
            'languages' => ['type' => 'object', 'properties' => array_fill_keys($languages, $body),
                'required' => $languages, 'additionalProperties' => false],
            'notes' => ['type' => 'array', 'items' => ['type' => 'string']],
        ], 'required' => ['languages', 'notes'], 'additionalProperties' => false];
    }

    public function messages(array $payload, array $languages, DocumentType $type, array $reasons = [], array $comparisonPairs = [], string $notesLanguage = 'ar'): array
    {
        $instruction = 'Draft only the case-specific prose in '.implode(', ', $languages).
            '. Arabic is authoritative; every other language renders the same claims. Use citations only as [[cite:<id>]] markers from candidate_units. '
            .'Never write article numbers or legal article text. Never invent facts, names, dates, numbers, outcomes or penalties. '
            .'Use Western digits (0-9) for every number in every language. '
            .'When stating the incident date, use incident.date_written for that language verbatim. '
            .'Names are masked as ⟦…⟧ tokens; copy each token exactly as given, character for character, in every language, and never translate or alter it. '
            .'Refer to the employee with the grammatical gender in incident.employee_gender (Arabic nouns, verbs and pronouns in the matching masculine or feminine form; he or she in English) in every language; use the conventional masculine form only when it is unspecified. Never let the minutes\' wording override a recorded gender. '
            .'Do not restate prior-record data; the template prints it. The statute prevails over an internal regulation. '
            .'Related cases are internal context only. Never print them or restate them as case facts unless HR entered those facts independently. '
            .'Do not state the operative decision, outcome, or penalty in prose; template wording supplies it. '
            .'Return short internal notes for missing or unclear information, never in the document body. '
            // HR reads these beside the letter: a real draft returned English notes naming payload fields and masking tokens.
            .'Write the notes in '.($notesLanguage === 'ar' ? 'Arabic' : 'English').' for an HR reviewer, in plain words: at most four, only points HR can check or complete, '
            .'never field names, JSON keys, ⟦…⟧ tokens or remarks about masking, gender wording or formatting.';
        if ($type === DocumentType::DefenseLetter) {
            $instruction .= ' intake_answers contain human-entered answers as facts; the questions are labels only, not facts. Never turn an unanswered question into an assertion.';
        }
        if ($type === DocumentType::Recommendations) {
            $instruction .= ' The employee_response is the employee’s own account. Summarize it in findings only when relevant; never quote numbers absent from the input. body is findings; secondary_body is reasons and additional recommendations without a penalty sentence.';
        }
        if ($type === DocumentType::DecisionLetter) {
            $instruction .= ' Draft only the reasons paragraph from the issued recommendations.';
        }
        if ($reasons) {
            $instruction .= ' Correct these validation failures: '.implode('; ', $reasons);
        }
        if ($comparisonPairs) {
            $instruction .= ' HR confirmed these law/regulation pairs: '.json_encode($comparisonPairs).'. For conflict pairs cite the statute only. Never cite an excluded regulation unit.';
        }
        if (($payload['regulation_comparison'] ?? null) === 'skipped_law_only') {
            $instruction .= ' HR skipped the comparison. Cite labour law only; cite no internal regulation.';
        }

        return [['role' => 'user', 'content' => $instruction."\n".json_encode($payload, JSON_UNESCAPED_UNICODE)]];
    }
}
