<?php

declare(strict_types=1);

namespace Pulse\Laravel\Detection;

use Illuminate\Console\Application as Artisan;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

final class LaravelSchedule
{
    /** @return list<array{name: string, schedule: string, nextRunAt: ?\DateTimeInterface}> */
    public static function snapshots(Schedule $schedule): array
    {
        $out = [];
        foreach ($schedule->events() as $event) {
            try {
                $next = $event->nextRunDate();
            } catch (\Throwable) {
                $next = null;
            }
            $out[] = ['name' => self::name($event), 'schedule' => $event->expression, 'nextRunAt' => $next];
        }

        return $out;
    }

    // Le même nom sert au planning et aux passages : c'est lui qui relie les deux.
    public static function name(Event $event): string
    {
        if (is_string($event->description) && trim($event->description) !== '') {
            return trim($event->description);
        }
        if (is_string($event->command) && $event->command !== '') {
            $command = str_replace([Artisan::phpBinary(), Artisan::artisanBinary()], ['php', 'artisan'], $event->command);
            $command = (string) preg_replace('/^php\s+artisan\s+/', '', trim($command));

            return trim(str_replace("'", '', $command));
        }

        return $event->getSummaryForDisplay();
    }
}
