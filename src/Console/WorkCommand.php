<?php

declare(strict_types=1);

namespace Pulse\Laravel\Console;

use Illuminate\Console\Command;
use Pulse\Laravel\PulseFactory;
use Pulse\Logs\SpoolHandler;
use Pulse\Support\Loggers;

final class WorkCommand extends Command
{
    protected $signature = 'pulse-app:work {--for= : arrête le worker après ce nombre de secondes}';

    protected $description = 'Envoie l’état de l’app à Pulse en continu (sous Supervisor, systemd ou Docker)';

    public function handle(PulseFactory $factory): int
    {
        SpoolHandler::mute();
        $runtime = $factory->runtime(Loggers::both(Loggers::psr($this->laravel['log']), Loggers::stderr()));
        if ($runtime === null) {
            return self::SUCCESS;
        }
        $probes = $runtime->reporter->probeNames();
        $this->components->info('Pulse surveille l’app' . ($probes === [] ? '.' : ' : ' . implode(', ', $probes) . '.'));
        $for = $this->option('for');
        $runtime->worker->run(is_numeric($for) ? (float) $for : null);

        return self::SUCCESS;
    }
}
