<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Trainer;
use App\Services\NotificationService;
use App\Services\PermissionResolver;
use App\Support\AdminExportResponder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * @group Trainers
 */
class TrainerController extends Controller
{
    private const STATUSES = ['active', 'passive', 'candidate'];

    public function __construct(
        private readonly PermissionResolver $permissionResolver,
        private readonly NotificationService $notificationService,
    ) {
    }

    /**
     * List trainers.
     *
     * Requires permission: `trainers.view` with a usable non-empty scope. Trainers are project-independent records, so no project ownership filter is applied; the scope check only decides whether the action is usable in the panel. Returns paginated trainers, summary stats and allowed status values.
     *
     * @group Trainers
     * @queryParam search string Optional search across name, email, phone, title, organization and expertise. Example: liderlik
     * @queryParam status string Optional status filter. Allowed values: active, passive, candidate. Example: active
     * @queryParam per_page integer Optional page size between 5 and 100. Defaults to 20. Example: 20
     * @response 200 {"trainers":{"data":[{"id":1,"first_name":"Ayse","last_name":"Yilmaz","full_name":"Ayse Yilmaz","email":"ayse@example.com","status":"active","kademe_comment":"Guclu atolye yonetimi"}],"current_page":1},"stats":{"total":12,"active":8,"candidate":2,"with_email":10},"statuses":["active","passive","candidate"]}
     * @response 403 {"message":"Bu islem icin kapsam verilmemis."}
     */

    public function index(Request $request): JsonResponse
    {
        $this->abortUnlessUsableScope($request, 'trainers.view');

        $validated = $request->validate([
            'search' => 'nullable|string|max:120',
            'status' => 'nullable|in:active,passive,candidate',
            'per_page' => 'nullable|integer|min:5|max:100',
        ]);

        $query = Trainer::query()
            ->with(['creator:id,name,surname', 'updater:id,name,surname', 'commentUpdater:id,name,surname'])
            ->latest('updated_at');

        $this->applyFilters($query, $validated);

        return response()->json([
            'trainers' => $query->paginate((int) ($validated['per_page'] ?? 20)),
            'stats' => [
                'total' => Trainer::query()->count(),
                'active' => Trainer::query()->where('status', 'active')->count(),
                'candidate' => Trainer::query()->where('status', 'candidate')->count(),
                'with_email' => Trainer::query()->whereNotNull('email')->where('email', '!=', '')->count(),
            ],
            'statuses' => self::STATUSES,
        ]);
    }

    /**
     * Get trainer details.
     *
     * Requires permission: `trainers.view` with a usable scope. Includes creator, updater and Kademe comment updater metadata when available.
     *
     * @group Trainers
     * @urlParam id integer required Trainer ID. Example: 1
     * @response 200 {"trainer":{"id":1,"first_name":"Ayse","last_name":"Yilmaz","full_name":"Ayse Yilmaz","email":"ayse@example.com","phone":"05550000000","title":"Egitmen","organization":"Kademe","expertise":"Liderlik","status":"active","kademe_comment":"Guclu iletisim"}}
     * @response 404 {"message":"No query results for model [App\\Models\\Trainer] 1"}
     */

    public function show(Request $request, int $id): JsonResponse
    {
        $this->abortUnlessUsableScope($request, 'trainers.view');

        return response()->json([
            'trainer' => $this->trainerQuery()->findOrFail($id),
        ]);
    }

