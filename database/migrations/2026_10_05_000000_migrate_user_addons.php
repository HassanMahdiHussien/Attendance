<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (config('displanery.permissions') as $name) {
            DB::table('permissions')->updateOrInsert(['name' => $name, 'guard_name' => 'web'], ['name' => $name, 'guard_name' => 'web']);
        }

        $permission = DB::table('permissions')->where('name', 'review_internal_regulation')->where('guard_name', 'web')->value('id');
        foreach (DB::table('users')->where('primary_role', 'company_legal_reviewer')->pluck('id') as $id) {
            DB::table('users')->where('id', $id)->update(['primary_role' => 'reporter', 'is_active' => false]);
            DB::table('model_has_permissions')->updateOrInsert(
                ['permission_id' => $permission, 'model_type' => User::class, 'model_id' => $id],
                ['permission_id' => $permission, 'model_type' => User::class, 'model_id' => $id],
            );
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->where('guard_name', 'web')->whereIn('name', ['review_internal_regulation', 'view_all_company_cases'])->pluck('id');
        DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
