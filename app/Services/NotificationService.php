<?php

namespace App\Services;

use App\Models\CommunicationLog;
use App\Support\MediaStorage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    /**
     * SMS gateway baglanana kadar gonderimi loglar ve alici sayisini dondurur.
     */
    public function sendSms(array $phoneNumbers, string $message, ?int $projectId = null, ?int $senderId = null): int
    {
        $recipients = collect($phoneNumbers)
            ->filter(fn ($phone) => is_string($phone) && trim($phone) !== '')
            ->map(fn ($phone) => trim($phone))
            ->unique()
            ->values();

        if ($recipients->isEmpty()) {
            return 0;
        }

        Log::info('sms.dispatch.queued', [
            'recipient_count' => $recipients->count(),
            'project_id' => $projectId,
        ]);

        CommunicationLog::create([
            'type' => 'sms',
            'sender_id' => $senderId,
            'recipients_count' => $recipients->count(),
            'subject' => 'SMS',
            'content' => $message,
            'status' => 'queued',
            'project_id' => $projectId,
        ]);

        return $recipients->count();
    }

    /**
     * Resend uzerinden e-posta gonderir ve sonucu CommunicationLog'a yazar.
     */
    public function sendEmail(
        array $emails,
        string $subject,
        string $body,
        ?int $projectId = null,
        ?int $senderId = null,
        ?string $attachmentPath = null
    ): int {
        $recipients = collect($emails)
            ->filter(fn ($email) => is_string($email) && trim($email) !== '')
            ->map(fn ($email) => mb_strtolower(trim($email)))
            ->unique()
            ->values();

        if ($recipients->isEmpty()) {
            CommunicationLog::create([
                'type' => 'email',
                'sender_id' => $senderId,
                'recipients_count' => 0,
                'subject' => $subject,
                'content' => mb_substr($body, 0, 2000),
                'attachment_path' => $attachmentPath,
                'status' => 'failed',
                'project_id' => $projectId,
            ]);

            Log::warning('resend.no_recipients', [
                'subject' => $subject,
                'project_id' => $projectId,
            ]);

            return 0;
        }

        $apiKey = (string) config('services.resend.key');
        $fromAddress = (string) config('services.resend.from', config('mail.from.address'));
        $fromName = (string) config('services.resend.from_name', config('mail.from.name'));
        $from = $fromName !== '' ? "{$fromName} <{$fromAddress}>" : $fromAddress;
        $textContent = trim(strip_tags($body));
        $htmlContent = nl2br(e($body));

        Log::info('resend.dispatch.start', [
            'subject' => $subject,
            'project_id' => $projectId,
            'recipient_count' => $recipients->count(),
        ]);

        if ($apiKey === '' || $fromAddress === '') {
            Log::warning('resend.missing_configuration', [
                'has_api_key' => $apiKey !== '',
                'from_address' => $fromAddress,
            ]);

            CommunicationLog::create([
                'type' => 'email',
                'sender_id' => $senderId,
                'recipients_count' => 0,
                'subject' => $subject,
                'content' => mb_substr($body, 0, 2000),
                'attachment_path' => $attachmentPath,
                'status' => 'failed',
                'project_id' => $projectId,
            ]);

            return 0;
        }

        $attachments = [];
        if ($attachmentPath && MediaStorage::exists($attachmentPath)) {
            try {
                $attachments[] = [
                    'filename' => basename($attachmentPath),
                    'content' => base64_encode(MediaStorage::disk()->get($attachmentPath)),
                ];
            } catch (\Throwable $exception) {
                Log::warning('resend.attachment_read_failed', [
                    'path' => $attachmentPath,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $successCount = 0;
        foreach ($recipients as $email) {
            $payload = [
                'from' => $from,
                'to' => [$email],
                'subject' => $subject,
                'text' => $textContent,
                'html' => $htmlContent,
            ];
            if ($attachments !== []) {
                $payload['attachments'] = $attachments;
            }

            try {
                $response = Http::withToken($apiKey)
                    ->acceptJson()
                    ->post('https://api.resend.com/emails', $payload);

                if ($response->successful()) {
                    $successCount++;
                } else {
                    Log::warning('resend.send_failed', [
                        'email' => $email,
                        'status' => $response->status(),
                        'body' => $response->json() ?? $response->body(),
                    ]);
                }
            } catch (\Throwable $exception) {
                Log::warning('resend.send_exception', [
                    'email' => $email,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        CommunicationLog::create([
            'type' => 'email',
            'sender_id' => $senderId,
            'recipients_count' => $successCount,
            'subject' => $subject,
            'content' => mb_substr($body, 0, 2000),
            'attachment_path' => $attachmentPath,
            'status' => $successCount > 0 ? 'sent' : 'failed',
            'project_id' => $projectId,
        ]);

        Log::info('resend.dispatch.result', [
            'subject' => $subject,
            'project_id' => $projectId,
            'recipient_count' => $recipients->count(),
            'success_count' => $successCount,
        ]);

        return $successCount;
    }
}
