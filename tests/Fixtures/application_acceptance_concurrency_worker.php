<?php

use App\Models\Application;
use App\Models\Period;
use App\Models\Project;
use App\Models\User;
use App\Services\ApplicationDecisionService;
use App\Services\ApplicationSubmissionService;
use App\Services\NotificationService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\Sanctum;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $targetId, $actorId, $path, $readyPath, $barrierPath] = $argv;
Http::preventStrayRequests();
$notifications = Mockery::mock(NotificationService::class);
$notifications->shouldReceive('sendTemplatedEmail', 'sendEmail')->andReturn(1);
$app->instance(NotificationService::class, $notifications);
Password::shouldReceive('sendResetLink')->andReturn(Password::RESET_LINK_SENT);

// Both requests have loaded their initial state before competing for the lock.
$app->bind(ApplicationDecisionService::class, fn () => new class($readyPath, $barrierPath) extends ApplicationDecisionService
{
    public function __construct(private string $readyPath, private string $barrierPath) {}

    public function runLocked(Application $application, Closure $decision): Application
    {
        touch($this->readyPath);
        $deadline = microtime(true) + 20;
        while (! file_exists($this->barrierPath)) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Basvuru testi eszamanlilik bekleme suresi doldu.');
            }
            usleep(10_000);
        }

        return parent::runLocked($application, function (Application $current) use ($decision) {
            usleep(200_000);
            $decision($current);
        });
    }
});

// Submission requests wait at the same gate after their initial controller read.
$app->bind(ApplicationSubmissionService::class, fn () => new class($readyPath, $barrierPath) extends ApplicationSubmissionService
{
    public function __construct(private string $readyPath, private string $barrierPath) {}

    public function runLocked(User $user, Project $project, Closure $submission): Application
    {
        $this->waitAtGate();

        return parent::runLocked($user, $project, function (User $currentUser, Project $currentProject, ?Period $period) use ($submission) {
            usleep(200_000);

            return $submission($currentUser, $currentProject, $period);
        });
    }

    public function runLockedForGuest(Project $project, Closure $resolveUser, Closure $submission): Application
    {
        $this->waitAtGate();

        return parent::runLockedForGuest($project, $resolveUser, function (User $currentUser, Project $currentProject, ?Period $period) use ($submission) {
            usleep(200_000);

            return $submission($currentUser, $currentProject, $period);
        });
    }

    private function waitAtGate(): void
    {
        touch($this->readyPath);
        $deadline = microtime(true) + 20;
        while (! file_exists($this->barrierPath)) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Basvuru gonderim testi eszamanlilik bekleme suresi doldu.');
            }
            usleep(10_000);
        }
    }
});

if ($path !== 'guest_submit') {
    Sanctum::actingAs(User::query()->findOrFail((int) $actorId));
}
if ($path === 'invite') {
    // Invitation reservations and final acceptance start together.
    touch($readyPath);
    $deadline = microtime(true) + 20;
    while (! file_exists($barrierPath)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Yedek daveti testi eszamanlilik bekleme suresi doldu.');
        }
        usleep(10_000);
    }
}
[$method, $url, $payload] = match ($path) {
    'invite' => ['POST', "/api/panel/applications/{$targetId}/waitlist-invite", []],
    'waitlist' => ['POST', "/api/applications/{$targetId}/waitlist-response", ['decision' => 'accept']],
    'move_to_waitlist' => ['POST', "/api/panel/applications/{$targetId}/waitlist", []],
    'submit' => ['POST', '/api/applications', ['project_id' => (int) $targetId, 'consent_accepted' => true] + ((int) ($argv[6] ?? 0) ? ['program_id' => (int) $argv[6]] : [])],
    'guest_submit' => ['POST', '/api/applications/public', [
        'project_id' => (int) $targetId,
        'consent_accepted' => true,
        'applicant' => ['name' => 'Guest', 'surname' => 'Concurrency', 'email' => 'concurrent-guest@example.test'],
        'verification_code' => (string) ($argv[6] ?? ''),
    ]],
    default => ['PUT', "/api/panel/applications/{$targetId}/status", ['status' => 'accepted']],
};
$request = Request::create($url, $method, $payload);
$request->headers->set('Accept', 'application/json');
$response = app(HttpKernel::class)->handle($request);
echo json_encode([
    'status' => $response->getStatusCode(),
    'body' => json_decode($response->getContent(), true),
], JSON_THROW_ON_ERROR);
