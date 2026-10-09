<?php

namespace App\Livewire\Concerns;

use App\Models\IntakeCheck;
use App\Services\Intake\IntakeQuestionService;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

trait ChecksDescription
{
    public ?int $intakeCheckId = null;

    public array $intakeAnswers = [];

    abstract protected function intakeFields(): array;

    abstract protected function authorizeIntakeCheck(): void;

    public function checkDescription(IntakeQuestionService $service): void
    {
        $this->authorizeIntakeCheck();
        $this->intakeCheckId = $service->start(auth()->user(), $this->intakeFields())->id;
        $this->intakeAnswers = [];
    }

    public function intakeCheck(): ?IntakeCheck
    {
        if ($this->intakeCheckId === null) {
            return null;
        }
        try {
            return app(IntakeQuestionService::class)->ownedCheck($this->intakeCheckId, auth()->user(), $this->intakeFields());
        } catch (ValidationException $exception) {
            return null;
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() !== 409) {
                throw $exception;
            }

            return null;
        }
    }
}
