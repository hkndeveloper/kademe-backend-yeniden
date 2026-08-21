<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $hasDuplicateVersions = DB::table('period_archives')
            ->select('period_id', 'archive_version')
            ->groupBy('period_id', 'archive_version')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasDuplicateVersions) {
            throw new RuntimeException('Yinelenen donem arsiv surumleri temizlenmeden benzersiz indeks eklenemez.');
        }

        Schema::table('period_archives', function (Blueprint $table) {
            $table->unsignedSmallInteger('schema_version')->default(1)->after('archive_version');
            $table->foreignId('previous_archive_id')
                ->nullable()
                ->after('schema_version')
                ->constrained('period_archives')
                ->restrictOnDelete();
            $table->string('previous_hash', 64)->nullable()->after('previous_archive_id');
            $table->json('snapshot_json')->nullable()->after('counts_json');
            $table->json('manifest_json')->nullable()->after('snapshot_json');
            $table->json('readiness_json')->nullable()->after('manifest_json');
            $table->text('override_reason')->nullable()->after('readiness_json');
            $table->text('correction_reason')->nullable()->after('override_reason');
            $table->string('verification_status', 30)->nullable()->after('integrity_hash');
            $table->timestampTz('verified_at')->nullable()->after('verification_status');
            $table->foreignId('verified_by')->nullable()->after('verified_at')->constrained('users')->nullOnDelete();
            $table->unique(['period_id', 'archive_version'], 'period_archives_period_version_unique');
        });
    }

    public function down(): void
    {
        Schema::table('period_archives', function (Blueprint $table) {
            $table->dropUnique('period_archives_period_version_unique');
            $table->dropConstrainedForeignId('verified_by');
            $table->dropColumn(['verified_at', 'verification_status']);
            $table->dropColumn(['correction_reason', 'override_reason']);
            $table->dropColumn(['readiness_json', 'manifest_json', 'snapshot_json']);
            $table->dropColumn(['previous_hash']);
            $table->dropConstrainedForeignId('previous_archive_id');
            $table->dropColumn('schema_version');
        });
    }
};
