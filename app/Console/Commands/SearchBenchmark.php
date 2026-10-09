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

    protected $description = 'Measure scoped search over 10,000 employees and 5,000 cases';

    public function handle(GlobalSearch $search): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('The search benchmark is available only in local and testing environments.');

            return self::FAILURE;
        }
        $viewer = User::query()->where('primary_role', UserRole::CompanyAdmin->value)->first();
        if (! $viewer || ! ($branch = DB::table('branches')->where('company_id', $viewer->company_id)->first())) {
            $this->error('Seed a company admin and branch before running this benchmark.');

            return self::FAILURE;
        }
        app(TenantContext::class)->setCompany($viewer->company);
        $marker = 'BENCH'.now()->format('YmdHisv');
        DB::beginTransaction();
        try {
            $start = (int) DB::table('cases')->where('company_id', $viewer->company_id)->max('case_number') + 1;
            $now = now();
            for ($offset = 0; $offset < 10000; $offset += 500) {
                $rows = [];
                for ($i = $offset; $i < $offset + 500; $i++) {
                    $rows[] = ['company_id' => $viewer->company_id, 'branch_id' => $branch->id,
                        'employee_code' => $marker.$i, 'name' => 'أحمد '.$i, 'search_name' => 'احمد '.$i,
                        'department' => 'Benchmark', 'position' => 'Benchmark', 'hire_date' => '2020-01-01',
                        'created_at' => $now, 'updated_at' => $now];
                }
                DB::table('employees')->insert($rows);
            }
            $ids = DB::table('employees')->where('company_id', $viewer->company_id)
                ->where('employee_code', 'like', $marker.'%')->orderBy('id')->pluck('id');
            for ($offset = 0; $offset < 5000; $offset += 500) {
                $rows = [];
                for ($i = $offset; $i < $offset + 500; $i++) {
                    $rows[] = ['company_id' => $viewer->company_id, 'branch_id' => $branch->id,
                        'employee_id' => $ids[$i], 'case_number' => $start + $i,
                        'jurisdiction_at_opening' => 'federal_iraq', 'opened_by_user_id' => $viewer->id,
                        'violation_time' => $now, 'violation_type' => 'Benchmark',
                        'created_at' => $now, 'updated_at' => $now];
                }
                DB::table('cases')->insert($rows);
            }
            foreach ([str_pad((string) ($start + 3), 4, '0', STR_PAD_LEFT), 'أحمد 4999', $marker.'4999'] as $term) {
                $started = hrtime(true);
                $results = $search->for($viewer, $term);
                $this->line($term.': '.round((hrtime(true) - $started) / 1_000_000, 2).' ms; '.count($results['cases']).' cases, '.count($results['employees']).' employees');
            }
            $this->line('Employee query plan:');
            foreach (DB::select('EXPLAIN SELECT id FROM employees WHERE company_id = ? AND search_name LIKE ?', [$viewer->company_id, '%احمد%']) as $row) {
                $this->line(json_encode($row, JSON_UNESCAPED_UNICODE));
            }
            $this->line('Case query plan:');
            foreach (DB::select('EXPLAIN SELECT cases.id FROM cases JOIN employees ON employees.id = cases.employee_id AND employees.company_id = cases.company_id WHERE cases.company_id = ? AND (cases.case_number = ? OR employees.search_name LIKE ?) LIMIT 8', [$viewer->company_id, $start + 3, '%احمد%']) as $row) {
                $this->line(json_encode($row, JSON_UNESCAPED_UNICODE));
            }
        } finally {
            DB::rollBack();
        }

        return self::SUCCESS;
    }
}
