<?php

namespace App\Services;

use App\Models\Application;
use App\Models\CommunicationLog;
use App\Support\IstanbulDateTime;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApplicationMessageService
{
    public const EVENTS = ['received', 'interview_planned', 'interview_passed', 'interview_failed', 'accepted', 'rejected', 'waitlisted', 'waitlist_invited'];

    public const VARIABLES = ['ad', 'soyad', 'proje_adi', 'donem_adi', 'egitim_adi', 'mulakat_tarihi', 'takip_linki'];

    public function send(Application $application, string $event, string $subject, array $data, ?int $senderId = null): int
    {
        [$subject, $data] = $this->apply($application, $event, $subject, $data, $senderId);

        return app(NotificationService::class)->sendTemplatedEmail(array_filter([$application->applicant()?->email]), $subject, 'emails.application-status', $data, $application->project_id, $senderId);
    }

    public function validateVariables(array $data): void
    {
        foreach ($data as $key => $value) {
            preg_match_all('/\{\{\s*([^{}]+?)\s*\}\}/', (string) $value, $matches);
            if (array_diff($matches[1], self::VARIABLES)) {
                throw ValidationException::withMessages([$key => ['Desteklenmeyen mesaj değişkeni var.']]);
            }
        }
    }

    public function apply(Application $application, string $event, string $subject, array $data, ?int $senderId = null): array
    {
        $template = DB::table('application_message_templates')->where('project_id', $application->project_id)->where('event', $event)->first();
        if (! $template) {
            return [$subject, $data];
        }
        $applicant = $application->applicant();
        $values = [
            'ad' => $applicant?->name ?? '', 'soyad' => $applicant?->surname ?? '', 'proje_adi' => $application->project?->name ?? '',
            'donem_adi' => $application->period?->name ?? '', 'egitim_adi' => $application->training?->title ?? '',
            'mulakat_tarihi' => $application->interview_at ? IstanbulDateTime::format($application->interview_at) : '',
            'takip_linki' => $data['action_url'] ?? '',
        ];
        $render = fn ($text) => preg_replace_callback('/\{\{\s*([^{}]+?)\s*\}\}/', fn ($match) => $values[$match[1]] ?? '', (string) $text);
        if ($template->email_subject) {
            $subject = $render($template->email_subject);
        }
        if ($template->email_body) {
            $data['intro'] = $render($template->email_body);
            $data['plain_text'] = $data['intro'];
            $data['title'] = $subject;
        }
        if ($template->sms_body && $applicant?->phone) {
            CommunicationLog::create([
                'type' => 'sms', 'sender_id' => $senderId, 'recipients_count' => 1, 'project_id' => $application->project_id,
                'recipient_filter' => ['application_id' => $application->id, 'event' => $event],
                'subject' => $event, 'content' => $render($template->sms_body), 'status' => 'failed',
            ]); // No SMS provider is configured: never claim delivery.
        }

        return [$subject, $data];
    }
}