    /**
     * Create a trainer.
     *
     * Requires permission: `trainers.create` with a usable scope. Creates a project-independent trainer profile, stores creator/updater metadata and prepares audit attributes for the activity log.
     *
     * @group Trainers
     * @bodyParam first_name string required Trainer first name. Max 120 characters. Example: Ayse
     * @bodyParam last_name string Optional trainer last name. Max 120 characters. Example: Yilmaz
     * @bodyParam email string Optional trainer email. Example: ayse@example.com
     * @bodyParam phone string Optional phone number. Example: 05550000000
     * @bodyParam title string Optional title. Example: Egitmen
     * @bodyParam organization string Optional organization. Example: Kademe
     * @bodyParam expertise string Optional expertise summary. Example: Liderlik, iletisim
     * @bodyParam status string Optional status. Allowed values: active, passive, candidate. Defaults to active. Example: candidate
     * @bodyParam last_worked_at date Optional last worked date. Example: 2026-06-01
     * @bodyParam bio string Optional biography. Max 5000 characters.
     * @bodyParam notes string Optional internal notes. Max 5000 characters.
     * @response 201 {"message":"Egitmen kaydi olusturuldu.","trainer":{"id":1,"full_name":"Ayse Yilmaz","status":"candidate"}}
     * @response 422 {"message":"The first name field is required."}
     */

    public function store(Request $request): JsonResponse
    {
        $this->abortUnlessUsableScope($request, 'trainers.create');

        $validated = $this->validatedTrainer($request);
        $validated['created_by'] = $request->user()->id;
        $validated['updated_by'] = $request->user()->id;

        $trainer = Trainer::query()->create($validated);
        $this->setTrainerAudit($request, $trainer, 'trainer_created');

        return response()->json([
            'message' => 'Egitmen kaydi olusturuldu.',
            'trainer' => $this->trainerQuery()->find($trainer->id),
        ], 201);
    }

    /**
     * Update a trainer.
     *
     * Requires permission: `trainers.update` with a usable scope. All profile fields are optional on update; updater metadata and trainer audit attributes are refreshed.
     *
     * @group Trainers
     * @urlParam id integer required Trainer ID. Example: 1
     * @bodyParam first_name string Optional trainer first name. Max 120 characters. Example: Ayse
     * @bodyParam last_name string Optional trainer last name. Max 120 characters. Example: Yilmaz
     * @bodyParam email string Optional trainer email. Example: ayse@example.com
     * @bodyParam phone string Optional phone number. Example: 05550000000
     * @bodyParam title string Optional title. Example: Bas Egitmen
     * @bodyParam organization string Optional organization. Example: Kademe
     * @bodyParam expertise string Optional expertise summary. Example: Kariyer planlama
     * @bodyParam status string Optional status. Allowed values: active, passive, candidate. Example: active
     * @bodyParam last_worked_at date Optional last worked date. Example: 2026-06-01
     * @bodyParam bio string Optional biography. Max 5000 characters.
     * @bodyParam notes string Optional internal notes. Max 5000 characters.
     * @response 200 {"message":"Egitmen kaydi guncellendi.","trainer":{"id":1,"full_name":"Ayse Yilmaz","status":"active"}}
     */

    public function update(Request $request, int $id): JsonResponse
    {
        $this->abortUnlessUsableScope($request, 'trainers.update');

        $trainer = Trainer::query()->findOrFail($id);
        $validated = $this->validatedTrainer($request, false);
        $validated['updated_by'] = $request->user()->id;
        $trainer->update($validated);
        $this->setTrainerAudit($request, $trainer, 'trainer_updated');

        return response()->json([
            'message' => 'Egitmen kaydi guncellendi.',
            'trainer' => $this->trainerQuery()->find($trainer->id),
        ]);
    }

    /**
     * Update the Kademe comment for a trainer.
     *
     * Requires permission: `trainers.comment` with a usable scope. This stores the single editable Kademe impression/comment for the trainer, comment updater metadata and an audit event.
     *
     * @group Trainers
     * @urlParam id integer required Trainer ID. Example: 1
     * @bodyParam kademe_comment string Optional Kademe comment. Null or empty clears the comment. Max 5000 characters. Example: Atolye sonrasi geri bildirimleri olumlu.
     * @response 200 {"message":"Kademe yorumu guncellendi.","trainer":{"id":1,"kademe_comment":"Atolye sonrasi geri bildirimleri olumlu."}}
     * @response 403 {"message":"Bu islem icin kapsam verilmemis."}
     */

