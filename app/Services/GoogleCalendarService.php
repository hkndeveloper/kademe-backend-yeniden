<?php

namespace App\Services;

use App\Enums\PeriodWriteAction;
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
    public function __construct(private readonly PeriodWritePolicy $periodWritePolicy)
    {
    }

    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
    private const AUTH_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const API_BASE = 'https://www.googleapis.com/calendar/v3';

    public function getStatus(): array
    {
        return [
            'configured' => $this->isConfigured(),
            'connected' => (bool) $this->getSetting('google_calendar_refresh_token'),
            'external_read_enabled' => (bool) config('services.google_calendar.external_read_enabled', false),
            'calendar_id' => config('services.google_calendar.calendar_id'),
            'last_synced_at' => $this->getSetting('google_calendar_last_synced_at'),
            'last_error' => $this->getSetting('google_calendar_last_error'),
            'last_error_at' => $this->getSetting('google_calendar_last_error_at'),
            'last_read_at' => $this->getSetting('google_calendar_last_read_at'),
            'last_read_error' => $this->getSetting('google_calendar_last_read_error'),
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

        return $this->resolveFrontendRedirect('connected');
    }

    public function syncAllPrograms(): array
    {
        $programs = Program::query()
            ->with(['project:id,name'])
            ->where(function ($query) {
                $query->whereNull('period_id')
                    ->orWhereHas('period', fn ($periodQuery) => $periodQuery->whereIn('status', ['active', 'closing']));
            })
            ->get();

        foreach ($programs as $program) {
            $this->syncProgram($program);
        }

        $this->putSetting('google_calendar_last_synced_at', now()->toIso8601String());
        $this->clearSyncError();

        return [
            'count' => $programs->count(),
            'last_synced_at' => $this->getSetting('google_calendar_last_synced_at'),
            'last_error' => $this->getSetting('google_calendar_last_error'),
            'last_error_at' => $this->getSetting('google_calendar_last_error_at'),
        ];
    }

    public function syncProgram(Program $program): CalendarEvent
    {
        $program->loadMissing(['project:id,name', 'period']);
        if ($program->period) {
            $this->periodWritePolicy->assertAllowed(
                null,
                $program->period,
                PeriodWriteAction::RESOLVE_OPERATION,
            );
        }

        $event = CalendarEvent::query()->updateOrCreate(
            ['program_id' => $program->id],
            [
                'project_id' => $program->project_id,
                'period_id' => $program->period_id,
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

        if (! $response->successful()) {
            $message = $response->json('error.message') ?? 'Google Calendar etkinlik senkronizasyonu basarisiz.';
            $this->recordSyncError((string) $message);
            abort(422, $message);
        }

        $googleEventId = $response->json('id');
        if ($googleEventId) {
            $event->update(['google_event_id' => $googleEventId]);
        }
        $this->putSetting('google_calendar_last_synced_at', now()->toIso8601String());

        return $event->fresh();
    }

    /** Google'daki harici etkinlikleri yerel kayıt oluşturmadan okur. */
    public function listExternalEvents(Carbon $start, Carbon $end): array
    {
        if (! config('services.google_calendar.external_read_enabled', false)
            || ! $this->isConfigured()
            || ! $this->getSetting('google_calendar_refresh_token')) {
            return ['events' => [], 'truncated' => false];
        }

        $items = [];
        $pageToken = null;
        for ($page = 0; $page < 5; $page++) {
            $query = [
                'timeMin' => $start->toRfc3339String(),
                'timeMax' => $end->toRfc3339String(),
                'singleEvents' => 'true',
                'orderBy' => 'startTime',
                'maxResults' => 250,
            ];
            if ($pageToken !== null) {
                $query['pageToken'] = $pageToken;
            }

            try {
                $response = $this->authorizedRequest()->timeout(10)->get($this->calendarEventsBaseUrl(), $query);
            } catch (\Throwable $exception) {
                $this->putSetting('google_calendar_last_read_error', Str::limit($exception->getMessage(), 500));
                abort(502, 'Google Calendar baglantisi kurulamadı.');
            }
            if (! $response->successful()) {
                $message = (string) ($response->json('error.message') ?? 'Google Calendar etkinlikleri okunamadı.');
                $this->putSetting('google_calendar_last_read_error', Str::limit($message, 500));
                abort(502, $message);
            }

            $items = array_merge($items, $response->json('items') ?? []);
            $pageToken = $response->json('nextPageToken');
            if (! $pageToken) {
                break;
            }
        }

        $localGoogleIds = CalendarEvent::query()
            ->whereIn('google_event_id', collect($items)->pluck('id')->filter()->all())
            ->pluck('google_event_id')
            ->all();

        $events = collect($items)
            ->filter(fn ($item) => is_array($item)
                && ! empty($item['id'])
                && ($item['status'] ?? '') !== 'cancelled'
                && ! in_array($item['visibility'] ?? '', ['private', 'confidential'], true)
                && ! in_array($item['id'], $localGoogleIds, true)
                && ! empty($item['start']['dateTime'] ?? $item['start']['date'] ?? null))
            ->map(function (array $item) {
                $allDay = isset($item['start']['date']) && ! isset($item['start']['dateTime']);
                $startValue = $item['start']['dateTime'] ?? $item['start']['date'];
                $endValue = $item['end']['dateTime'] ?? $item['end']['date'] ?? $startValue;

                return [
                    'google_event_id' => $item['id'],
                    'title' => $item['summary'] ?? 'Başlıksız Google etkinliği',
                    'location' => $item['location'] ?? null,
                    'start_at' => Carbon::parse($startValue, config('app.timezone', 'Europe/Istanbul'))->toIso8601String(),
                    'end_at' => Carbon::parse($endValue, config('app.timezone', 'Europe/Istanbul'))->toIso8601String(),
                    'all_day' => $allDay,
                ];
            })
            ->values()
            ->all();

        $this->putSetting('google_calendar_last_read_at', now()->toIso8601String());
        $this->putSetting('google_calendar_last_read_error', null);

        return ['events' => $events, 'truncated' => (bool) $pageToken];
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

    private function resolveFrontendRedirect(string $status): string
    {
        $url = parse_url((string) config('services.google_calendar.frontend_redirect'));
        abort_unless(
            is_array($url)
                && isset($url['scheme'], $url['host'])
                && in_array($url['scheme'], ['http', 'https'], true),
            500,
            'Google Calendar donus adresi gecersiz.'
        );

        $origin = $url['scheme'].'://'.$url['host'].(isset($url['port']) ? ':'.$url['port'] : '');

        return "{$origin}/panel/calendar?google_calendar=".rawurlencode($status);
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

    public function recordSyncError(string $message): void
    {
        $this->putSetting('google_calendar_last_error', Str::limit($message, 500));
        $this->putSetting('google_calendar_last_error_at', now()->toIso8601String());
    }

    private function clearSyncError(): void
    {
        $this->putSetting('google_calendar_last_error', null);
        $this->putSetting('google_calendar_last_error_at', null);
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
