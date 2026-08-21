<?php

namespace Tests\Feature;

use App\Models\Period;
use App\Models\PeriodArchive;
use App\Models\Project;
use App\Models\User;
use App\Services\PeriodArchiveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;
use Tests\TestCase;

class PeriodArchiveServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_archive_versions_are_canonical_chained_and_verifiable(): void
    {
        [$period, $actor] = $this->context();
        $service = app(PeriodArchiveService::class);

        $first = DB::transaction(fn () => $service->createVersion(
            $period,
            $this->closurePayload(),
            $this->readiness(),
            $actor,
            'Ilk kapanis',
        ));
        $second = DB::transaction(fn () => $service->createVersion(
            $period,
            $this->closurePayload(),
            $this->readiness(),
            $actor,
            'Duzeltme sonrasi kapanis',
            'Katilimci sonucu duzeltildi.',
        ));

        $this->assertSame(1, $first->archive_version);
        $this->assertSame(2, $second->archive_version);
        $this->assertSame($first->id, $second->previous_archive_id);
        $this->assertSame($first->integrity_hash, $second->previous_hash);
        $this->assertSame(2, $second->schema_version);
        $this->assertNotEmpty($second->snapshot_json['domains']);
        $this->assertSame('verified', $service->verify($first->fresh(['previousArchive']), $actor)['status']);
        $this->assertSame('verified', $service->verify($second->fresh(['previousArchive']), $actor)['status']);

        $tamperedSnapshot = $first->snapshot_json;
        $tamperedSnapshot['period']['name'] = 'Zincir Bozuldu';
        DB::table('period_archives')->where('id', $first->id)->update([
            'snapshot_json' => json_encode($tamperedSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        $this->assertFalse($service->verify($second->fresh(['previousArchive']))['chain_valid']);
    }

    public function test_tampering_is_detected_and_archive_model_rejects_update_or_delete(): void
    {
        [$period, $actor] = $this->context();
        $service = app(PeriodArchiveService::class);
        $archive = DB::transaction(fn () => $service->createVersion(
            $period,
            $this->closurePayload(),
            $this->readiness(),
            $actor,
        ));

        try {
            $archive->update(['notes' => 'Degistirme denemesi']);
            $this->fail('Arsiv modeli update islemini reddetmeliydi.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('degistirilemez', $exception->getMessage());
        }

        try {
            $archive->delete();
            $this->fail('Arsiv modeli delete islemini reddetmeliydi.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('silinemez', $exception->getMessage());
        }

        $snapshot = $archive->snapshot_json;
        $snapshot['period']['name'] = 'Yetkisiz Degisiklik';
        DB::table('period_archives')->where('id', $archive->id)->update([
            'snapshot_json' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        Log::spy();
        $result = $service->verify(PeriodArchive::query()->with('previousArchive')->findOrFail($archive->id));
        $this->assertSame('invalid', $result['status']);
        $this->assertFalse($result['hash_valid']);
        $this->assertSame('invalid', PeriodArchive::query()->findOrFail($archive->id)->verification_status);
        Log::shouldHaveReceived('error')->withArgs(
            fn (string $event, array $context) => $event === 'period_lifecycle.archive_verification_failed'
                && $context['archive_id'] === $archive->id
                && $context['period_id'] === $period->id
                && $context['hash_valid'] === false
        )->once();
    }

    public function test_verify_archives_command_reports_success_for_valid_chain(): void
    {
        [$period, $actor] = $this->context();
        DB::transaction(fn () => app(PeriodArchiveService::class)->createVersion(
            $period,
            $this->closurePayload(),
            $this->readiness(),
            $actor,
        ));

        Log::spy();
        $this->artisan('periods:verify-archives', ['--period' => $period->id])
            ->expectsOutputToContain('Tum arsivler dogrulandi.')
            ->assertSuccessful();
        Log::shouldHaveReceived('info')->with(
            'period_lifecycle.archive_verification_run_succeeded',
            ['checked_count' => 1, 'invalid_count' => 0],
        )->once();
    }

    private function context(): array
    {
        $actor = User::factory()->create(['surname' => 'Arsivci']);
        $project = Project::query()->create([
            'name' => 'Arsiv Projesi',
            'slug' => 'archive-service-project',
            'type' => 'other',
            'status' => 'active',
        ]);
        $period = Period::query()->create([
            'project_id' => $project->id,
            'name' => 'Arsiv Donemi',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'closing',
            'credit_start_amount' => 100,
            'credit_threshold' => 75,
        ]);

        return [$period, $actor];
    }

    private function closurePayload(): array
    {
        return [
            'summary' => ['participants' => ['total' => 0]],
            'warnings' => ['open_programs' => 0],
            'counts' => ['participants_total' => 0],
        ];
    }

    private function readiness(): array
    {
        return [
            'ready' => true,
            'blockers' => [],
            'warnings' => [],
            'resolved_checks' => [],
            'checks' => [],
            'calculated_at' => now()->toIso8601String(),
            'watermark' => str_repeat('a', 64),
        ];
    }
}