    public function updateComment(Request $request, int $id): JsonResponse
    {
        $this->abortUnlessUsableScope($request, 'trainers.comment');

        $validated = $request->validate([
            'kademe_comment' => 'nullable|string|max:5000',
        ]);

        $trainer = Trainer::query()->findOrFail($id);
        $trainer->update([
            'kademe_comment' => $validated['kademe_comment'] ?? null,
            'comment_updated_by' => $request->user()->id,
            'comment_updated_at' => now(),
            'updated_by' => $request->user()->id,
        ]);
        $this->setTrainerAudit($request, $trainer, 'trainer_comment_updated', [
            'comment_present' => filled($trainer->kademe_comment),
        ]);

        return response()->json([
            'message' => 'Kademe yorumu guncellendi.',
            'trainer' => $this->trainerQuery()->find($trainer->id),
        ]);
    }

    /**
     * Send an email to a trainer.
     *
     * Requires permission: `trainers.email` with a usable scope. The trainer must have an email address. Sends through the existing notification/email service and stores trainer email audit attributes.
     *
     * @group Trainers
     * @urlParam id integer required Trainer ID. Example: 1
     * @bodyParam subject string required Email subject. Max 180 characters. Example: Program Daveti
     * @bodyParam body string required Email body. Max 10000 characters. Example: Merhaba, sizi yeni programa davet etmek isteriz.
     * @response 200 {"message":"E-posta gonderildi.","sent_count":1}
     * @response 422 {"message":"Bu egitmen icin e-posta adresi bulunmuyor."}
     */

    public function sendEmail(Request $request, int $id): JsonResponse
    {
        $this->abortUnlessUsableScope($request, 'trainers.email');

        $trainer = Trainer::query()->findOrFail($id);
        abort_if(! $trainer->email, 422, 'Bu egitmen icin e-posta adresi bulunmuyor.');

        $validated = $request->validate([
            'subject' => 'required|string|max:180',
            'body' => 'required|string|max:10000',
        ]);

        $sentCount = $this->notificationService->sendEmail(
            [$trainer->email],
            $validated['subject'],
            $validated['body'],
            null,
            $request->user()->id,
        );
        $this->setTrainerAudit($request, $trainer, 'trainer_email_sent', [
            'subject' => $validated['subject'],
            'sent_count' => $sentCount,
        ]);

        return response()->json([
            'message' => $sentCount > 0 ? 'E-posta gonderildi.' : 'E-posta gonderimi tamamlanamadi, iletisim loguna kaydedildi.',
            'sent_count' => $sentCount,
        ]);
    }

    /**
     * Delete a trainer.
     *
     * Requires permission: `trainers.delete` with a usable scope. Trainers use soft deletes, so the row is marked deleted after audit attributes are prepared.
     *
     * @group Trainers
     * @urlParam id integer required Trainer ID. Example: 1
     * @response 200 {"message":"Egitmen kaydi silindi."}
     */

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->abortUnlessUsableScope($request, 'trainers.delete');

        $trainer = Trainer::query()->findOrFail($id);
        $this->setTrainerAudit($request, $trainer, 'trainer_deleted');
        $trainer->delete();

