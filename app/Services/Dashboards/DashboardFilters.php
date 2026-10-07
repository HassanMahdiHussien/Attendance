<?php

namespace App\Services\Dashboards;

use App\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class DashboardFilters
{
    public function __construct(
        public readonly ?int $branch,
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
        public readonly string $period,
        public readonly ?string $from = null,
        public readonly ?string $to = null,
    ) {}

    public static function fromInput(User $viewer, array $input): self
    {
        $data = validator($input, [
            'branch' => ['nullable', 'integer', 'min:1'],
            'period' => ['nullable', 'in:3,6,12,custom'],
            'from' => ['required_if:period,custom', 'nullable', 'date_format:Y-m-d'],
            'to' => ['required_if:period,custom', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ])->validate();
        $branch = isset($data['branch']) ? (int) $data['branch'] : null;
        if ($branch && ! Branch::query()->where('company_id', $viewer->company_id)->whereKey($branch)->exists()) {
            throw ValidationException::withMessages(['branch' => 'الفرع غير متاح.']);
        }
        $today = CarbonImmutable::now('Asia/Baghdad')->startOfDay();
        $period = $data['period'] ?? '6';
        $start = $period === 'custom' ? CarbonImmutable::parse($data['from'], 'Asia/Baghdad')->startOfDay() : $today->subMonths((int) $period);
        $end = $period === 'custom' ? CarbonImmutable::parse($data['to'], 'Asia/Baghdad')->addDay()->startOfDay() : $today->addDay();
        if ($start->gte($end) || $start->diffInDays($end, true) > 3660) {
            throw ValidationException::withMessages(['period' => 'النطاق الزمني غير صالح.']);
        }

        return new self($branch, $start, $end, $period, $data['from'] ?? null, $data['to'] ?? null);
    }

    public function previousStart(): CarbonImmutable
    {
        return $this->start->subSeconds($this->start->diffInSeconds($this->end, true));
    }
}
