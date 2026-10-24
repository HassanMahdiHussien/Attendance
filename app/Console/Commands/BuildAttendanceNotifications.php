<?php

namespace App\Console\Commands;

use App\Models\Attendance\NotificationBatch;
use App\Models\Company;
use App\Services\Attendance\FollowUp\NotificationBatches;
use App\Services\Attendance\FollowUp\NotificationSettings;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class BuildAttendanceNotifications extends Command
{
    protected $signature = 'attendance:build-notifications';

    protected $description = 'Build due attendance follow-up notifications';

    public function handle(NotificationBatches $batches, NotificationSettings $settings): int
    {
        $now = CarbonImmutable::now('Asia/Baghdad');
        $key = 'scheduled:'.$now->toDateString();
        Company::query()->where('is_active', true)->orderBy('id')->chunkById(100,
            function ($companies) use ($batches, $settings, $now, $key): void {
                foreach ($companies as $company) {
                    app(TenantContext::class)->runForCompany($company, function () use ($company, $batches, $settings, $now, $key): void {
                        $setting = $settings->forCompany($company);
                        if ($now->format('H:i:s') < $setting->build_time
                            || NotificationBatch::query()->where('run_key', $key)->exists()) {
                            return;
                        }
                        $batches->build($company, null, $key);
                    });
                }
            });

        return self::SUCCESS;
    }
}