        return response()->json(['message' => 'Egitmen kaydi silindi.']);
    }

    /**
     * Export trainers.
     *
     * Requires permission: `trainers.export` with a usable scope. Applies the same search/status filters as the list endpoint and returns an exported file through the shared admin export responder. Supported formats include CSV, Excel/XLSX, PDF and Word/DOCX.
     *
     * @group Trainers
     * @queryParam search string Optional search filter. Example: liderlik
     * @queryParam status string Optional status filter. Allowed values: active, passive, candidate. Example: active
     * @queryParam format string Optional export format. Allowed values: xlsx, excel, pdf, docx, word, csv. Defaults to csv. Example: xlsx
     * @response 200 {"download":"Trainer export file stream"}
     * @response 403 {"message":"Bu islem icin kapsam verilmemis."}
     */

    public function export(Request $request)
    {
        $this->abortUnlessUsableScope($request, 'trainers.export');

        $validated = $request->validate([
            'search' => 'nullable|string|max:120',
            'status' => 'nullable|in:active,passive,candidate',
            'format' => 'nullable|string|in:xlsx,excel,pdf,docx,word,csv',
        ]);

        $query = Trainer::query()->latest('updated_at');
        $this->applyFilters($query, $validated);

        $rows = $query->get()->map(fn (Trainer $trainer) => [
            $trainer->id,
            $trainer->full_name,
            $trainer->email ?? '-',
            $trainer->phone ?? '-',
            $trainer->title ?? '-',
            $trainer->organization ?? '-',
            $trainer->expertise ?? '-',
            $this->statusLabel($trainer->status),
            $trainer->last_worked_at?->format('d.m.Y') ?? '-',
            Str::limit((string) $trainer->kademe_comment, 120),
        ])->all();

        return AdminExportResponder::download(
            $request->string('format')->toString() ?: 'csv',
            'egitmenler_' . now()->format('Ymd_His'),
            'Egitmenler',
            ['ID', 'Ad Soyad', 'E-posta', 'Telefon', 'Unvan', 'Kurum', 'Uzmanlik', 'Durum', 'Son Calisma', 'Kademe Yorumu'],
            $rows,
        );
    }

    private function setTrainerAudit(Request $request, Trainer $trainer, string $operation, array $extra = []): void
    {
        $request->attributes->set('audit.subject', $trainer);
        $request->attributes->set('audit.event', 'trainers.' . $operation);
        $request->attributes->set('audit.description', 'trainers.' . $operation);
        $request->attributes->set('audit.properties', array_merge([
            'operation' => $operation,
            'trainer_id' => $trainer->id,
            'trainer_name' => $trainer->full_name,
            'status' => $trainer->status,
            'email_present' => filled($trainer->email),
        ], $extra));
    }

    private function trainerQuery()
    {
        return Trainer::query()->with(['creator:id,name,surname', 'updater:id,name,surname', 'commentUpdater:id,name,surname']);
    }

    private function validatedTrainer(Request $request, bool $creating = true): array
    {
        return $request->validate([
            'first_name' => [$creating ? 'required' : 'sometimes', 'string', 'max:120'],
            'last_name' => 'nullable|string|max:120',
            'email' => 'nullable|email|max:180',
            'phone' => 'nullable|string|max:60',
            'title' => 'nullable|string|max:180',
            'organization' => 'nullable|string|max:180',
            'expertise' => 'nullable|string|max:255',
            'status' => 'nullable|in:active,passive,candidate',
            'last_worked_at' => 'nullable|date',
            'bio' => 'nullable|string|max:5000',
            'notes' => 'nullable|string|max:5000',
        ]);
    }

    private function applyFilters($query, array $validated): void
    {
        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['search'])) {
            $search = trim((string) $validated['search']);
            $query->where(function ($builder) use ($search) {
                $builder->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('title', 'like', "%{$search}%")
                    ->orWhere('organization', 'like', "%{$search}%")
                    ->orWhere('expertise', 'like', "%{$search}%");
            });
        }
    }

    private function abortUnlessUsableScope(Request $request, string $permission): void
    {
        $user = $request->user();
        abort_unless($user && $this->permissionResolver->hasPermission($user, $permission), 403);

        $scopeType = $this->permissionResolver->scopeFor($user, $permission)['scope_type'] ?? 'none';
        abort_if(in_array($scopeType, ['none', ''], true), 403, 'Bu islem icin kapsam verilmemis.');
    }

    private function statusLabel(?string $status): string
    {
        return match ($status) {
            'active' => 'Aktif',
            'candidate' => 'Aday',
            'passive' => 'Pasif',
            default => '-',
        };
    }
}
