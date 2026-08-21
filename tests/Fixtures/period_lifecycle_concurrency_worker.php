<?php

use App\Models\Period;
use App\Models\PeriodArchive;
use App\Models\User;
use App\Services\PeriodLifecycleService;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $action, $periodId, $actorId, $readyPath, $barrierPath] = $argv;

touch($readyPath);
$deadline = microtime(true) + 15;
while (! file_exists($barrierPath)) {
    if (microtime(true) >= $deadline) {
        fwrite(STDERR, 'Concurrency barrier zaman asimina ugradi.');
        exit(2);
    }

    usleep(10_000);
}

try {
    $service = app(PeriodLifecycleService::class);
    $actor = User::query()->findOrFail((int) $actorId);

    $result = match ($action) {
        'activate' => $service->activate((int) $periodId, $actor, 'Eszamanli aktivasyon testi.'),
        'complete' => $service->complete(
            (int) $periodId,
            $actor,
            'Eszamanli tamamlama testi.',
            function (Period $period) use ($actor): PeriodArchive {
                return PeriodArchive::query()->create([
                    'period_id' => $period->id,
                    'project_id' => $period->project_id,
                    'closed_by' => $actor->id,
                    'closed_at' => now(),
                    'archive_version' => 1,
                    'schema_version' => 1,
                    'summary_json' => [],
                    'warnings_json' => [],
                    'counts_json' => [],
                    'integrity_hash' => hash('sha256', "concurrency-{$period->id}"),
                ]);
            },
        ),
        default => throw new InvalidArgumentException("Bilinmeyen concurrency aksiyonu: {$action}"),
    };

    $period = $result instanceof Period ? $result : $result['period'];
    echo json_encode(['status' => 200, 'period_status' => $period->status], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500;
    echo json_encode([
        'status' => $status,
        'exception' => $exception::class,
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR);
}

