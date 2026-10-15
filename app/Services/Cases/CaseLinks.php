<?php

namespace App\Services\Cases;

use App\Enums\CaseLinkType;
use App\Enums\UserRole;
use App\Models\CaseLink;
use App\Models\CaseLinkAnnotation;
use App\Models\DisciplinaryCase;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CaseEventLogger;
use App\Support\Access\CaseVisibility;
use App\Support\Search\SearchText;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CaseLinks
{
    public function __construct(private CaseEventLogger $events, private AuditLogger $audit) {}

    public function linkRelated(DisciplinaryCase $case, DisciplinaryCase $other, ?string $reason, User $actor): CaseLink
    {
        $this->requireEditor($actor);
        $this->requireVisible($actor, $case);
        $this->requireVisible($actor, $other);
        if ($case->company_id !== $other->company_id || $case->id === $other->id
            || ($case->incident_id !== null && $case->incident_id === $other->incident_id)
            || mb_strlen((string) $reason) > 500) {
            throw new \DomainException(__('messages.related.invalid_link'));
        }

        [$lower, $higher] = $case->id < $other->id ? [$case, $other] : [$other, $case];

        return DB::transaction(function () use ($lower, $higher, $reason, $actor): CaseLink {
            if (CaseLink::query()->where('case_id', $lower->id)->where('linked_case_id', $higher->id)
                ->where('link_type', CaseLinkType::Related->value)->exists()) {
                throw new \DomainException(__('messages.related.already_linked'));
            }
            $link = CaseLink::create(['company_id' => $lower->company_id, 'case_id' => $lower->id,
                'linked_case_id' => $higher->id, 'link_type' => CaseLinkType::Related->value,
                'reason' => filled($reason) ? trim($reason) : null, 'created_by_user_id' => $actor->id]);
            $this->events->log($lower, 'case_related_linked', ['case_link_id' => $link->id, 'related_case_id' => $higher->id], actor: $actor);
            $this->events->log($higher, 'case_related_linked', ['case_link_id' => $link->id, 'related_case_id' => $lower->id], actor: $actor);
            $this->audit->log('case.related_linked', $link, ['case_id' => $lower->id, 'related_case_id' => $higher->id], $actor);

            return $link;
        });
    }

    public function markInError(CaseLink $link, string $reason, User $actor): CaseLinkAnnotation
    {
        $this->requireEditor($actor);
        if ($link->link_type !== CaseLinkType::Related || blank($reason) || mb_strlen($reason) > 500) {
            throw new \DomainException(__('messages.related.invalid_annotation'));
        }
        $lower = DisciplinaryCase::query()->findOrFail($link->case_id);
        $higher = DisciplinaryCase::query()->findOrFail($link->linked_case_id);
        $this->requireVisible($actor, $lower);
        $this->requireVisible($actor, $higher);

        return DB::transaction(function () use ($link, $reason, $actor, $lower, $higher): CaseLinkAnnotation {
            if ($link->annotations()->where('kind', 'linked_in_error')->exists()) {
                throw new \DomainException(__('messages.related.already_annotated'));
            }
            $annotation = CaseLinkAnnotation::create(['company_id' => $link->company_id, 'case_link_id' => $link->id,
                'kind' => 'linked_in_error', 'reason' => trim($reason), 'created_by_user_id' => $actor->id,
                'created_at' => now()]);
            $this->events->log($lower, 'case_related_link_in_error', ['case_link_id' => $link->id, 'related_case_id' => $higher->id], actor: $actor);
            $this->events->log($higher, 'case_related_link_in_error', ['case_link_id' => $link->id, 'related_case_id' => $lower->id], actor: $actor);
            $this->audit->log('case.related_link_in_error', $annotation, ['case_link_id' => $link->id], $actor);

            return $annotation;
        });
    }

    public function candidates(User $actor, DisciplinaryCase $case, string $q): Collection
    {
        $this->requireEditor($actor);
        $this->requireVisible($actor, $case);
        $linkedIds = CaseLink::query()->where('link_type', CaseLinkType::Related->value)
            ->where(fn ($query) => $query->where('case_id', $case->id)->orWhere('linked_case_id', $case->id))
            ->get()->map(fn (CaseLink $link) => $link->case_id === $case->id ? $link->linked_case_id : $link->case_id);
        $needle = SearchText::normalize(trim($q));
        $query = CaseVisibility::for($actor)->join('employees as related_employees', function ($join): void {
            $join->on('related_employees.id', '=', 'cases.employee_id')
                ->on('related_employees.company_id', '=', 'cases.company_id');
        })->where('cases.company_id', $case->company_id)->where('cases.id', '<>', $case->id)
            ->whereNotIn('cases.id', $linkedIds)->select('cases.*')->with('employee');
        if ($case->incident_id !== null) {
            $query->where(fn ($members) => $members->whereNull('cases.incident_id')
                ->orWhere('cases.incident_id', '<>', $case->incident_id));
        }
        if ($needle !== '') {
            $like = '%'.addcslashes($needle, '%_\\').'%';
            $query->where(function ($search) use ($like, $needle): void {
                $search->where('related_employees.search_name', 'like', $like);
                if (ctype_digit($needle)) {
                    $search->orWhereRaw('CAST(cases.case_number AS CHAR) LIKE ?', [$like]);
                }
            });
        }

        return $query->orderByDesc('cases.id')->limit(10)->get();
    }

    public function visibleRelated(DisciplinaryCase $case, User $viewer): Collection
    {
        $links = CaseLink::query()->where('link_type', CaseLinkType::Related->value)
            ->where(fn ($query) => $query->where('case_id', $case->id)->orWhere('linked_case_id', $case->id))
            ->with('annotations')->orderByDesc('id')->get();
        if ($links->isEmpty()) {
            return collect();
        }
        $otherIds = $links->map(fn (CaseLink $link) => $link->case_id === $case->id ? $link->linked_case_id : $link->case_id);
        $visible = CaseVisibility::for($viewer)->where('cases.company_id', $case->company_id)
            ->whereIn('cases.id', $otherIds)->with('employee')->get()->keyBy('id');

        return $links->map(function (CaseLink $link) use ($case, $visible): ?array {
            $otherId = $link->case_id === $case->id ? $link->linked_case_id : $link->case_id;
            $other = $visible->get($otherId);

            return $other ? ['link' => $link, 'case' => $other,
                'in_error' => $link->annotations->firstWhere('kind', 'linked_in_error')] : null;
        })->filter()->values();
    }

    private function requireEditor(User $actor): void
    {
        if (! $actor->is_active || ! $actor->company?->is_active
            || ! in_array($actor->primary_role, [UserRole::CompanyAdmin, UserRole::HrStaff], true)) {
            throw new \DomainException(__('messages.related.hr_only'));
        }
    }

    private function requireVisible(User $actor, DisciplinaryCase $case): void
    {
        if ($actor->company_id !== $case->company_id || ! CaseVisibility::for($actor)->whereKey($case->id)->exists()) {
            throw new \DomainException(__('messages.related.case_unavailable'));
        }
    }
}
