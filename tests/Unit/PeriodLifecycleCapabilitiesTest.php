<?php

namespace Tests\Unit;

use App\Services\PeriodLifecycleService;
use App\Support\ProjectPeriodContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PeriodLifecycleCapabilitiesTest extends TestCase
{
    public static function statusCases(): array
    {
        return [
            'planned' => ['planned', false, true, false, false],
            'legacy passive' => ['passive', false, true, false, false],
            'active' => ['active', false, true, true, true],
            'closing' => ['closing', false, false, false, true],
            'completed' => ['completed', true, false, false, false],
            'cancelled' => ['cancelled', true, false, false, false],
        ];
    }

    #[DataProvider('statusCases')]
    public function test_archive_mode_and_write_capabilities_share_the_canonical_status_matrix(
        string $status,
        bool $archiveMode,
        bool $configure,
        bool $create,
        bool $resolve,
    ): void {
        $capabilities = PeriodLifecycleService::writeCapabilitiesForStatus($status);
        $context = new ProjectPeriodContext(1, 2, $status);

        $this->assertSame($archiveMode, PeriodLifecycleService::isArchiveStatus($status));
        $this->assertSame($archiveMode, $context->isArchiveMode());
        $this->assertSame($configure, $capabilities['configure_period']);
        $this->assertSame($create, $capabilities['create_operations']);
        $this->assertSame($resolve, $capabilities['resolve_operations']);
        $this->assertSame($archiveMode, $capabilities['archive_correction_required']);
    }
}
