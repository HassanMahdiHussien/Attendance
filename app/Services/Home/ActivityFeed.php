<?php

namespace App\Services\Home;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class ActivityFeed
{
    public function merge(Collection $events): Collection
    {
        $lines = collect();
        $previousKey = null;

        foreach ($events as $event) {
            $key = implode('|', [$event->event_type, $event->event_type === 'status_changed' ? ($event->payload['to'] ?? '') : '',
                $event->case_id, $event->actor_user_id,
                CarbonImmutable::parse($event->created_at)->format('Y-m-d H:i')]);
            if ($key === $previousKey) {
                $index = $lines->count() - 1;
                $line = $lines->get($index);
                $line['count']++;
                $lines->put($index, $line);

                continue;
            }

            $lines->push(['event' => $event, 'count' => 1]);
            $previousKey = $key;
        }

        return $lines;
    }
}
