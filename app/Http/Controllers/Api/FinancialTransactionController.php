<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesGranularPermissions;
use App\Http\Controllers\Concerns\ResolvesProjectPeriodContext;
use App\Http\Controllers\Controller;
use App\Models\FinancialTransaction;
use App\Models\Project;
use App\Support\AdminExportResponder;
use App\Support\MediaStorage;
use App\Services\PermissionResolver;
use App\Models\User;
use App\Support\ProjectPeriodContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * @group Financials
 */
class FinancialTransactionController extends Controller
{
    use AuthorizesGranularPermissions;
    use ResolvesProjectPeriodContext;

    public function __construct(
        private readonly PermissionResolver $permissionResolver
    ) {
    }

    /**
     * Süper admin dışı: yalnızca manageable_project_ids içindeki project_id kayıtları (null proje satırları dahil değil).
     */
    private function scopeFinancialTransactionsForUser(
        $query,
        \Illuminate\Contracts\Auth\Authenticatable $user,
        string $permissionName = 'financial.view'
    ): void
    {
        if ($user instanceof \App\Models\User && $this->permissionResolver->hasGlobalScope($user, $permissionName)) {
            return;
        }

        if (! $user instanceof \App\Models\User) {
            $query->whereRaw('1 = 0');

            return;
        }

        $ids = $this->permissionResolver->projectIdsForPermission($user, $permissionName);
        if ($ids === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereIn('project_id', $ids);
    }

    private function canAccessFinancialProject(User $user, string $permissionName, ?int $projectId): bool
    {
        if ($projectId === null) {
            return $this->permissionResolver->hasPermission($user, $permissionName);
        }

        return $this->permissionResolver->canAccessProject($user, $permissionName, $projectId);
    }

    private function applyFinancialContext($query, User $user, string $permissionName, ProjectPeriodContext $context): void
    {
        if ($context->projectId !== null) {
            $query->where('project_id', $context->projectId);
        } elseif (! $this->permissionResolver->hasGlobalScope($user, $permissionName)) {
            $projectIds = $context->projectIdsForQuery();
            if ($projectIds === []) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('project_id', $projectIds);
            }
        }

        if ($context->periodId !== null) {
            $query->where('period_id', $context->periodId);
        }
    }

