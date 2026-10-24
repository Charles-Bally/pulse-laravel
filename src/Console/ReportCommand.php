<?php

declare(strict_types=1);

namespace Pulse\Laravel\Console;

use Illuminate\Console\Command;
use Pulse\Laravel\PulseFactory;
use Pulse\Logs\SpoolHandler;
use Pulse\Support\Loggers;

final class ReportCommand extends Command
{
    protected $signature = 'pulse-app:report';

    protected $description = 'Envoie un seul rapport à Pulse (essai, cron)';

    public function handle(PulseFactory $factory): int
    {
        SpoolHandler::mute();
        $runtime = $factory->runtime(Loggers::stderr());
        if ($runtime === null) {
            return self::FAILURE;
        }
        $ack = $runtime->reporter->reportNow();
        $report = $runtime->reporter->lastReport() ?? ['checks' => [], 'crons' => []];
        foreach ($report['checks'] as $check) {
            $this->line(sprintf('  %s %s (%d ms)%s', $check['ok'] ? 'ok    ' : 'échec ', $check['name'], $check['latencyMs'], isset($check['error']) ? " : {$check['error']}" : ''));
        }
        $this->line(sprintf('  %d tâche(s) planifiée(s)', count($report['crons'])));
        if ($ack === null) {
            $this->components->error('Rapport non accepté par Pulse.');

            return self::FAILURE;
        }
        $runtime->shipper?->flush();
        $this->components->info(sprintf('Rapport accepté par Pulse, prochain dans %d s.', $runtime->worker->state->intervalSeconds()));

        return self::SUCCESS;
    }
}
