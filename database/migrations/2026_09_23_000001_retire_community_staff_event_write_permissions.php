<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private array $retiredPermissions = [
        'programs.community_event.create',
        'programs.community_event.update',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('coordination_units') || ! Schema::hasTable('coordination_unit_permission_rules')) {
            return;
        }

        $communityUnitIds = DB::table('coordination_units')
            ->where('code', 'service_community_culture')
            ->pluck('id');

        if ($communityUnitIds->isEmpty()) {
            return;
        }

        // Isveren karari: ortak etkinligi coordinator planlar; staff mevcut
        // kayitlarda yoklama, galeri goruntuleme ve lojistik operasyonu yapar.
        // Gecmis kural satirlari audit/rollback icin silinmeden pasiflestirilir.
        DB::table('coordination_unit_permission_rules')
            ->whereIn('unit_id', $communityUnitIds)
            ->where('position', 'staff')
            ->whereIn('permission_name', $this->retiredPermissions)
            ->where('status', 'active')
            ->update([
                'status' => 'passive',
                'ends_at' => now(),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Bilerek no-op: rollback sirasinda admin tarafindan daha once
        // pasiflestirilmis bir kurali yanlislikla yeniden acmayiz.
    }
};
