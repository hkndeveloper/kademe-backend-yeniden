<?php

namespace Tests\Feature;

use App\Services\GoogleCalendarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleCalendarOAuthRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_oauth_callback_returns_to_existing_panel_calendar_route_for_every_panel(): void
    {
        config()->set('services.google_calendar.client_id', 'test-client');
        config()->set('services.google_calendar.client_secret', 'test-secret');
        config()->set('services.google_calendar.redirect_uri', 'http://localhost:8000/google/calendar/callback');

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'test-access-token',
                'refresh_token' => 'test-refresh-token',
                'expires_in' => 3600,
            ]),
        ]);

        foreach ([
            ['admin', 'https://hakankekec.me/admin/calendar', 'https://hakankekec.me/panel/calendar?google_calendar=connected'],
            ['coordinator', 'http://localhost:3000/coordinator/calendar', 'http://localhost:3000/panel/calendar?google_calendar=connected'],
            ['staff', 'http://localhost:3000/staff/calendar', 'http://localhost:3000/panel/calendar?google_calendar=connected'],
        ] as [$panel, $configuredRedirect, $expectedRedirect]) {
            config()->set('services.google_calendar.frontend_redirect', $configuredRedirect);
            parse_str((string) parse_url(app(GoogleCalendarService::class)->getAuthorizationUrl($panel), PHP_URL_QUERY), $query);

            $this->get('/google/calendar/callback?code=test-code&state='.$query['state'])
                ->assertRedirect($expectedRedirect);
        }

        $this->assertDatabaseHas('system_settings', [
            'key' => 'google_calendar_refresh_token',
            'value' => 'test-refresh-token',
        ]);
    }
}
