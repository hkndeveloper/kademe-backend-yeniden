<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getSchemaBuilder()->hasTable('role_permission_scopes')) {
            DB::table('role_permission_scopes')
                ->whereIn('scope_type', ['own_projects', 'assigned_projects', 'all', 'self', 'none'])
                ->update(['scope_payload' => json_encode([])]);

            DB::table('role_permission_scopes')
                ->where('scope_type', 'selected_projects')
                ->orderBy('id')
                ->chunkById(200, function ($rows): void {
                    foreach ($rows as $row) {
                        $payload = json_decode((string) ($row->scope_payload ?? '{}'), true);
                        $projectIds = collect($payload['project_ids'] ?? [])
                            ->filter(fn ($id) => is_numeric($id))
                            ->map(fn ($id) => (int) $id)
                            ->unique()
                            ->values()
                            ->all();

                        DB::table('role_permission_scopes')
                            ->where('id', $row->id)
                            ->update(['scope_payload' => json_encode(['project_ids' => $projectIds])]);
                    }
                });
        }

        if (DB::getSchemaBuilder()->hasTable('user_permission_overrides')) {
            DB::table('user_permission_overrides')
                ->whereIn('scope_type', ['own_projects', 'assigned_projects', 'all', 'self', 'none'])
                ->update(['scope_payload' => json_encode([])]);

            DB::table('user_permission_overrides')
                ->where('scope_type', 'selected_projects')
                ->orderBy('id')
                ->chunkById(200, function ($rows): void {
                    foreach ($rows as $row) {
                        $payload = json_decode((string) ($row->scope_payload ?? '{}'), true);
                        $projectIds = collect($payload['project_ids'] ?? [])
                            ->filter(fn ($id) => is_numeric($id))
                            ->map(fn ($id) => (int) $id)
                            ->unique()
                            ->values()
                            ->all();

                        DB::table('user_permission_overrides')
                            ->where('id', $row->id)
                            ->update(['scope_payload' => json_encode(['project_ids' => $projectIds])]);
                    }
                });
        }
    }

    public function down(): void
    {
        // Data normalization migration: no down action.
    }
};

