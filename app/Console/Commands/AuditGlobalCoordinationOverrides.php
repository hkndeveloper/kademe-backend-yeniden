<?php

namespace App\Console\Commands;

use App\Models\UserPermissionOverride;
use App\Support\AuthorizationManagementCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class AuditGlobalCoordinationOverrides extends Command
{
    protected $signature = 'coordination-units:audit-global-overrides {--json : JSON cikti uret}';

    protected $description = 'Coordinator/staff birim isi icin kalmis global kullanici override kayitlarini salt okunur raporlar';

    public function handle(): int
    {
        if (! Schema::hasTable('user_permission_overrides')) {
            $this->error('user_permission_overrides tablosu bulunamadi.');

            return self::FAILURE;
        }

        $permissions = AuthorizationManagementCatalog::unitBusinessPermissions();
        $roles = AuthorizationManagementCatalog::protectedAuthorityRoles();
        $rows = UserPermissionOverride::query()
            ->with(['user:id,name,surname,email,role'])
            ->whereIn('permission_name', $permissions)
            ->whereHas('user', fn ($query) => $query->whereIn('role', $roles))
            ->orderBy('user_id')
            ->orderBy('permission_name')
            ->get()
            ->map(fn (UserPermissionOverride $override) => [
                'override_id' => (int) $override->id,
                'user_id' => (int) $override->user_id,
                'email' => $override->user?->email,
                'role' => $override->user?->role,
                'permission_name' => $override->permission_name,
                'effect' => $override->effect,
                'enforce_effect' => $override->effect === 'deny'
                    ? 'global_deny_preserved'
                    : 'legacy_allow_ignored_until_membership_assignment',
                'recommended_action' => $override->effect === 'allow'
                    ? 'Yetki Matrisinde hedef birim uyeligini secerek yeniden tanimlayin.'
                    : 'Global daraltma isteniyorsa koruyun; degilse bilincli olarak kaldirin.',
            ])
            ->values();

        $payload = [
            'mode' => config('coordination_authorization.mode', 'legacy'),
            'dry_run' => true,
            'record_count' => $rows->count(),
            'allow_count' => $rows->where('effect', 'allow')->count(),
            'deny_count' => $rows->where('effect', 'deny')->count(),
            'records' => $rows->all(),
        ];

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info("Global birim-isi override dry-run: {$payload['record_count']} kayit");
        $this->table(
            ['ID', 'Kullanici', 'Rol', 'Permission', 'Etki', 'Enforce sonucu'],
            $rows->map(fn (array $row) => [
                $row['override_id'],
                $row['email'],
                $row['role'],
                $row['permission_name'],
                $row['effect'],
                $row['enforce_effect'],
            ])->all()
        );

        return self::SUCCESS;
    }
}
