<?php

namespace App\Livewire\Attendance;

use App\Enums\UserRole;
use App\Models\Attendance\ChatConversation;
use App\Models\Attendance\ChatProposal;
use App\Models\Attendance\ChatTurn;
use App\Models\Company;
use App\Models\Employee;
use App\Policies\EmployeeProfilePolicy;
use App\Services\Attendance\Ai\ChatModel;
use App\Services\Attendance\Ai\ChatProposals;
use App\Services\Attendance\Ai\ChatSettings;
use App\Services\Attendance\Ai\ChatTools;
use App\Services\Attendance\Ai\ChatTurns;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Assistant extends Component
{
    #[Locked]
    public ?int $companyId = null;

    #[Locked]
    public ?int $conversationId = null;

    public string $draft = '';

    public function mount(): void
    {
        $actor = auth()->user();
        abort_unless($actor?->is_active && app(EmployeeProfilePolicy::class)->search($actor), 403);
        if ($actor->primary_role === UserRole::HrStaff) {
            $this->companyId = (int) $actor->company_id;
        } elseif (request()->query('company') !== null) {
            abort_unless(ctype_digit((string) request()->query('company')), 404);
            $this->companyId = (int) request()->query('company');
        }
        if ($this->companyId !== null) {
            $this->company();
            $chosen = request()->query('conversation');
            if ($chosen !== null) {
                abort_unless(ctype_digit((string) $chosen), 404);
                $this->open((int) $chosen);
            }
        }
    }

    public function chooseCompany(int $companyId): void
    {
        abort_unless(auth()->user()?->primary_role === UserRole::PlatformAdmin, 403);
        $this->companyId = $companyId;
        $this->conversationId = null;
        $this->company();
    }

    public function clearCompany(): void
    {
        abort_unless(auth()->user()?->primary_role === UserRole::PlatformAdmin, 403);
        $this->companyId = null;
        $this->conversationId = null;
    }

    public function newConversation(ChatTurns $chat, ChatModel $model): void
    {
        abort_unless($model->available(), 403);
        $company = $this->company();
        $this->conversationId = $chat->start($company, auth()->user())->id;
        $this->draft = '';
    }

    public function open(int $id): void
    {
        $company = $this->company();
        $conversation = ChatConversation::query()->where('company_id', $company->id)->findOrFail($id);
        // Someone else's conversation answers like a missing one, so its existence is not revealed.
        abort_unless((int) $conversation->user_id === (int) auth()->id(), 404);
        app(ChatTurns::class)->view($conversation, auth()->user());
        $this->conversationId = $conversation->id;
    }

    public function send(ChatTurns $chat, ChatModel $model): void
    {
        $company = $this->company();
        abort_unless($model->available(), 403);
        $this->validate(['draft' => ['required', 'string', 'max:4000']]);
        if ($this->conversationId === null) {
            $this->conversationId = $chat->start($company, auth()->user())->id;
        }
        $chat->enqueue($this->selected($company), auth()->user(), $this->draft);
        $this->draft = '';
    }

    public function poll(): void
    {
        $company = $this->company();
        if ($this->conversationId !== null) {
            $this->selected($company);
        }
    }

    public function confirm(int $id, ChatProposals $proposals): void
    {
        $company = $this->company();
        $proposal = ChatProposal::query()->where('company_id', $company->id)->findOrFail($id);
        abort_unless($proposal->conversation_id === $this->selected($company)->id, 403);
        $proposals->confirm($proposal, auth()->user());
    }

    public function cancel(int $id, ChatProposals $proposals): void
    {
        $company = $this->company();
        $proposal = ChatProposal::query()->where('company_id', $company->id)->findOrFail($id);
        abort_unless($proposal->conversation_id === $this->selected($company)->id, 403);
        $proposals->cancel($proposal, auth()->user());
    }

    public function render(ChatModel $model, ChatTools $tools, ChatSettings $settings): View
    {
        $actor = auth()->user();
        abort_unless($actor?->is_active && app(EmployeeProfilePolicy::class)->search($actor), 403);
        $companies = $actor->primary_role === UserRole::PlatformAdmin
            ? Company::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']) : collect();
        $company = $this->companyId === null ? null : $this->company();
        $conversations = collect();
        $messages = collect();
        $turns = collect();
        $cards = [];
        $reports = [];
        $changesEnabled = false;
        if ($company !== null) {
            $conversations = ChatConversation::query()->where('company_id', $company->id)
                ->where('user_id', $actor->id)->orderByDesc('last_message_at')->orderByDesc('id')->limit(50)->get();
            $changesEnabled = $settings->enabled($company);
            if ($this->conversationId !== null) {
                $conversation = app(ChatTurns::class)->view($this->selected($company), $actor);
                $messages = $conversation->messages->whereIn('role', ['user', 'assistant'])
                    ->reject(fn ($message): bool => $message->role === 'assistant'
                        && ! in_array($message->status, ['final', 'terminal'], true));
                $turns = $conversation->turns->sortBy('sequence');
                $turns->load('toolCalls');
                $proposals = ChatProposal::query()->where('conversation_id', $conversation->id)
                    ->orderBy('id')->get();
                $employeeIds = [];
                foreach ($proposals as $proposal) {
                    foreach ($proposal->payload['employees'] as $entry) {
                        $employeeIds[] = $entry['employee_id'];
                        $employeeIds[] = $entry['before']['manager_employee_id'] ?? null;
                        $employeeIds[] = $entry['after']['manager_employee_id'] ?? null;
                    }
                }
                $employees = Employee::query()->where('company_id', $company->id)
                    ->whereIn('id', array_values(array_filter(array_unique($employeeIds))))->get()->keyBy('id');
                foreach ($proposals as $proposal) {
                    $cards[$proposal->turn_id][$proposal->id] = $this->card($proposal, $employees, $changesEnabled);
                }
                foreach ($turns as $turn) {
                    $reports[$turn->id] = $this->reports($turn, $company, $tools);
                }
            }
        }

        $pending = $turns->contains(fn (ChatTurn $turn): bool => in_array($turn->status, ['queued', 'running'], true));

        return view('livewire.attendance.assistant', compact('companies', 'company', 'conversations', 'messages',
            'turns', 'cards', 'reports', 'changesEnabled', 'pending') + ['available' => $model->available()])
            ->layout('layouts.workspace');
    }

    public function answer(array $content): string
    {
        $text = (string) ($content['text'] ?? '');
        $footer = (string) ($content['verified']['footer'] ?? '');
        if ($footer !== '' && str_ends_with($text, "\n\n".$footer)) {
            return substr($text, 0, -strlen("\n\n".$footer));
        }

        return $text;
    }

    public function terminalLabel(?string $code): string
    {
        $known = ['unavailable', 'refusal', 'step_limit', 'max_tokens',
            'model_context_window_exceeded', 'model_error'];

        return __('messages.chat_ui.terminal.'.(in_array($code, $known, true) ? $code : 'failed'));
    }

    private function reports(ChatTurn $turn, Company $company, ChatTools $tools): array
    {
        return $turn->toolCalls()->where('name', 'create_report')->where('is_error', false)->get()
            ->pluck('result.export_id')->filter()->unique()->map(function ($id) use ($tools, $company): array {
                $result = $tools->execute('report_status', ['export_id' => (int) $id], $company, auth()->user());

                return ['id' => (int) $id, 'status' => $result['status'] ?? 'unavailable',
                    'url' => ($result['status'] ?? null) === 'completed' ? ($result['download_url'] ?? null) : null];
            })->values()->all();
    }

    private function card(ChatProposal $proposal, Collection $employees, bool $changesEnabled): array
    {
        $entries = [];
        $allowed = $proposal->status === 'proposed' && $changesEnabled;
        foreach ($proposal->payload['employees'] as $entry) {
            $employee = $employees->get($entry['employee_id']);
            $allowed = $allowed && $employee !== null && Gate::forUser(auth()->user())->allows('update', $employee);
            $fields = [];
            foreach ($entry['after'] as $field => $after) {
                $fields[] = ['label' => __('messages.employee_history.fields.'.$field),
                    'before' => $this->value($field, $entry['before'][$field] ?? null, $employees),
                    'after' => $this->value($field, $after, $employees)];
            }
            $entries[] = ['name' => $employee?->name ?? '', 'code' => $entry['employee_code'], 'fields' => $fields];
        }

        return ['id' => $proposal->id, 'status' => $proposal->status,
            'reason' => $proposal->result['reason'] ?? null,
            'effective_from' => $proposal->payload['effective_from'], 'employees' => $entries,
            'can_decide' => $allowed];
    }

    private function value(string $field, mixed $value, Collection $employees): string
    {
        if ($value === null || $value === '') {
            return __('messages.chat_ui.empty');
        }

        return match ($field) {
            'schedule_start', 'schedule_end' => substr((string) $value, 0, 5),
            'rest_days' => implode(', ', array_map(fn ($day): string => __('messages.office_ui.weekdays.'.$day), $value)),
            'work_pattern' => __('messages.reporting_ui.patterns.'.$value),
            'manager_employee_id' => $employees->get((int) $value)?->name ?? __('messages.chat_ui.empty'),
            default => (string) $value,
        };
    }

    private function selected(Company $company): ChatConversation
    {
        abort_unless($this->conversationId !== null, 404);
        $conversation = ChatConversation::query()->where('company_id', $company->id)
            ->findOrFail($this->conversationId);
        abort_unless((int) $conversation->user_id === (int) auth()->id(), 404);

        return $conversation;
    }

    private function company(): Company
    {
        $actor = auth()->user();
        abort_unless($actor?->is_active && $this->companyId !== null
            && ($actor->primary_role === UserRole::PlatformAdmin
                || ($actor->primary_role === UserRole::HrStaff && (int) $actor->company_id === $this->companyId)), 404);
        $company = Company::query()->where('is_active', true)->findOrFail($this->companyId);
        abort_unless($actor->canAccessTenant($company), 404);
        app(TenantContext::class)->setCompany($company);

        return $company;
    }
}
