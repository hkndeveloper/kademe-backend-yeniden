<?php

namespace App\Services;

use App\Models\CalendarEvent;
use App\Models\Program;
use App\Models\SystemSetting;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class GoogleCalendarService
{
    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
    private const AUTH_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const API_BASE = 'https://www.googleapis.com/calendar/v3';

    public function getStatus(): array
    {
        return [
            'configured' => $this->isConfigured(),
            'connected' => (bool) $this->getSetting('google_calendar_refresh_token'),
            'calendar_id' => config('services.google_calendar.calendar_id'),
            'last_synced_at' => $this->getSetting('google_calendar_last_synced_at'),
        ];
    }

    public function isConfigured(): bool
    {
        return filled(config('services.google_calendar.client_id'))
            && filled(config('services.google_calendar.client_secret'))
            && filled(config('services.google_calendar.redirect_uri'))
            && filled(config('services.google_calendar.calendar_id'));
    }

    public function getAuthorizationUrl(string $panel): string
    {
        $state = Str::random(40);
        Cache::put("google_calendar_oauth_state:{$state}", $panel, now()->addMinutes(10));

        $query = http_build_query([
            'client_id' => config('services.google_calendar.client_id'),
            'redirect_uri' => config('services.google_calendar.redirect_uri'),
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/calendar',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]);

        return self::AUTH_ENDPOINT . '?' . $query;
    }

    public function handleCallback(?string $code, ?string $state): string
    {
        abort_if(empty($code) || empty($state), 422, 'Google Calendar callback parametreleri eksik.');

        $panel = Cache::pull("google_calendar_oauth_state:{$state}");
        abort_if(empty($panel), 422, 'OAuth state gecersiz veya suresi dolmus.');

        $response = Http::asForm()->post(self::TOKEN_ENDPOINT, [
            'code' => $code,
            'client_id' => config('services.google_calendar.client_id'),
            'client_secret' => config('services.google_calendar.client_secret'),
            'redirect_uri' => config('services.google_calendar.redirect_uri'),
            'grant_type' => 'authorization_code',
        ]);

        abort_unless($response->successful(), 422, 'Google token alma islemi basarisiz.');

        $payload = $response->json();

        $this->putSetting('google_calendar_access_token', $payload['access_token'] ?? null);
        $this->putSetting('google_calendar_refresh_token', $payload['refresh_token'] ?? $this->getSetting('google_calendar_refresh_token'));
        $this->putSetting('google_calendar_token_expires_at', now()->addSeconds((int) ($payload['expires_in'] ?? 3600))->toIso8601String());
        $this->putSetting('google_calendar_last_synced_at', now()->toIso8601String());

        return $this->resolveFrontendRedirect($panel, 'connected');
    }

    public function syncAllPrograms(): array
    {
        $programs = Program::query()->with(['project:id,name'])->get();

        foreach ($programs as $program) {
            $this->syncProgram($program);
        }

        $this->putSetting('google_calendar_last_synced_at', now()->toIso8601String());

        return [
            'count' => $programs->count(),
            'last_synced_at' => $this->getSetting('google_calendar_last_synced_at'),
        ];
    }

    public function syncProgram(Program $program): CalendarEvent
    {
        $program->loadMissing('project:id,name');

        $event = CalendarEvent::query()->updateOrCreate(
            ['program_id' => $program->id],
            [
                'project_id' => $program->project_id,
                'title' => $program->title,
                'description' => $program->description,
                'location' => $program->location,
                'start_at' => $program->start_at,
                'end_at' => $program->end_at,
                'created_by' => $program->created_by,
            ]
        );

        if (!$this->isConfigured() || !$this->getSetting('google_calendar_refresh_token')) {
            return $event;
        }

        $payload = [
            'summary' => $program->title,
            'description' => trim(($program->project?->name ? "Proje: {$program->project->name}\n" : '') . ($program->description ?? '')),
            'location' => $program->location,
            'start' => [
                'dateTime' => optional($program->start_at)->toIso8601String(),
                'timeZone' => config('app.timezone', 'Europe/Istanbul'),
            ],
            'end' => [
                'dateTime' => optional($program->end_at)->toIso8601String(),
                'timeZone' => config('app.timezone', 'Europe/Istanbul'),
            ],
        ];

        if ($event->google_event_id) {
            $response = $this->authorizedRequest()->patch(
                $this->calendarEventUrl($event->google_event_id),
                $payload
            );
        } else {
            $response = $this->authorizedRequest()->post(
                $this->calendarEventsBaseUrl(),
                $payload
            );
        }

        if ($response->successful()) {
            $googleEventId = $response->json('id');
            if ($googleEventId) {
                $event->update(['google_event_id' => $googleEventId]);
            }
            $this->putSetting('google_calendar_last_synced_at', now()->toIso8601String());
        }

        return $event->fresh();
    }

    private function authorizedRequest(): PendingRequest
    {
        $accessToken = $this->resolveAccessToken();

        return Http::withToken($accessToken)
            ->acceptJson()
            ->baseUrl(self::API_BASE);
    }

    private function resolveAccessToken(): string
    {
        $accessToken = $this->getSetting('google_calendar_access_token');
        $expiresAt = $this->getSetting('google_calendar_token_expires_at');

        if ($accessToken && $expiresAt && Carbon::parse($expiresAt)->isFuture()) {
            return $accessToken;
        }

        $refreshToken = $this->getSetting('google_calendar_refresh_token');
        abort_if(empty($refreshToken), 422, 'Google Calendar baglantisi bulunmuyor.');

        $response = Http::asForm()->post(self::TOKEN_ENDPOINT, [
            'client_id' => config('services.google_calendar.client_id'),
            'client_secret' => config('services.google_calendar.client_secret'),
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]);

        abort_unless($response->successful(), 422, 'Google Calendar access token yenilenemedi.');

        $payload = $response->json();
        $token = $payload['access_token'] ?? null;
        abort_if(empty($token), 422, 'Google Calendar access token alinmadi.');

        $this->putSetting('google_calendar_access_token', $token);
        $this->putSetting('google_calendar_token_expires_at', now()->addSeconds((int) ($payload['expires_in'] ?? 3600))->toIso8601String());

        return $token;
    }

    private function resolveFrontendRedirect(string $panel, string $status): string
    {
        $configured = config('services.google_calendar.frontend_redirect');
        $origin = rtrim((string) preg_replace('#(/dashboard)?/(admin|coordinator|staff)/calendar$#', '', (string) $configured), '/');

        $path = match ($panel) {
            'staff' => '/staff/calendar',
            'coordinator' => '/coordinator/calendar',
            default => '/admin/calendar',
        };

        return "{$origin}{$path}?google_calendar={$status}";
    }

    private function calendarEventsBaseUrl(): string
    {
        $calendarId = urlencode((string) config('services.google_calendar.calendar_id'));
        return "/calendars/{$calendarId}/events";
    }

    private function calendarEventUrl(string $eventId): string
    {
        $calendarId = urlencode((string) config('services.google_calendar.calendar_id'));
        return "/calendars/{$calendarId}/events/{$eventId}";
    }

    private function getSetting(string $key): ?string
    {
        return SystemSetting::query()->where('key', $key)->value('value');
    }

    private function putSetting(string $key, ?string $value): void
    {
        SystemSetting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'group' => 'google_calendar']
        );
    }
}
