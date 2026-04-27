<?php

namespace App\Services;

use App\Models\CommunicationLog;
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
    public function sendEmail(array $emails, string $subject, string $body, ?int $projectId = null, ?int $senderId = null)
    {
        Log::info('E-Posta Gönderildi: ' . count($emails) . ' kişiye. Konu: ' . $subject);

        CommunicationLog::create([
            'type' => 'email',
            'sender_id' => $senderId,
            'recipients_count' => count($emails),
            'subject' => $subject,
            'content' => $body,
            'status' => 'sent',
            'project_id' => $projectId
        ]);

        return true;
    }
}
