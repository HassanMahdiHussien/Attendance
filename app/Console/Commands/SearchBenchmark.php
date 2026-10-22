<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\Search\GlobalSearch;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SearchBenchmark extends Command
{
    protected $signature = 'search:benchmark';

    protected $description = 'Measure scoped search over 10,000 employees';

    public function handle(GlobalSearch $search): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('The search benchmark is available only in local and testing environments.');

            return self::FAILURE;
        }

        $viewer = User::query()->where('primary_role', UserRole::HrStaff->value)->first();
        if (! $viewer || ! ($branch = DB::table('branches')->where('company_id', $viewer->company_id)->first())) {
            $this->error('Seed an HR staff user and branch before running this benchmark.');

            return self::FAILURE;
        }

        app(TenantContext::class)->setCompany($viewer->company);
        $marker = 'BENCH'.now()->format('YmdHisv');
        DB::beginTransaction();
        try {
            $now = now();
            for ($offset = 0; $offset < 10000; $offset += 500) {
                $rows = [];
                for ($i = $offset; $i < $offset + 500; $i++) {
                    $rows[] = [
                        'company_id' => $viewer->company_id,
                        'branch_id' => $branch->id,
                        'employee_code' => $marker.$i,
                        'name' => 'Benchmark Employee '.$i,
                        'search_name' => 'benchmark employee '.$i,
                        'department' => 'Benchmark',
                        'position' => 'Benchmark',
                        'hire_date' => '2020-01-01',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                DB::table('employees')->insert($rows);
            }

            foreach (['Benchmark Employee 4999', $marker.'4999'] as $term) {
                $started = hrtime(true);
                $results = $search->for($viewer, $term);
                $this->line($term.': '.round((hrtime(true) - $started) / 1_000_000, 2).' ms; '.count($results['employees']).' employees');
            }

            $this->line('Employee query plan:');
            foreach (DB::select('EXPLAIN SELECT id FROM employees WHERE company_id = ? AND search_name LIKE ?', [$viewer->company_id, '%benchmark%']) as $row) {
                $this->line(json_encode($row, JSON_UNESCAPED_UNICODE));
            }
        } finally {
            DB::rollBack();
        }

        return self::SUCCESS;
    }
}
