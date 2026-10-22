<?php

namespace App\Services\Attendance;

use App\Models\Attendance\DeviceSetting;
use App\Models\Attendance\Transaction;
use App\Models\Company;
use Illuminate\Support\Collection;

class DeviceRoles
{
    private Collection $settings;

    public function load(Company $company, Collection $transactions): void
    {
        $this->settings = DeviceSetting::query()->where('company_id', $company->id)
            ->whereIn('device_id', $transactions->pluck('device_id')->unique()->all())
            ->orderByDesc('effective_from')->orderByDesc('id')->get()->groupBy('device_id');
    }

    public function at(Transaction $transaction): ?string
    {
        if ($transaction->device->status === 'pending') {
            return null;
        }
        $day = $transaction->punched_at->toDateString();
        foreach ($this->settings->get($transaction->device_id, collect()) as $setting) {
            if ($setting->effective_from->toDateString() <= $day) {
                return $setting->role;
            }
        }

        return null;
    }
}
