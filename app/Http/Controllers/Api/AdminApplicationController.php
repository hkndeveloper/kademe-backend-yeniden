<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesGranularPermissions;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Participant;
use App\Models\Project;
use App\Services\PermissionResolver;
use App\Support\AdminExportResponder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminApplicationController extends Controller
{
    use AuthorizesGranularPermissions;

    public function __construct(
        private readonly PermissionResolver $permissionResolver
    ) {
    }

    /** @return int[] */
    private function manageableProjectIdList(Request $request, string $permission): array
    {
        return $this->permissionResolver->projectIdsForPermission($request->user(), $permission);
    }

    public function export(Request $request)
    {
        $this->abortUnlessAllowed($request, 'applications.export');

        $query = Application::query()->with(['user:id,name,surname,email,phone', 'period', 'project:id,name']);
        $query->whereIn('project_id', $this->manageableProjectIdList($request, 'applications.export'));

        if ($request->filled('project_id')) {
            $query->where('project_id', (int) $request->project_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('user', function ($builder) use ($search) {
                $builder
                    ->where('name', 'like', "%$search%")
                    ->orWhere('surname', 'like', "%$search%")
                    ->orWhere('email', 'like', "%$search%");
            });
        }

        $applications = $query->orderByDesc('created_at')->get();

        $headings = ['ID', 'Proje', 'Donem', 'Ad', 'Soyad', 'E-posta', 'Telefon', 'Durum', 'Degerlendirme Notu', 'Ret Nedeni', 'Basvuru Tarihi'];
        $rows = $applications->map(fn (Application $application) => [
            $application->id,
            $application->project->name ?? '-',
            $application->period->name ?? '-',
            $application->user->name ?? '-',
            $application->user->surname ?? '-',
            $application->user->email ?? '-',
            $application->user->phone ?? '-',
            $application->status,
            $application->evaluation_note ?? '-',
            $application->rejection_reason ?? '-',
            $application->created_at?->format('d.m.Y H:i') ?? '-',
        ])->all();

        return AdminExportResponder::download(
            $request->string('format')->toString() ?: 'csv',
            'basvurular_' . now()->format('Ymd_His'),
            'Basvurular',
            $headings,
            $rows,
        );
    }

    public function staffIndex(Request $request)
    {
        $this->abortUnlessAllowed($request, 'applications.view');

        $projectIds = $this->manageableProjectIdList($request, 'applications.view');

        $query = Application::query()
            ->with(['user:id,name,surname,email,phone', 'period', 'project:id,name'])
            ->whereIn('project_id', $projectIds);

        if ($request->filled('project_id')) {
            $query->where('project_id', (int) $request->project_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('user', function ($builder) use ($search) {
                $builder
                    ->where('name', 'like', "%$search%")
                    ->orWhere('surname', 'like', "%$search%")
                    ->orWhere('email', 'like', "%$search%");
            });
        }

        return response()->json([
            'applications' => $query->orderByDesc('created_at')->paginate(20),
        ]);
    }

    public function staffExport(Request $request)
    {
        $this->abortUnlessAllowed($request, 'applications.export');

        $projectIds = $this->manageableProjectIdList($request, 'applications.export');

        $query = Application::query()
            ->with(['user:id,name,surname,email,phone', 'period', 'project:id,name'])
            ->whereIn('project_id', $projectIds);

        if ($request->filled('project_id')) {
            $query->where('project_id', (int) $request->project_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('user', function ($builder) use ($search) {
                $builder
                    ->where('name', 'like', "%$search%")
                    ->orWhere('surname', 'like', "%$search%")
                    ->orWhere('email', 'like', "%$search%");
            });
        }

        $applications = $query->orderByDesc('created_at')->get();

        $headings = ['ID', 'Proje', 'Donem', 'Ad', 'Soyad', 'E-posta', 'Telefon', 'Durum', 'Basvuru Tarihi'];
        $rows = $applications->map(fn (Application $application) => [
            $application->id,
            $application->project->name ?? '-',
            $application->period->name ?? '-',
            $application->user->name ?? '-',
            $application->user->surname ?? '-',
            $application->user->email ?? '-',
            $application->user->phone ?? '-',
            $application->status,
            $application->created_at?->format('d.m.Y H:i') ?? '-',
        ])->all();

        return AdminExportResponder::download(
            $request->string('format')->toString() ?: 'csv',
            'personel_basvurulari_' . now()->format('Ymd_His'),
            'Personel Basvurulari',
            $headings,
            $rows,
        );
    }

    public function staffUpdateStatus(Request $request, int $id)
    {
        $this->abortUnlessAllowed($request, 'applications.update_status');

        $projectIds = $this->manageableProjectIdList($request, 'applications.update_status');
        $application = Application::with('period')->findOrFail($id);

        abort_unless(in_array((int) $application->project_id, $projectIds, true), 403, 'Bu basvuru icin yetkiniz bulunmuyor.');

        return $this->updateStatus($request, $id);
    }

    /**
     * Projeye ait tüm başvuruları getirir
     */
    public function index(Request $request)
    {
        $this->abortUnlessAllowed($request, 'applications.view');

        $validated = $request->validate([
            'project_id' => 'required|exists:projects,id',
            'status' => 'nullable|string',
        ]);

        $project = Project::findOrFail($validated['project_id']);
        $ids = $this->manageableProjectIdList($request, 'applications.view');
        abort_unless(in_array((int) $project->id, $ids, true), 403, 'Bu projeye ait basvurulari goruntuleme yetkiniz yok.');

        $query = Application::where('project_id', $validated['project_id'])
            ->with(['user:id,name,surname,email,phone', 'period']);

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        return response()->json([
            'applications' => $query->orderBy('created_at', 'desc')->paginate(20),
        ]);
    }

    /**
     * Başvuru Durumunu Güncelleme (Kabul/Red/Yedek vb.)
     */
    public function updateStatus(Request $request, $id)
    {
        $this->abortUnlessAllowed($request, 'applications.update_status');

        $validated = $request->validate([
            'status' => 'required|in:accepted,rejected,waitlisted,interview_planned,interview_passed,interview_failed',
            'rejection_reason' => 'nullable|string',
            'evaluation_note' => 'nullable|string',
        ]);

        $application = Application::with('period')->findOrFail($id);

        $ids = $this->manageableProjectIdList($request, 'applications.update_status');
        abort_unless(in_array((int) $application->project_id, $ids, true), 403, 'Bu basvuru icin yetkiniz bulunmuyor.');

        DB::beginTransaction();
        try {
            $application->update([
                'status' => $validated['status'],
                'rejection_reason' => $validated['rejection_reason'] ?? $application->rejection_reason,
                'evaluation_note' => $validated['evaluation_note'] ?? $application->evaluation_note,
            ]);

            if ($validated['status'] === 'accepted') {
                $hasAnotherActiveProject = Participant::query()
                    ->where('user_id', $application->user_id)
                    ->where('status', 'active')
                    ->where('project_id', '!=', $application->project_id)
                    ->exists();

                if ($hasAnotherActiveProject) {
                    throw ValidationException::withMessages([
                        'status' => ['Bu kullanici aktif olarak baska bir projede yer aldigi icin kabul edilemez.'],
                    ]);
                }

                Participant::firstOrCreate([
                    'user_id' => $application->user_id,
                    'project_id' => $application->project_id,
                    'period_id' => $application->period_id,
                ], [
                    'status' => 'active',
                    'credit' => $application->period->credit_start_amount ?? 100,
                    'enrolled_at' => now(),
                ]);
            }

            DB::commit();

            return response()->json([
                'message' => 'Başvuru durumu başarıyla güncellendi.',
                'application' => $application,
            ]);
        } catch (ValidationException $e) {
            DB::rollBack();

            throw $e;
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['message' => 'Bir hata oluştu.'], 500);
        }
    }

    /**
     * PUT /admin/applications/{id}/interview — Mülakat Tarihi Planla
     */
    public function planInterview(Request $request, $id)
    {
        $this->abortUnlessAllowed($request, 'applications.plan_interview');

        $validated = $request->validate([
            'interview_at' => 'required|date|after:now',
        ]);

        $application = Application::findOrFail($id);

        abort_unless(
            $this->permissionResolver->canAccessProject(
                $request->user(),
                'applications.plan_interview',
                (int) $application->project_id
            ),
            403,
            'Bu basvuru icin yetkiniz bulunmuyor.'
        );

        $application->update([
            'status' => 'interview_planned',
            'interview_at' => $validated['interview_at'],
        ]);

        return response()->json([
            'message' => 'Mülakat tarihi başarıyla planlandı.',
            'application' => $application,
        ]);
    }

    /**
     * POST /admin/applications/{id}/waitlist — Yedeğe Al
     */
    public function addToWaitlist(Request $request, $id)
    {
        $this->abortUnlessAllowed($request, 'applications.waitlist.manage');

        $application = Application::findOrFail($id);

        abort_unless(
            $this->permissionResolver->canAccessProject(
                $request->user(),
                'applications.waitlist.manage',
                (int) $application->project_id
            ),
            403,
            'Bu basvuru icin yetkiniz bulunmuyor.'
        );

        $application->update([
            'status' => 'waitlisted',
            'evaluation_note' => $request->evaluation_note ?? $application->evaluation_note,
        ]);

        return response()->json([
            'message' => 'Başvuru yedeğe alındı.',
            'application' => $application,
        ]);
    }
}
