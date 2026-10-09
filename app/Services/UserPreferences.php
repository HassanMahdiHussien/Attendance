<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UserPreferences
{
    public function boardFilters(User $user): array
    {
        $preferences = is_array($user->preferences) ? $user->preferences : [];
        $saved = $preferences['board_filters'] ?? [];
        $valid = array_intersect_key($this->valid($user, is_array($saved) ? $saved : []), array_flip(['branch', 'stage']));
        if (array_key_exists('board_filters', $preferences) && ($valid !== $saved || $valid === ['branch' => '', 'stage' => ''])) {
            $this->saveBoardFilters($user, $valid);
        }

        return $valid;
    }

    public function valid(User $user, array $filters): array
    {
        $branch = is_scalar($filters['branch'] ?? '') ? (string) ($filters['branch'] ?? '') : '';
        $stage = is_scalar($filters['stage'] ?? '') ? (string) ($filters['stage'] ?? '') : '';
        $search = is_scalar($filters['search'] ?? '') ? (string) ($filters['search'] ?? '') : '';
        if ($branch !== '' && (! ctype_digit($branch) || ! Branch::query()->where('company_id', $user->company_id)->whereKey((int) $branch)->exists())) {
            $branch = '';
        }
        if (! in_array($stage, ['', '1', '2', '3', '4', '5'], true)) {
            $stage = '';
        }
        if (mb_strlen($search) > 100) {
            $search = '';
        }

        return compact('branch', 'stage', 'search');
    }

    public function saveBoardFilters(User $user, array $filters): void
    {
        $saved = array_intersect_key($this->valid($user, $filters), array_flip(['branch', 'stage']));
        $preferences = is_array($user->preferences) ? $user->preferences : [];
        if (! isset($preferences['board_filters']) && $saved === ['branch' => '', 'stage' => '']) {
            return;
        }
        if ($saved !== ['branch' => '', 'stage' => ''] && ($preferences['board_filters'] ?? null) === $saved) {
            return;
        }
        if ($saved === ['branch' => '', 'stage' => '']) {
            unset($preferences['board_filters']);
        } else {
            $preferences['board_filters'] = $saved;
        }
        DB::table('users')->where('id', $user->id)->update(['preferences' => $preferences ? json_encode($preferences, JSON_THROW_ON_ERROR) : null]);
        $user->preferences = $preferences;
        $user->syncOriginalAttribute('preferences');
    }
}
