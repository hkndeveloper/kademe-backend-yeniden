<?php

namespace App\Services;

use App\Models\CommunicationLog;
use App\Support\MediaStorage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    /**
     * Webasist veya NetGSM gibi servisler üzerinden SMS atar (MOCK)
     */
    public function sendSms(array $phoneNumbers, string $message, ?int $projectId = null, ?int $senderId = null)
    {
        // TODO: Gelişmiş aşamada Webasist API entegrasyonu yapılacak.
        // Şu an sadece başarılı sayıp log tutuyoruz.
        
        Log::info('SMS Gönderildi: ' . count($phoneNumbers) . ' kişiye. Mesaj: ' . $message);

        CommunicationLog::create([
            'type' => 'sms',
            'sender_id' => $senderId,
            'recipients_count' => count($phoneNumbers),
            'content' => $message,
            'status' => 'sent',
            'project_id' => $projectId
        ]);

        return true;
    }

    /**
     * E-posta gönderimi (MOCK)
     */
    public function sendEmail(
        array $emails,
        string $subject,
        string $body,
        ?int $projectId = null,
        ?int $senderId = null,
        ?string $attachmentPath = null
    ): int
    {
        $recipients = collect($emails)
            ->filter(fn ($email) => is_string($email) && trim($email) !== '')
            ->map(fn ($email) => mb_strtolower(trim($email)))
            ->unique()
            ->values();

        if ($recipients->isEmpty()) {
            return 0;
        }

        $apiKey = (string) config('services.resend.key');
        $fromAddress = (string) config('services.resend.from', config('mail.from.address'));
        $fromName = (string) config('services.resend.from_name', config('mail.from.name'));
        $from = $fromName !== '' ? "{$fromName} <{$fromAddress}>" : $fromAddress;
        $textContent = trim(strip_tags($body));
        $htmlContent = nl2br(e($body));

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

        return $successCount;
    }
}
