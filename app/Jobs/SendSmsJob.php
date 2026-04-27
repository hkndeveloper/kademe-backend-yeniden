<?php

namespace App\Jobs;

use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendSmsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected array $phoneNumbers;
    protected string $message;
    protected ?int $projectId;
    protected ?int $senderId;

    public function __construct(array $phoneNumbers, string $message, ?int $projectId = null, ?int $senderId = null)
    {
        $this->phoneNumbers = $phoneNumbers;
        $this->message = $message;
        $this->projectId = $projectId;
        $this->senderId = $senderId;
    }

    public function handle(NotificationService $notificationService): void
    {
        $notificationService->sendSms(
            $this->phoneNumbers,
            $this->message,
            $this->projectId,
            $this->senderId
        );
    }
}
