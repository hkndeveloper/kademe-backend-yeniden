<?php

namespace App\Services;

use App\Models\ApplicationEmailVerification;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ApplicationEmailVerificationService
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function sendCode(Project $project, string $email): void
    {
        $code = (string) random_int(10000000, 99999999);
        $hash = $this->hashCode($code);
        $shouldSend = DB::transaction(function () use ($project, $email, $hash) {
            Project::query()->lockForUpdate()->findOrFail($project->id);
            $challenge = ApplicationEmailVerification::query()
                ->where('project_id', $project->id)
                ->where('email', $email)
                ->lockForUpdate()
                ->first();

            if ($challenge && $challenge->updated_at?->gt(now()->subMinute()) && $challenge->consumed_at === null) {
                return false;
            }

            ApplicationEmailVerification::query()->updateOrCreate(
                ['project_id' => $project->id, 'email' => $email],
                ['code_hash' => $hash, 'expires_at' => now()->addMinutes(10), 'attempts' => 0, 'consumed_at' => null],
            );

            return true;
        });

        if (! $shouldSend) {
            return;
        }

        $sent = $this->notifications->sendEmail(
            [$email],
            'KADEME başvuru e-posta doğrulama kodu',
            "Başvurunuzu tamamlamak için doğrulama kodunuz: {$code}\nBu kod 10 dakika geçerlidir. Siz istemediyseniz bu mesajı yok sayın.",
            $project->id,
            null,
            null,
            null,
            'Başvuru e-posta doğrulama kodu gönderimi. Kod gizlendi.',
        );

        if ($sent !== 1) {
            ApplicationEmailVerification::query()
                ->where('project_id', $project->id)
                ->where('email', $email)
                ->where('code_hash', $hash)
                ->delete();

            throw new HttpException(503, 'Doğrulama kodu şu anda gönderilemiyor. Lütfen daha sonra tekrar deneyin.');
        }
    }

    public function assertCode(int $projectId, string $email, string $code): void
    {
        $valid = DB::transaction(function () use ($projectId, $email, $code) {
            $challenge = ApplicationEmailVerification::query()
                ->where('project_id', $projectId)
                ->where('email', $email)
                ->lockForUpdate()
                ->first();

            if (! $challenge || ! $this->canUse($challenge)) {
                return false;
            }

            if (hash_equals($challenge->code_hash, $this->hashCode($code))) {
                return true;
            }

            $challenge->increment('attempts');

            return false;
        });

        if (! $valid) {
            throw ValidationException::withMessages([
                'verification_code' => ['Doğrulama kodu geçersiz veya süresi dolmuş. Yeni kod isteyin.'],
            ]);
        }
    }

    /** Called inside the submission transaction, after form validation. */
    public function consume(int $projectId, string $email, string $code): void
    {
        $challenge = ApplicationEmailVerification::query()
            ->where('project_id', $projectId)
            ->where('email', $email)
            ->lockForUpdate()
            ->first();

        if (! $challenge || ! $this->canUse($challenge) || ! hash_equals($challenge->code_hash, $this->hashCode($code))) {
            throw ValidationException::withMessages([
                'verification_code' => ['Doğrulama kodu geçersiz veya süresi dolmuş. Yeni kod isteyin.'],
            ]);
        }

        $challenge->update(['consumed_at' => now()]);
    }

    private function canUse(ApplicationEmailVerification $challenge): bool
    {
        return $challenge->consumed_at === null
            && $challenge->attempts < 5
            && $challenge->expires_at->isFuture();
    }

    private function hashCode(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }
}
