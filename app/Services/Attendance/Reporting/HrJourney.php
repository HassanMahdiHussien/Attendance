<?php

namespace App\Services\Attendance\Reporting;

use App\Models\Attendance\Device;
use App\Models\Attendance\ImportBatchEvent;
use App\Models\Company;
use App\Support\Access\AdminNav;

class HrJourney
{
    public function for(Company $company, array $freshness): array
    {
        $uploaded = ImportBatchEvent::query()->where('company_id', $company->id)->where('state', 'completed')->exists();
        $needsDevice = Device::query()->where('company_id', $company->id)->where('status', 'pending')->exists();
        $current = ! $uploaded ? 'upload' : ($needsDevice ? 'check' : ($freshness['results_updating'] ? 'results' : 'review'));
        $names = ['upload', 'check', 'results', 'review'];
        $steps = [];
        foreach ($names as $name) {
            $steps[] = ['key' => $name, 'state' => array_search($name, $names, true) < array_search($current, $names, true)
                ? 'done' : ($name === $current ? 'current' : 'todo')];
        }
        $items = $current === 'upload' ? AdminNav::uploadItems(auth()->user()) : AdminNav::attendanceItems(auth()->user());
        $action = null;
        if ($current === 'results') {
            $action = ['label' => __('messages.clear_screens.view_data_status'), 'url' => '#data-status'];
        } elseif ($current === 'review' && ($freshness['actions'] ?? []) !== []) {
            $action = $freshness['actions'][0];
        } else {
            $item = $current === 'check'
                ? collect($items)->first(fn (array $item): bool => str_contains($item['url'], 'devices'))
                : ($current === 'review' ? collect($items)->first(fn (array $item): bool => str_contains($item['url'], 'review')) : ($items[0] ?? null));
            if ($item) {
                $action = ['label' => $item['label'], 'url' => $item['url']];
            }
        }

        return ['steps' => $steps, 'action' => $action];
    }
}
