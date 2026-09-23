<?php

namespace App\Support;

final class CoordinationUnitCatalog
{
    /**
     * @return array<string, string>
     */
    public static function serviceDomains(): array
    {
        return [
            'media' => 'Medya',
            'finance_procurement' => 'Finans ve Satın Alma',
            'organization' => 'Organizasyon ve Lojistik',
            'community_culture' => 'Topluluk ve Kültür',
        ];
    }

    /**
     * @return array<string, array{name: string, domains: list<string>}>
     */
    public static function serviceUnits(): array
    {
        return [
            'service_media' => [
                'name' => 'Medya Koordinatörlüğü',
                'domains' => ['media'],
            ],
            'service_purchase_organization' => [
                'name' => 'Satın Alma ve Organizasyon Koordinatörlüğü',
                'domains' => ['finance_procurement', 'organization'],
            ],
            'service_community_culture' => [
                'name' => 'Topluluk ve Kültür Koordinatörlüğü',
                'domains' => ['community_culture'],
            ],
        ];
    }

    public static function projectUnitCode(int $projectId): string
    {
        return 'project_'.$projectId;
    }
}
