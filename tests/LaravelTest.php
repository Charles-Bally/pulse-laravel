<?php

declare(strict_types=1);

namespace Pulse\Laravel\Tests;

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use Pulse\Http\Response;
use Pulse\Http\Transport;
use Pulse\Laravel\Facades\Pulse;
use Pulse\Laravel\PulseFactory;
use Pulse\Laravel\PulseServiceProvider;
use Pulse\Logs\LogSpool;
use Pulse\Logs\SpoolHandler;
use Pulse\Storage\StateStore;
use Pulse\Support\Loggers;

final class LaravelTest extends TestCase
{
    private const KEY = 'pk_test_8f2aQx3f9aB7cD1eF5gH9iJ2kL4mN6oP8qR';

    private string $directory;
    private RecordingTransport $transport;

    protected function getPackageProviders($app): array
    {
        return [PulseServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->directory = sys_get_temp_dir() . '/pulse-laravel-' . bin2hex(random_bytes(4));
        $config = $app['config'];
        $config->set('pulse-app.key', self::KEY);
        $config->set('pulse-app.directory', $this->directory);
        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $config->set('cache.default', 'redis');
        $config->set('queue.default', 'database');
        $config->set('queue.connections.database', ['driver' => 'database', 'table' => 'jobs', 'queue' => 'emails']);
        $config->set('database.redis.default', ['host' => '127.0.0.1', 'port' => 1]);
        $config->set('database.redis.cache', ['host' => '127.0.0.1', 'port' => 1]);
        $config->set('logging.default', 'single');
        $config->set('logging.channels.single', ['driver' => 'single', 'path' => $this->directory . '/laravel.log']);
        $this->transport = new RecordingTransport();
        $app->instance(Transport::class, $this->transport);
    }

    public function testIsDiscoveredByLaravel(): void
    {
        $composer = json_decode((string) file_get_contents(__DIR__ . '/../composer.json'), true);
        self::assertSame([PulseServiceProvider::class], $composer['extra']['laravel']['providers']);
        self::assertTrue($this->app->providerIsLoaded(PulseServiceProvider::class));
        self::assertNotNull(config('pulse-app.auto_detect'));
    }

    public function testDetectsDatabaseRedisAndQueue(): void
    {
        Schema::create('jobs', static function (Blueprint $table): void {
            $table->id();
            $table->string('queue');
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        $runtime = $this->app->make(PulseFactory::class)->runtime(Loggers::none());
        self::assertSame(['SQLite', 'Redis', 'File emails'], $runtime?->reporter->probeNames());
        $checks = array_column($runtime->reporter->buildReport()['checks'], null, 'name');
        self::assertSame([true, 'database', 'sqlite'], [$checks['SQLite']['ok'], $checks['SQLite']['kind'], $checks['SQLite']['engine']]);
        self::assertSame([true, 'queue', 'database'], [$checks['File emails']['ok'], $checks['File emails']['kind'], $checks['File emails']['engine']]);
        self::assertFalse($checks['Redis']['ok']);

        config(['cache.default' => 'array', 'queue.default' => 'sync', 'session.driver' => 'file']);
        self::assertSame(['SQLite'], $this->app->make(PulseFactory::class)->runtime(Loggers::none())?->reporter->probeNames());
    }

    public function testReadsTheScheduleAndRecordsRuns(): void
    {
        $schedule = $this->app->make(Schedule::class);
        $schedule->command('inspire')->hourly();
        $nightly = $schedule->call(static fn () => null)->dailyAt('03:00')->name('nettoyage');
        $runtime = $this->app->make(PulseFactory::class)->runtime(Loggers::none());

        $command = $schedule->events()[0];
        $command->exitCode = 1;
        event(new ScheduledTaskFinished($command, 0.2));
        event(new ScheduledTaskFinished($nightly, 0.1));
        event(new ScheduledTaskFailed($nightly, new \RuntimeException('disque plein password=secret')));
        self::assertSame('ok', Pulse::track('relance-abonnements', static fn () => 'ok'));

        $crons = array_column($runtime?->reporter->buildReport()['crons'] ?? [], null, 'name');
        self::assertSame(['0 * * * *', false, 'code de sortie 1'], [$crons['inspire']['schedule'], $crons['inspire']['lastRunOk'], $crons['inspire']['error']]);
        self::assertSame(['0 3 * * *', false, 'disque plein password=***'], [$crons['nettoyage']['schedule'], $crons['nettoyage']['lastRunOk'], $crons['nettoyage']['error']]);
        self::assertArrayHasKey('nextRunAt', $crons['nettoyage']);
        self::assertTrue($crons['relance-abonnements']['lastRunOk']);
    }

    public function testCopiesLogsOfTheDefaultChannelWithoutRemovingItsHandlers(): void
    {
        $monolog = Log::channel()->getLogger();
        $handlers = $monolog->getHandlers();
        self::assertCount(2, $handlers);
        self::assertInstanceOf(SpoolHandler::class, end($handlers));

        Log::error('avant l’accord de Pulse');
        self::assertSame([], LogSpool::in($this->directory)->drain());
        (new StateStore($this->directory . '/state.json'))->write(true, false, 60);
        Log::error('Paiement refusé token=abc123');
        $entries = LogSpool::in($this->directory)->drain();
        self::assertSame(['ERROR', 'Paiement refusé token=***'], [$entries[0]['level'], $entries[0]['message']]);
        self::assertStringContainsString('Paiement refusé token=abc123', (string) file_get_contents($this->directory . '/laravel.log'));
    }

    public function testCommandsSendTheReport(): void
    {
        $this->artisan('pulse-app:report')->assertExitCode(0);
        [$url, $headers, $body] = $this->transport->calls[0];
        self::assertSame('https://api.pulse-app.si/ingest/report', $url);
        self::assertSame(self::KEY, $headers['x-pulse-key']);
        $report = json_decode($body, true);
        self::assertSame(['pulse-laravel', 'laravel', $this->app->version()], [$report['packageName'], $report['framework'], $report['frameworkVersion']]);
        self::assertSame(\Pulse\Laravel\PulseFactory::VERSION, $report['packageVersion']);

        $this->transport->calls = [];
        $this->artisan('pulse-app:work', ['--for' => '2.5'])->assertExitCode(0);
        self::assertCount(1, $this->transport->calls);

        config(['pulse-app.key' => null]);
        $this->artisan('pulse-app:report')->assertExitCode(1);
    }
}

final class RecordingTransport implements Transport
{
    /** @var list<array{0: string, 1: array<string, string>, 2: string}> */
    public array $calls = [];

    public function post(string $url, array $headers, string $body): Response
    {
        $this->calls[] = [$url, $headers, $body];

        return new Response(202, '{"accepted":true,"intervalSeconds":30,"live":false,"logs":false}');
    }
}
