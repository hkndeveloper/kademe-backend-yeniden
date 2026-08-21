<?php

namespace App\Services;

use App\Models\Period;
use App\Models\PeriodArchive;
use App\Models\User;

class PeriodArchiveService
{
    public function __construct(
        private readonly PeriodArchiveBuilder $builder,
        private readonly CanonicalJson $canonicalJson,
        private readonly PeriodLifecycleMonitor $monitor,
    ) {}

    public function createVersion(
        Period $period,
        array $closurePayload,
        array $readiness,
        ?User $actor,
        ?string $notes = null,
        ?string $correctionReason = null,
        ?string $overrideReason = null,
    ): PeriodArchive {
        $previous = PeriodArchive::query()
            ->where('period_id', $period->id)
            ->orderByDesc('archive_version')
            ->lockForUpdate()
            ->first();
        $version = ((int) ($previous?->archive_version ?? 0)) + 1;
        $closedAt = now();
        $payload = $this->builder->build($period, $closurePayload, $readiness);
        $hashPayload = $this->hashPayload([
            'period_id' => (int) $period->id,
            'project_id' => (int) $period->project_id,
            'archive_version' => $version,
            'schema_version' => PeriodArchiveBuilder::SCHEMA_VERSION,
            'previous_hash' => $previous?->integrity_hash,
            'closed_at' => $closedAt->toIso8601String(),
            ...$payload,
        ]);

        return PeriodArchive::query()->create([
            'period_id' => $period->id,
            'project_id' => $period->project_id,
            'closed_by' => $actor?->id,
            'closed_at' => $closedAt,
            'archive_version' => $version,
            'schema_version' => PeriodArchiveBuilder::SCHEMA_VERSION,
            'previous_archive_id' => $previous?->id,
            'previous_hash' => $previous?->integrity_hash,
            'summary_json' => $payload['summary'],
            'warnings_json' => $payload['warnings'],
            'counts_json' => $payload['counts'],
            'snapshot_json' => $payload['snapshot'],
            'manifest_json' => $payload['manifest'],
            'readiness_json' => $payload['readiness'],
            'override_reason' => $overrideReason,
            'correction_reason' => $correctionReason,
            'integrity_hash' => $this->canonicalJson->hash($hashPayload),
            'verification_status' => 'not_verified',
            'notes' => $notes,
        ]);
    }

    public function createLegacyBackfillVersion(Period $period, string $reason): PeriodArchive
    {
        return $this->createVersion(
            $period,
            [
                'summary' => [
                    'legacy_backfill' => true,
                    'source' => 'periods:backfill-lifecycle',
                ],
                'warnings' => [
                    'historical_snapshot' => 'Bu arsiv canli kapanis aninda degil, onayli legacy backfill sirasinda olusturuldu.',
                ],
                'counts' => [],
            ],
            [
                'ready' => null,
                'legacy_backfill' => true,
                'calculated_at' => now()->toIso8601String(),
            ],
            null,
            $reason,
            null,
            'legacy_backfill',
        );
    }

    public function verify(PeriodArchive $archive, ?User $actor = null): array
    {
        $expected = $this->expectedHash($archive);
        $hashValid = hash_equals((string) $archive->integrity_hash, $expected);
        $chainValid = (int) $archive->schema_version <= 1 ? true : $this->chainIsValid($archive);
        $status = $hashValid && $chainValid ? 'verified' : 'invalid';

        PeriodArchive::query()->whereKey($archive->id)->update([
            'verification_status' => $status,
            'verified_at' => now(),
            'verified_by' => $actor?->id,
        ]);

        $result = [
            'archive_id' => (int) $archive->id,
            'period_id' => (int) $archive->period_id,
            'archive_version' => (int) $archive->archive_version,
            'status' => $status,
            'hash_valid' => $hashValid,
            'chain_valid' => $chainValid,
            'expected_hash' => $expected,
            'stored_hash' => $archive->integrity_hash,
        ];

        if ($status === 'invalid') {
            $this->monitor->archiveVerificationFailed($result, $actor);
        }

        return $result;
    }

    private function expectedHash(PeriodArchive $archive): string
    {
        return (int) $archive->schema_version <= 1 || $archive->snapshot_json === null
            ? hash('sha256', json_encode([
                'period_id' => $archive->period_id,
                'project_id' => $archive->project_id,
                'closed_at' => optional($archive->closed_at)?->toIso8601String(),
                'archive_version' => $archive->archive_version,
                'summary' => $archive->summary_json,
                'warnings' => $archive->warnings_json,
                'counts' => $archive->counts_json,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION))
            : $this->canonicalJson->hash($this->hashPayload([
                'period_id' => (int) $archive->period_id,
                'project_id' => (int) $archive->project_id,
                'archive_version' => (int) $archive->archive_version,
                'schema_version' => (int) $archive->schema_version,
                'previous_hash' => $archive->previous_hash,
                'closed_at' => optional($archive->closed_at)?->toIso8601String(),
                'snapshot' => $archive->snapshot_json,
                'manifest' => $archive->manifest_json,
                'summary' => $archive->summary_json,
                'warnings' => $archive->warnings_json,
                'counts' => $archive->counts_json,
                'readiness' => $archive->readiness_json,
            ]));
    }

    private function chainIsValid(PeriodArchive $archive): bool
    {
        if ((int) $archive->archive_version === 1) {
            return $archive->previous_archive_id === null && $archive->previous_hash === null;
        }

        $previous = $archive->previousArchive;

        return $previous
            && (int) $previous->period_id === (int) $archive->period_id
            && (int) $previous->archive_version === ((int) $archive->archive_version - 1)
            && hash_equals((string) $previous->integrity_hash, (string) $archive->previous_hash)
            && hash_equals((string) $previous->integrity_hash, $this->expectedHash($previous))
            && ((int) $previous->schema_version <= 1 || $this->chainIsValid($previous));
    }

    private function hashPayload(array $payload): array
    {
        return $payload;
    }
}
