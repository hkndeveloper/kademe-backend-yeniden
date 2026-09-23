<?php

namespace App\Support;

use Illuminate\Support\Str;

final class CoordinationUnitExclusivePermissionCatalog
{
    /** @var array<string, string> */
    private const OWNER_BY_PREFIX = [
        'financial.' => 'service_purchase_organization',
        'announcements.' => 'service_media',
        'content.' => 'service_media',
        'volunteer.' => 'service_community_culture',
        'motivation.' => 'service_community_culture',
    ];

    /**
     * YF-3 ile ilk kez birim sablonuna giren izinler. Normal sync yalnizca
     * bu izinlerin hic tarihsel satiri yoksa varsayilani ekler; pasif bir
     * satiri admin karari sayar ve yeniden acmaz.
     *
     * @return list<string>
     */
    public static function transitionDefaultPermissions(): array
    {
        return [
            'inbox.view',
            'alumni_opportunities.view',
            'alumni_opportunities.manage',
            'programs.logistics.view',
            'requests.create',
            'support.create',
        ];
    }

    public static function ownerCodeFor(string $permissionName): ?string
    {
        foreach (self::OWNER_BY_PREFIX as $prefix => $unitCode) {
            if (Str::startsWith($permissionName, $prefix)) {
                return $unitCode;
            }
        }

        return null;
    }

    public static function isOwnedBy(string $permissionName, string $unitCode): bool
    {
        $ownerCode = self::ownerCodeFor($permissionName);

        return $ownerCode === null || $ownerCode === $unitCode;
    }
}