    private function applyFinancialFilters($query, Request $request, bool $includeProjectPeriod = true): void
    {
        if ($includeProjectPeriod && $request->filled('project_id')) {
            $query->where('project_id', $request->project_id);
        }
        if ($includeProjectPeriod && $request->filled('period_id')) {
            $query->where('period_id', $request->period_id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }
        if ($request->filled('payee')) {
            $query->where(function ($builder) use ($request) {
                $needle = '%' . $request->payee . '%';
                $builder
                    ->where('payee_name', 'like', $needle)
                    ->orWhere('spending_unit', 'like', $needle)
                    ->orWhere('invoice_no', 'like', $needle)
                    ->orWhere('accounting_code', 'like', $needle);
            });
        }
        if ($request->filled('date_from')) {
            $query->where('submitted_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->where('submitted_at', '<=', $request->date_to . ' 23:59:59');
        }
    }

    private function attachFinancialAudit(Request $request, FinancialTransaction $transaction, string $operation, ?string $statusBefore = null): void
    {
        $request->attributes->set('audit.subject', $transaction);
        $request->attributes->set('audit.event', 'financial.' . $operation);
        $request->attributes->set('audit.description', 'financial.' . $operation);
        $request->attributes->set('audit.properties', [
            'operation' => 'financial_' . $operation,
            'financial_transaction_id' => $transaction->id,
            'project_id' => $transaction->project_id,
            'period_id' => $transaction->period_id,
            'type' => $transaction->type,
            'category' => $transaction->category,
            'spending_unit' => $transaction->spending_unit,
            'payee_name' => $transaction->payee_name,
            'amount' => (float) $transaction->amount,
            'invoice_no' => $transaction->invoice_no,
            'payment_date' => optional($transaction->payment_date)?->toDateString(),
            'payment_method' => $transaction->payment_method,
            'accounting_code' => $transaction->accounting_code,
            'status_before' => $statusBefore,
            'status_after' => $transaction->status,
            'submitted_by' => $transaction->submitted_by,
            'approved_by' => $transaction->approved_by,
            'invoice_present' => ! empty($transaction->invoice_path),
        ]);
    }

    /**
     * List financial transactions.
     *
     * Panel/admin endpoint exposed under `/admin/financials` and `/panel/financials`. Requires `financial.view`; users with global scope see all matching transactions, while scoped users are limited to the project ids resolved by the action+scope matrix. `project_id` and `period_id` are validated through project-period context, so archive/period restrictions remain aligned with the panel.
     *
     * Returns paginated transactions plus total amount, category totals, project totals, and status totals for dashboard cards/charts.
     *
     * @group Financials
     * @authenticated
     *
     * @queryParam project_id integer Optional project filter; scoped by `financial.view`. Example: 1
     * @queryParam period_id integer Optional period filter; must belong to the selected/allowed project. Example: 3
     * @queryParam status string Optional status filter. Example: pending
     * @queryParam category string Optional category filter. Example: travel
     * @queryParam type string Optional transaction type: expense or payment. Example: expense
     * @queryParam payee string Optional payee, spending unit, invoice no, or accounting code search. Example: Otel
     * @queryParam date_from date Optional submitted date lower bound. Example: 2026-01-01
     * @queryParam date_to date Optional submitted date upper bound. Example: 2026-01-31
     * @response 200 {"transactions":{"data":[{"id":1,"type":"expense","category":"travel","amount":"1250.00","status":"pending"}]},"total_amount":"1250.00","category_stats":[],"project_stats":[],"status_stats":[]}
     * @response 403 {"message":"This action is unauthorized."}
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'project_id' => 'nullable|exists:projects,id',
            'period_id' => 'nullable|exists:periods,id',
        ]);
        $user = $request->user();
        $context = $this->resolveProjectPeriodContext(
            $request,
            'financial.view',
            ! empty($validated['project_id']) ? (int) $validated['project_id'] : null,
            ! empty($validated['period_id']) ? (int) $validated['period_id'] : null,
        );
        $query = FinancialTransaction::with([
            'project:id,name',
            'period:id,name',
            'submitter:id,name,surname',
            'approver:id,name,surname',
        ]);
        $this->applyFinancialContext($query, $user, 'financial.view', $context);

        $this->applyFinancialFilters($query, $request, false);

        $transactions = $query->latest('submitted_at')->paginate(20);

        // Toplam tutar hesaplama
        $totalQuery = FinancialTransaction::query();
        $this->applyFinancialContext($totalQuery, $user, 'financial.view', $context);
        $this->applyFinancialFilters($totalQuery, $request, false);
        $totalAmount = $totalQuery->sum('amount');

        // Kategori bazlı infografik
        $categoryStats = FinancialTransaction::query()
            ->tap(fn ($q) => $this->applyFinancialContext($q, $user, 'financial.view', $context))
            ->selectRaw('category, SUM(amount) as total, COUNT(*) as count')
            ->tap(fn ($q) => $this->applyFinancialFilters($q, $request, false))
            ->groupBy('category')
            ->get();

        // Proje bazlı harcama
        $projectStats = FinancialTransaction::query()
            ->tap(fn ($q) => $this->applyFinancialContext($q, $user, 'financial.view', $context))
            ->with('project:id,name')
            ->selectRaw('project_id, SUM(amount) as total')
            ->tap(fn ($q) => $this->applyFinancialFilters($q, $request, false))
            ->groupBy('project_id')
            ->get();

        $statusStats = FinancialTransaction::query()
            ->tap(fn ($q) => $this->applyFinancialContext($q, $user, 'financial.view', $context))
            ->selectRaw('status, SUM(amount) as total, COUNT(*) as count')
            ->tap(fn ($q) => $this->applyFinancialFilters($q, $request, false))
            ->groupBy('status')
            ->get();

        return response()->json([
            'transactions' => $transactions,
            'total_amount' => $totalAmount,
            'category_stats' => $categoryStats,
            'project_stats' => $projectStats,
            'status_stats' => $statusStats,
        ]);
    }

    /**
     * Create a financial transaction.
     *
     * Panel/admin and coordinator endpoint exposed under `/admin/financials`, `/panel/financials`, and `/coordinator/financials`. Requires `financial.create`. If `project_id` is supplied, the actor must have create scope for that project; creating a projectless financial transaction requires global `financial.create` scope. If `period_id` is supplied, it must belong to the selected project and the period must be writable.
     *
     * Send as `multipart/form-data` when uploading an invoice. Created transactions start as `pending` and are audit logged as `financial.created`.
     *
     * @group Financials
     * @authenticated
     *
     * @bodyParam project_id integer Optional project id. Required for non-global scoped users. Example: 1
     * @bodyParam period_id integer Optional period id belonging to the selected project. Example: 3
     * @bodyParam type string required Transaction type, one of `expense` or `payment`. Example: expense
     * @bodyParam category string required Transaction category, max 80 chars. Example: travel
     * @bodyParam spending_unit string Optional spending unit/cost center. Example: Operasyon
     * @bodyParam payee_name string required Person or company to be paid. Example: ABC Turizm
     * @bodyParam amount number required Amount, minimum 0.01. Example: 1250.50
     * @bodyParam invoice_no string Optional invoice number. Example: INV-2026-001
     * @bodyParam payment_date date Optional planned or actual payment date. Example: 2026-02-01
     * @bodyParam payment_method string Optional payment method. Example: bank_transfer
     * @bodyParam accounting_code string Optional accounting code. Example: 770.01
     * @bodyParam invoice file Optional invoice file; pdf, jpg, jpeg, png, max 10MB.
     * @response 201 {"message":"Islem basariyla kaydedildi.","transaction":{"id":1,"status":"pending","amount":"1250.50"}}
     * @response 403 {"message":"Projesiz mali islem icin global kapsam gerekir."}
     * @response 422 {"message":"Secilen donem bu projeye ait degil."}
     */
    public function store(Request $request)
    {
        $this->abortUnlessAllowed($request, 'financial.create');
        $validated = $request->validate([
            'project_id'  => 'nullable|exists:projects,id',
            'period_id'   => 'nullable|exists:periods,id',
            'type'        => 'required|in:expense,payment',
            'category'    => 'required|string|max:80',
            'spending_unit' => 'nullable|string|max:150',
            'payee_name'  => 'required|string|max:255',
            'amount'      => 'required|numeric|min:0.01',
            'invoice_no'  => 'nullable|string|max:100',
            'payment_date' => 'nullable|date',
            'payment_method' => 'nullable|string|max:80',
            'accounting_code' => 'nullable|string|max:80',
            'invoice'     => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);

        if (!empty($validated['project_id'])) {
            $this->abortUnlessProjectAllowed($request, 'financial.create', (int) $validated['project_id']);
        } elseif (! $this->permissionResolver->hasGlobalScope($request->user(), 'financial.create')) {
            abort(403, 'Projesiz mali islem icin global kapsam gerekir.');
        }

        if (! empty($validated['period_id'])) {
            abort_unless(
                ! empty($validated['project_id'])
                && \App\Models\Period::query()
                    ->whereKey((int) $validated['period_id'])
                    ->where('project_id', (int) $validated['project_id'])
                    ->exists(),
                422,
                'Secilen donem bu projeye ait degil.'
            );
            $this->assertPeriodWritable($request, (int) $validated['period_id']);
        }

        $invoicePath = null;
        if ($request->hasFile('invoice')) {
            $invoicePath = MediaStorage::putFile('invoices', $request->file('invoice'));
        }

        $transaction = FinancialTransaction::create([
            'project_id'   => $validated['project_id'] ?? null,
            'period_id'    => $validated['period_id'] ?? null,
            'type'         => $validated['type'],
            'category'     => $validated['category'],
            'spending_unit' => $validated['spending_unit'] ?? null,
            'payee_name'   => $validated['payee_name'],
            'amount'       => $validated['amount'],
            'status'       => 'pending',
            'invoice_path' => $invoicePath,
            'invoice_no'   => $validated['invoice_no'] ?? null,
            'submitted_by' => Auth::id(),
            'submitted_at' => now(),
            'payment_date' => $validated['payment_date'] ?? null,
            'payment_method' => $validated['payment_method'] ?? null,
            'accounting_code' => $validated['accounting_code'] ?? null,
        ]);

        $this->attachFinancialAudit($request, $transaction, 'created');

        return response()->json([
            'message'     => 'İşlem başarıyla kaydedildi.',
            'transaction' => $transaction->load(['project:id,name', 'submitter:id,name,surname']),
        ], 201);
    }

    /**
     * Show a financial transaction.
     *
     * Panel/admin endpoint exposed under `/admin/financials/{id}` and `/panel/financials/{id}`. Requires `financial.view` access to the transaction project; projectless records require the permission itself. Includes project, period, submitter, and approver summary data.
     *
     * @group Financials
     * @authenticated
     *
     * @urlParam id integer required Financial transaction id. Example: 1
     * @response 200 {"transaction":{"id":1,"type":"expense","category":"travel","status":"pending","project":{"id":1,"name":"KADEME"}}}
     * @response 403 {"message":"Bu isleme erisim yetkiniz yok."}
     * @response 404 {"message":"No query results for model"}
     */
    public function show(Request $request, int $id)
    {
        $transaction = FinancialTransaction::with([
            'project:id,name',
            'period:id,name',
            'submitter:id,name,surname',
            'approver:id,name,surname',
        ])->findOrFail($id);

        abort_unless(
            $this->canAccessFinancialProject($request->user(), 'financial.view', $transaction->project_id),
            403,
            'Bu isleme erisim yetkiniz yok.'
        );

        return response()->json(['transaction' => $transaction]);
    }

    /**
     * Approve a financial transaction.
     *
     * Panel/admin endpoint exposed under `/admin/financials/{id}/approve` and `/panel/financials/{id}/approve`. Requires `financial.approve` and access to the transaction project. The related period must be writable and only `pending` transactions can be approved. Status changes are audit logged as `financial.approved`.
     *
     * @group Financials
     * @authenticated
     *
     * @urlParam id integer required Financial transaction id. Example: 1
     * @response 200 {"message":"Islem onaylandi.","transaction":{"id":1,"status":"approved"}}
     * @response 403 {"message":"Bu islem icin onay yetkiniz yok."}
     * @response 422 {"message":"Bu islem zaten islenmis."}
     */
    public function approve(Request $request, int $id)
    {
        $this->abortUnlessAllowed($request, 'financial.approve');
        $transaction = FinancialTransaction::findOrFail($id);
        abort_unless(
            $this->canAccessFinancialProject($request->user(), 'financial.approve', $transaction->project_id),
            403,
            'Bu islem icin onay yetkiniz yok.'
        );
        $this->assertPeriodWritable($request, $transaction->period_id);

        if ($transaction->status !== 'pending') {
            return response()->json(['message' => 'Bu işlem zaten işlenmiş.'], 422);
        }

        $statusBefore = $transaction->status;
        $transaction->update([
            'status'      => 'approved',
            'approved_by' => Auth::id(),
            'approved_at' => now(),
        ]);
        $transaction = $transaction->fresh();
        $this->attachFinancialAudit($request, $transaction, 'approved', $statusBefore);

        return response()->json([
            'message'     => 'İşlem onaylandı.',
            'transaction' => $transaction->load(['approver:id,name,surname']),
        ]);
    }

    /**
     * Reject a financial transaction.
     *
     * Panel/admin endpoint exposed under `/admin/financials/{id}/reject` and `/panel/financials/{id}/reject`. Requires `financial.reject` and access to the transaction project. The related period must be writable and only `pending` transactions can be rejected. Status changes are audit logged as `financial.rejected`.
     *
     * @group Financials
     * @authenticated
     *
     * @urlParam id integer required Financial transaction id. Example: 1
     * @response 200 {"message":"Islem reddedildi.","transaction":{"id":1,"status":"rejected"}}
     * @response 403 {"message":"Bu islem icin red yetkiniz yok."}
     * @response 422 {"message":"Bu islem zaten islenmis."}
     */
    public function reject(Request $request, int $id)
    {
        $this->abortUnlessAllowed($request, 'financial.reject');
        $transaction = FinancialTransaction::findOrFail($id);
        abort_unless(
            $this->canAccessFinancialProject($request->user(), 'financial.reject', $transaction->project_id),
            403,
            'Bu islem icin red yetkiniz yok.'
        );
        $this->assertPeriodWritable($request, $transaction->period_id);

        if ($transaction->status !== 'pending') {
            return response()->json(['message' => 'Bu işlem zaten işlenmiş.'], 422);
        }

        $statusBefore = $transaction->status;
        $transaction->update([
            'status'      => 'rejected',
            'approved_by' => Auth::id(),
            'approved_at' => now(),
        ]);
        $transaction = $transaction->fresh();
        $this->attachFinancialAudit($request, $transaction, 'rejected', $statusBefore);

        return response()->json(['message' => 'İşlem reddedildi.', 'transaction' => $transaction]);
    }

    /**
     * Mark a financial transaction as paid.
     *
     * Panel/admin endpoint exposed under `/admin/financials/{id}/pay` and `/panel/financials/{id}/pay`. Requires `financial.mark_paid` and access to the transaction project. The related period must be writable and only `approved` transactions can be marked as paid. If `payment_date` is empty, the controller sets it to today. Status changes are audit logged as `financial.paid`.
     *
     * @group Financials
     * @authenticated
     *
     * @urlParam id integer required Financial transaction id. Example: 1
     * @response 200 {"message":"Odeme tamamlandi.","transaction":{"id":1,"status":"paid"}}
     * @response 403 {"message":"Bu islem icin odeme yetkiniz yok."}
     * @response 422 {"message":"Sadece onaylanan islemler odenmis olarak isaretlenebilir."}
     */
    public function markPaid(Request $request, int $id)
    {
        $this->abortUnlessAllowed($request, 'financial.mark_paid');
        $transaction = FinancialTransaction::findOrFail($id);
        abort_unless(
            $this->canAccessFinancialProject($request->user(), 'financial.mark_paid', $transaction->project_id),
            403,
            'Bu islem icin odeme yetkiniz yok.'
        );
        $this->assertPeriodWritable($request, $transaction->period_id);

        if ($transaction->status !== 'approved') {
            return response()->json(['message' => 'Sadece onaylanan işlemler ödenmiş olarak işaretlenebilir.'], 422);
        }

        $statusBefore = $transaction->status;
        $transaction->update([
            'status' => 'paid',
            'payment_date' => $transaction->payment_date ?? now()->toDateString(),
        ]);
        $transaction = $transaction->fresh();
        $this->attachFinancialAudit($request, $transaction, 'paid', $statusBefore);

        return response()->json(['message' => 'Ödeme tamamlandı.', 'transaction' => $transaction]);
    }

    /**
     * Delete a pending financial transaction.
     *
     * Panel/admin endpoint exposed under `/admin/financials/{id}` and `/panel/financials/{id}`. Requires `financial.delete` and access to the transaction project. The related period must be writable and only `pending` transactions can be deleted. Attached invoice files are removed from storage and the deletion is audit logged as `financial.deleted`.
     *
     * @group Financials
     * @authenticated
     *
     * @urlParam id integer required Financial transaction id. Example: 1
     * @response 200 {"message":"Islem silindi."}
     * @response 403 {"message":"Bu islem icin silme yetkiniz yok."}
     * @response 422 {"message":"Sadece bekleyen islemler silinebilir."}
     */
    public function destroy(Request $request, int $id)
    {
        $this->abortUnlessAllowed($request, 'financial.delete');
        $transaction = FinancialTransaction::findOrFail($id);
        abort_unless(
            $this->canAccessFinancialProject($request->user(), 'financial.delete', $transaction->project_id),
            403,
            'Bu islem icin silme yetkiniz yok.'
        );
        $this->assertPeriodWritable($request, $transaction->period_id);

        // Sadece pending işlemler silinebilir
        if ($transaction->status !== 'pending') {
            return response()->json(['message' => 'Sadece bekleyen işlemler silinebilir.'], 422);
        }

        if ($transaction->invoice_path) {
            MediaStorage::delete($transaction->invoice_path);
        }

        $this->attachFinancialAudit($request, $transaction, 'deleted', $transaction->status);
        $transaction->delete();

        return response()->json(['message' => 'İşlem silindi.']);
    }

    /**
     * Download a financial invoice.
     *
     * Panel/admin endpoint exposed under `/admin/financials/{id}/invoice` and `/panel/financials/{id}/invoice`. Requires `financial.invoice.download` and access to the transaction project. If `direct=true` and public/direct downloads are configured, returns a `download_url`; otherwise streams the stored file with its MIME type.
     *
     * @group Financials
     * @authenticated
     *
     * @urlParam id integer required Financial transaction id. Example: 1
     * @queryParam direct boolean Optional. Return direct storage URL when configured. Example: true
     * @response 200 {"download_url":"https://storage.example.com/invoices/file.pdf"}
     * @response 200 {"download":"Binary invoice file stream"}
     * @response 403 {"message":"Bu fatura icin erisim yetkiniz yok."}
     * @response 404 {"message":"Fatura bulunamadi."}
     */
    public function downloadInvoice(Request $request, int $id)
    {
        $this->abortUnlessAllowed($request, 'financial.invoice.download');
        $transaction = FinancialTransaction::findOrFail($id);
        abort_unless(
            $this->canAccessFinancialProject($request->user(), 'financial.invoice.download', $transaction->project_id),
            403,
            'Bu fatura icin erisim yetkiniz yok.'
        );

        if (!$transaction->invoice_path) {
            return response()->json(['message' => 'Fatura bulunamadı.'], 404);
        }

        if (
            $request->boolean('direct')
            && MediaStorage::directDownloadsEnabled()
            && (MediaStorage::publicUrlConfigured() || MediaStorage::isUrl($transaction->invoice_path))
        ) {
            return response()->json([
                'download_url' => MediaStorage::url($transaction->invoice_path),
            ]);
        }

        if (MediaStorage::isUrl($transaction->invoice_path)) {
            return response()->json([
                'download_url' => $transaction->invoice_path,
            ]);
        }

        if (!MediaStorage::exists($transaction->invoice_path)) {
            return response()->json(['message' => 'Fatura bulunamadı.'], 404);
        }

        $fileName = 'fatura_' . $transaction->id . '_' . basename($transaction->invoice_path);
        $headers = [
            'Content-Type' => MediaStorage::mimeType($transaction->invoice_path) ?? 'application/octet-stream',
        ];

        return MediaStorage::disk()->download($transaction->invoice_path, $fileName, $headers);
    }

    /**
     * Export financial transactions.
     *
     * Panel/admin endpoint exposed under `/admin/financials/export` and `/panel/financials/export`. Requires `financial.export`; users with global scope export all matching transactions, while scoped users are limited to project ids resolved by the action+scope matrix. Project and period filters are validated through project-period context. The shared export responder accepts `csv`, `xlsx`, or `pdf` when enabled.
     *
     * @group Financials
     * @authenticated
     *
     * @queryParam project_id integer Optional project filter; scoped by `financial.export`. Example: 1
     * @queryParam period_id integer Optional period filter; must belong to the selected/allowed project. Example: 3
     * @queryParam status string Optional status filter. Example: paid
     * @queryParam category string Optional category filter. Example: travel
     * @queryParam type string Optional transaction type: expense or payment. Example: expense
     * @queryParam payee string Optional payee, spending unit, invoice no, or accounting code search. Example: ABC
     * @queryParam date_from date Optional submitted date lower bound. Example: 2026-01-01
     * @queryParam date_to date Optional submitted date upper bound. Example: 2026-01-31
     * @queryParam format string Optional export format. Example: xlsx
     * @response 200 {"download":"Export file stream"}
     * @response 403 {"message":"This action is unauthorized."}
     */
    public function export(Request $request)
    {
        $validated = $request->validate([
            'project_id' => 'nullable|exists:projects,id',
            'period_id' => 'nullable|exists:periods,id',
            'format' => 'nullable|string|max:20',
        ]);
        $context = $this->resolveProjectPeriodContext(
            $request,
            'financial.export',
            ! empty($validated['project_id']) ? (int) $validated['project_id'] : null,
            ! empty($validated['period_id']) ? (int) $validated['period_id'] : null,
        );
        $query = FinancialTransaction::with([
            'project:id,name',
            'period:id,name',
            'submitter:id,name,surname',
            'approver:id,name,surname',
        ]);
        $this->applyFinancialContext($query, $request->user(), 'financial.export', $context);

        $this->applyFinancialFilters($query, $request, false);

        $transactions = $query->latest('submitted_at')->get();

        $format = $request->get('format', 'csv');
        $headings = [
            'ID',
            'Proje',
            'Birim',
            'Donem',
            'Tur',
            'Kategori',
            'Odeme Yapilacak Kisi/Firma',
            'Fatura No',
            'Tutar',
            'Durum',
            'Odeme Tarihi',
            'Odeme Yontemi',
            'Muhasebe Kodu',
            'Gonderen',
            'Onaylayan',
            'Gonderim Tarihi',
            'Onay Tarihi',
        ];
        $rows = $transactions->map(fn (FinancialTransaction $transaction) => [
            $transaction->id,
            $transaction->project->name ?? '-',
            $transaction->spending_unit ?? '-',
            $transaction->period->name ?? '-',
            $transaction->type,
            $transaction->category,
            $transaction->payee_name,
            $transaction->invoice_no ?? '-',
            number_format((float) $transaction->amount, 2, '.', ''),
            $transaction->status,
            $transaction->payment_date?->format('d.m.Y') ?? '-',
            $transaction->payment_method ?? '-',
            $transaction->accounting_code ?? '-',
            $transaction->submitter ? $transaction->submitter->name . ' ' . $transaction->submitter->surname : '-',
            $transaction->approver ? $transaction->approver->name . ' ' . $transaction->approver->surname : '-',
            $transaction->submitted_at?->format('d.m.Y H:i') ?? '-',
            $transaction->approved_at?->format('d.m.Y H:i') ?? '-',
        ])->all();

        return AdminExportResponder::download(
            $format,
            'finansal_islemler_' . now()->format('Ymd_His'),
            'Finansal Islemler',
            $headings,
            $rows,
        );
    }

    /**
     * List my coordinator financial transactions.
     *
     * Coordinator endpoint exposed under `/coordinator/financials`. Requires `financial.view`; results are limited to transactions submitted by the authenticated user and to project ids available through `financial.view` scope. Returns paginated records, category totals, and the current page total amount.
     *
     * @group Financials
     * @authenticated
     *
     * @queryParam status string Optional status filter. Example: pending
     * @queryParam period_id integer Optional period filter. Example: 3
     * @queryParam date_from date Optional submitted date lower bound. Example: 2026-01-01
     * @queryParam date_to date Optional submitted date upper bound. Example: 2026-01-31
     * @response 200 {"transactions":{"data":[{"id":1,"status":"pending","amount":"1250.00"}]},"category_stats":[],"total_amount":"1250.00"}
     * @response 403 {"message":"This action is unauthorized."}
     */
    public function myFinancials(Request $request)
    {
        $this->abortUnlessAllowed($request, 'financial.view');
        $user = Auth::user();

        $projectIds = $this->permissionResolver->projectIdsForPermission($user, 'financial.view');

        $query = FinancialTransaction::with([
            'project:id,name',
            'period:id,name',
            'approver:id,name,surname',
        ])->whereIn('project_id', $projectIds)->where('submitted_by', $user->id);

        if ($request->filled('status')) $query->where('status', $request->status);
        if ($request->filled('period_id')) $query->where('period_id', $request->period_id);
        if ($request->filled('date_from')) $query->where('submitted_at', '>=', $request->date_from);
        if ($request->filled('date_to')) $query->where('submitted_at', '<=', $request->date_to . ' 23:59:59');

        $transactions = $query->latest('submitted_at')->paginate(20);

        $categoryStats = FinancialTransaction::selectRaw('category, SUM(amount) as total, COUNT(*) as count')
            ->whereIn('project_id', $projectIds)
            ->groupBy('category')
            ->get();

        return response()->json([
            'transactions'  => $transactions,
            'category_stats' => $categoryStats,
            'total_amount'  => $transactions->sum('amount'),
        ]);
    }

    /**
     * Export my coordinator financial transactions.
     *
     * Coordinator endpoint exposed under `/coordinator/financials/export`. Requires `financial.export`; results are limited to transactions submitted by the authenticated user and to project ids available through `financial.export` scope. Supports the shared `csv`, `xlsx`, or `pdf` export responder when enabled.
     *
     * @group Financials
     * @authenticated
     *
     * @queryParam project_id integer Optional project filter inside coordinator export scope. Example: 1
     * @queryParam period_id integer Optional period filter. Example: 3
     * @queryParam status string Optional status filter. Example: approved
     * @queryParam category string Optional category filter. Example: travel
     * @queryParam type string Optional transaction type: expense or payment. Example: expense
     * @queryParam payee string Optional payee, spending unit, invoice no, or accounting code search. Example: ABC
     * @queryParam date_from date Optional submitted date lower bound. Example: 2026-01-01
     * @queryParam date_to date Optional submitted date upper bound. Example: 2026-01-31
     * @queryParam format string Optional export format. Example: csv
     * @response 200 {"download":"Export file stream"}
     * @response 403 {"message":"This action is unauthorized."}
     */
    public function exportMyFinancials(Request $request)
    {
        $this->abortUnlessAllowed($request, 'financial.export');
        $user = Auth::user();
        $projectIds = $this->permissionResolver->projectIdsForPermission($user, 'financial.export');

        $query = FinancialTransaction::with([
            'project:id,name',
            'period:id,name',
            'approver:id,name,surname',
        ])->whereIn('project_id', $projectIds)->where('submitted_by', $user->id);

        $this->applyFinancialFilters($query, $request);

        $transactions = $query->latest('submitted_at')->get();
        $headings = ['ID', 'Proje', 'Birim', 'Donem', 'Kategori', 'Alici', 'Fatura No', 'Tutar', 'Durum', 'Odeme Tarihi', 'Odeme Yontemi', 'Muhasebe Kodu', 'Onaylayan', 'Gonderim Tarihi'];
        $rows = $transactions->map(fn (FinancialTransaction $transaction) => [
            $transaction->id,
            $transaction->project?->name ?? '-',
            $transaction->spending_unit ?? '-',
            $transaction->period?->name ?? '-',
            $transaction->category,
            $transaction->payee_name,
            $transaction->invoice_no ?? '-',
            number_format((float) $transaction->amount, 2, '.', ''),
            $transaction->status,
            $transaction->payment_date?->format('d.m.Y') ?? '-',
            $transaction->payment_method ?? '-',
            $transaction->accounting_code ?? '-',
            $transaction->approver ? $transaction->approver->name . ' ' . $transaction->approver->surname : '-',
            $transaction->submitted_at?->format('d.m.Y H:i') ?? '-',
        ])->all();

        return AdminExportResponder::download(
            $request->string('format')->toString() ?: 'csv',
            'koordinator_finans_' . now()->format('Ymd_His'),
            'Koordinator Finans Islemleri',
            $headings,
            $rows,
        );
    }
}
