<?php

namespace App\Http\Controllers;

use App\Services\GoogleCalendarService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class GoogleCalendarController extends Controller
{
    public function callback(Request $request, GoogleCalendarService $googleCalendar): RedirectResponse
    {
        $redirectUrl = $googleCalendar->handleCallback(
            $request->query('code'),
            $request->query('state')
        );

        return redirect()->away($redirectUrl);
    }
}
