<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesGranularPermissions;
use App\Http\Controllers\Controller;
use App\Models\Period;
use App\Services\PermissionResolver;
use App\Support\AdminExportResponder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PeriodController extends Controller
{
    use AuthorizesGranularPermissions;

    public function __construct(
        private readonly PermissionResolver $permissionResolver
    ) {
    }

    private function scopeManageablePeriods(Request $request, $query, string $permission)
    {
        $user = $request->user();

        if ($user->role === 'super_admin') {
            return $query;
        }

        $manageableProjectIds = $this->permissionResolver->projectIdsForPermission($user, $permission);

        if ($manageableProjectIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('project_id', $manageableProjectIds);
    }

    public function index(Request $request): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'periods.view');

        $query = Period::with('project:id,name')->orderByDesc('created_at');
        $query = $this->scopeManageablePeriods($request, $query, 'periods.view');

        if ($request->filled('project_id')) {
            $query->where('project_id', $request->project_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return response()->json([
            'periods' => $query->get(),
        ]);
    }

    public function export(Request $request)
    {
        $this->abortUnlessAllowed($request, 'periods.export');

        $query = Period::with('project:id,name')->orderByDesc('created_at');
        $query = $this->scopeManageablePeriods($request, $query, 'periods.export');

        if ($request->filled('project_id')) {
            $query->where('project_id', $request->project_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $periods = $query->get();
        $headings = ['ID', 'Proje', 'Donem', 'Baslangic', 'Bitis', 'Baslangic Kredisi', 'Esik', 'Durum'];
        $rows = $periods->map(fn (Period $period) => [
            $period->id,
            $period->project?->name ?? '-',
            $period->name,
            optional($period->start_date)?->format('d.m.Y') ?? '-',
            optional($period->end_date)?->format('d.m.Y') ?? '-',
            $period->credit_start_amount,
            $period->credit_threshold,
            $period->status,
        ])->all();

        return AdminExportResponder::download(
            $request->string('format')->toString() ?: 'csv',
            'donemler_' . now()->format('Ymd_His'),
            'Donemler',
            $headings,
            $rows,
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'periods.create');

        $validated = $request->validate([
            'project_id' => 'required|exists:projects,id',
            'name' => 'required|string|max:255',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'credit_start_amount' => 'required|integer|min:0',
            'credit_threshold' => 'required|integer|min:0',
            'status' => 'required|in:active,passive,completed',
        ]);

        $this->abortUnlessProjectAllowed($request, 'periods.create', (int) $validated['project_id']);

        if ($validated['status'] === 'active') {
            Period::where('project_id', $validated['project_id'])
                ->where('status', 'active')
                ->update(['status' => 'passive']);
        }

        $period = Period::create($validated)->load('project');

        return response()->json([
            'message' => 'Donem olusturuldu.',
            'period' => $period,
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'periods.update');

        $period = Period::findOrFail($id);

        $this->abortUnlessProjectAllowed($request, 'periods.update', (int) $period->project_id);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'credit_start_amount' => 'required|integer|min:0',
            'credit_threshold' => 'required|integer|min:0',
            'status' => 'required|in:active,passive,completed',
        ]);

        if ($validated['status'] === 'active') {
            Period::where('project_id', $period->project_id)
                ->where('id', '!=', $period->id)
                ->where('status', 'active')
                ->update(['status' => 'passive']);
        }

        $period->update($validated);

        return response()->json([
            'message' => 'Donem guncellendi.',
            'period' => $period->fresh('project'),
        ]);
    }
}
