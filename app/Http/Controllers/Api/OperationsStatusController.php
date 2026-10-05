<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesGranularPermissions;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\CommunicationLog;
use App\Models\SystemSetting;
use App\Services\PermissionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OperationsStatusController extends Controller
{
    use AuthorizesGranularPermissions;

    public function __construct(private readonly PermissionResolver $permissionResolver) {}

    public function index(Request $request): JsonResponse
    {
        $this->abortUnlessGloballyAllowed($request, 'logs.view');

        // Read only the status keys. OAuth tokens and message/job payloads must never enter this response.
        $settings = SystemSetting::query()->whereIn('key', [
            'google_calendar_refresh_token',
            'google_calendar_last_synced_at',
            'google_calendar_last_error_at',
            'operations_scheduler_last_tick_at',
        ])->pluck('value', 'key');

        $email = CommunicationLog::query()->where('type', 'email');
        $recentEmail = (clone $email)->where('created_at', '>=', now()->subDay());
        $unresolvedInvitations = Application::query()
            ->where('status', 'waitlisted')
            ->whereNotNull('waitlist_invited_at');

        $queueDriver = config('queue.connections.'.config('queue.default').'.driver');
        $queueTable = config('queue.connections.'.config('queue.default').'.table', 'jobs');
        $failedDriver = config('queue.failed.driver');
        $failedTable = config('queue.failed.table', 'failed_jobs');
        $queueDatabase = $queueDriver === 'database'
            ? DB::connection(config('queue.connections.'.config('queue.default').'.connection'))
            : null;
        $failedDatabase = $failedDriver === 'database-uuids'
            ? DB::connection(config('queue.failed.database'))
            : null;
        $databaseQueue = $queueDriver === 'database' && $queueDatabase->getSchemaBuilder()->hasTable($queueTable);
        $databaseFailures = $failedDriver === 'database-uuids' && $failedDatabase->getSchemaBuilder()->hasTable($failedTable);

        return response()->json([
            'measured_at' => now()->toIso8601String(),
            'google_calendar' => [
                'configured' => filled(config('services.google_calendar.client_id'))
                    && filled(config('services.google_calendar.client_secret'))
                    && filled(config('services.google_calendar.redirect_uri'))
                    && filled(config('services.google_calendar.calendar_id')),
                'connected' => filled($settings['google_calendar_refresh_token'] ?? null),
                'last_synced_at' => $settings['google_calendar_last_synced_at'] ?? null,
                'last_error_at' => $settings['google_calendar_last_error_at'] ?? null,
            ],
            'email' => [
                'sent_24h' => (clone $recentEmail)->where('status', 'sent')->count(),
                'failed_24h' => (clone $recentEmail)->where('status', 'failed')->count(),
                'queued_24h' => (clone $recentEmail)->where('status', 'queued')->count(),
                'last_sent_at' => (clone $email)->where('status', 'sent')->latest('created_at')->value('created_at'),
                'last_failed_at' => (clone $email)->where('status', 'failed')->latest('created_at')->value('created_at'),
            ],
            'waitlist' => [
                'pending' => (clone $unresolvedInvitations)->where('waitlist_invitation_delivery_status', 'pending')->count(),
                'failed' => (clone $unresolvedInvitations)->where('waitlist_invitation_delivery_status', 'failed')->count(),
                'unknown' => (clone $unresolvedInvitations)->where('waitlist_invitation_delivery_status', 'unknown')->count(),
                'legacy_untracked' => (clone $unresolvedInvitations)->whereNull('waitlist_invitation_delivery_status')->count(),
                'last_sent_at' => Application::query()->where('waitlist_invitation_delivery_status', 'sent')->max('waitlist_invited_at'),
                'last_failed_at' => Application::query()->where('waitlist_invitation_delivery_status', 'failed')->latest('updated_at')->value('updated_at'),
                'auto_schedule_enabled' => (bool) config('application_waitlist.auto_schedule_enabled', false)
                    && config('application_waitlist.auto_project_ids', []) !== [],
            ],
            'scheduler' => [
                'last_tick_at' => $settings['operations_scheduler_last_tick_at'] ?? null,
            ],
            'queue' => [
                'driver' => $queueDriver,
                'pending' => $databaseQueue ? $queueDatabase->table($queueTable)->count() : null,
                'failed_total' => $databaseFailures ? $failedDatabase->table($failedTable)->count() : null,
                'last_failed_at' => $databaseFailures ? $failedDatabase->table($failedTable)->latest('failed_at')->value('failed_at') : null,
                'last_success_at' => null, // No worker completion log exists yet; do not infer success from an empty queue.
            ],
        ]);
    }
}
