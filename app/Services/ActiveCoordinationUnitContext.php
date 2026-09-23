<?php

namespace App\Services;

use App\Models\CoordinationUnitMembership;
use App\Models\User;
use Illuminate\Http\Request;

class ActiveCoordinationUnitContext
{
    public const HEADER = 'X-Coordination-Unit-Id';

    public const ATTRIBUTE = 'coordination.active_context';

    /**
     * @return array{membership: CoordinationUnitMembership|null, metadata: array<string, mixed>}
     */
    public function resolveRequest(Request $request, User $user): array
    {
        if (! $this->isAuthoritativeFor($user)) {
            return $this->selection(null, 'not_authoritative', false);
        }

        $memberships = $this->membershipsFor($user);
        $rawHeader = trim((string) $request->header(self::HEADER, ''));

        if ($rawHeader !== '') {
            if (! ctype_digit($rawHeader) || (int) $rawHeader < 1) {
                abort(422, 'Aktif koordinasyon birimi kimligi pozitif bir tam sayi olmalidir.');
            }

            $membership = $memberships->firstWhere('unit_id', (int) $rawHeader);
            abort_unless(
                $membership instanceof CoordinationUnitMembership,
                403,
                'Secilen koordinasyon birimi icin aktif uyeliginiz bulunmuyor.'
            );

            return $this->selection($membership, 'header', $memberships->count() > 1);
        }

        if ($memberships->count() === 1) {
            return $this->selection($memberships->first(), 'single_membership', false);
        }

        if ($memberships->isEmpty()) {
            return $this->selection(null, 'no_membership', false);
        }

        if (config('coordination_authorization.active_unit_fallback', 'primary') === 'primary') {
            $primary = $memberships->firstWhere('is_primary', true);
            if ($primary instanceof CoordinationUnitMembership) {
                return $this->selection($primary, 'primary_fallback', true);
            }
        }

        abort(409, 'Birden fazla aktif birim uyeliginiz var. Devam etmek icin aktif koordinasyon birimini secin.');
    }

    /**
     * HTTP disi resolver, queue ve test cagirilari icin deterministik fallback.
     */
    public function membershipFor(User $user): ?CoordinationUnitMembership
    {
        $request = app()->bound('request') ? request() : null;
        $context = $request?->attributes->get(self::ATTRIBUTE);

        if (is_array($context) && (int) ($context['user_id'] ?? 0) === (int) $user->id) {
            $membershipId = $context['membership_id'] ?? null;

            return $membershipId === null
                ? null
                : $this->membershipsFor($user)->firstWhere('id', (int) $membershipId);
        }

        $memberships = $this->membershipsFor($user);

        return $memberships->firstWhere('is_primary', true) ?? $memberships->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function metadataFor(User $user): array
    {
        $request = app()->bound('request') ? request() : null;
        $context = $request?->attributes->get(self::ATTRIBUTE);
        if (is_array($context) && (int) ($context['user_id'] ?? 0) === (int) $user->id) {
            return $context;
        }

        $membership = $this->membershipFor($user);

        return $this->selection($membership, $membership ? 'resolver_fallback' : 'no_membership', false)['metadata'];
    }

    public function isAuthoritativeFor(User $user): bool
    {
        if (! in_array($user->role, ['coordinator', 'staff'], true)) {
            return false;
        }

        $mode = strtolower((string) config('coordination_authorization.mode', 'legacy'));
        if ($mode === 'enforce') {
            return true;
        }

        return $mode === 'pilot' && collect(config('coordination_authorization.pilot_user_ids', []))
            ->map(fn ($id) => (int) $id)
            ->contains((int) $user->id);
    }

    private function membershipsFor(User $user)
    {
        return CoordinationUnitMembership::query()
            ->active()
            ->where('user_id', $user->id)
            ->whereHas('unit', fn ($query) => $query->where('status', 'active'))
            ->with([
                'unit.projectResponsibilities' => fn ($query) => $query->active(),
                'unit.permissionRules' => fn ($query) => $query->active(),
                'permissionOverrides' => fn ($query) => $query->active(),
            ])
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array{membership: CoordinationUnitMembership|null, metadata: array<string, mixed>}
     */
    private function selection(?CoordinationUnitMembership $membership, string $source, bool $required): array
    {
        return [
            'membership' => $membership,
            'metadata' => [
                'user_id' => $membership?->user_id,
                'active_membership_id' => $membership?->id,
                'membership_id' => $membership?->id,
                'active_unit_id' => $membership?->unit_id,
                'unit_id' => $membership?->unit_id,
                'source' => $source,
                'header' => self::HEADER,
                'selection_required' => $required,
            ],
        ];
    }
}
