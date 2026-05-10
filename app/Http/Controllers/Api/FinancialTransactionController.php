<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesGranularPermissions;
use App\Http\Controllers\Controller;
use App\Models\FinancialTransaction;
use App\Models\Project;
use App\Support\AdminExportResponder;
use App\Support\MediaStorage;
use App\Services\PermissionResolver;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class FinancialTransactionController extends Controller
{
    use AuthorizesGranularPermissions;

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

    private function applyFinancialFilters($query, Request $request): void
    {
        if ($request->filled('project_id')) {
            $query->where('project_id', $request->project_id);
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
            $query->where('payee_name', 'like', '%' . $request->payee . '%');
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
            'payee_name' => $transaction->payee_name,
            'amount' => (float) $transaction->amount,
            'status_before' => $statusBefore,
            'status_after' => $transaction->status,
            'submitted_by' => $transaction->submitted_by,
            'approved_by' => $transaction->approved_by,
            'invoice_present' => ! empty($transaction->invoice_path),
        ]);
    }

    /**
     * GET /admin/financials
     * Tüm finansal işlemleri listele (filtrelenebilir).
     */
    public function index(Request $request)
    {
        $this->abortUnlessAllowed($request, 'financial.view');
        $user = $request->user();
        $query = FinancialTransaction::with([
            'project:id,name',
            'period:id,name',
            'submitter:id,name,surname',
            'approver:id,name,surname',
        ]);
        $this->scopeFinancialTransactionsForUser($query, $user, 'financial.view');

        $this->applyFinancialFilters($query, $request);

        $transactions = $query->latest('submitted_at')->paginate(20);

        // Toplam tutar hesaplama
        $totalQuery = FinancialTransaction::query();
        $this->scopeFinancialTransactionsForUser($totalQuery, $user, 'financial.view');
        $this->applyFinancialFilters($totalQuery, $request);
        $totalAmount = $totalQuery->sum('amount');

        // Kategori bazlı infografik
        $categoryStats = FinancialTransaction::query()
            ->tap(fn ($q) => $this->scopeFinancialTransactionsForUser($q, $user, 'financial.view'))
            ->selectRaw('category, SUM(amount) as total, COUNT(*) as count')
            ->tap(fn ($q) => $this->applyFinancialFilters($q, $request))
            ->groupBy('category')
            ->get();

        // Proje bazlı harcama
        $projectStats = FinancialTransaction::query()
            ->tap(fn ($q) => $this->scopeFinancialTransactionsForUser($q, $user, 'financial.view'))
            ->with('project:id,name')
            ->selectRaw('project_id, SUM(amount) as total')
            ->tap(fn ($q) => $this->applyFinancialFilters($q, $request))
            ->groupBy('project_id')
            ->get();

        $statusStats = FinancialTransaction::query()
            ->tap(fn ($q) => $this->scopeFinancialTransactionsForUser($q, $user, 'financial.view'))
            ->selectRaw('status, SUM(amount) as total, COUNT(*) as count')
            ->tap(fn ($q) => $this->applyFinancialFilters($q, $request))
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
     * POST /admin/financials  (Koordinatör yükler)
     * POST /coordinator/financials
     */
    public function store(Request $request)
    {
        $this->abortUnlessAllowed($request, 'financial.create');
        $validated = $request->validate([
            'project_id'  => 'nullable|exists:projects,id',
            'period_id'   => 'nullable|exists:periods,id',
            'type'        => 'required|in:expense,payment',
            'category'    => 'required|in:transport,food,print,education,other',
            'payee_name'  => 'required|string|max:255',
            'amount'      => 'required|numeric|min:0.01',
            'invoice'     => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);

        if (!empty($validated['project_id'])) {
            $this->abortUnlessProjectAllowed($request, 'financial.create', (int) $validated['project_id']);
        } elseif (! $this->permissionResolver->hasGlobalScope($request->user(), 'financial.create')) {
            abort(403, 'Projesiz mali islem icin global kapsam gerekir.');
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
            'payee_name'   => $validated['payee_name'],
            'amount'       => $validated['amount'],
            'status'       => 'pending',
            'invoice_path' => $invoicePath,
            'submitted_by' => Auth::id(),
            'submitted_at' => now(),
        ]);

        $this->attachFinancialAudit($request, $transaction, 'created');

        return response()->json([
            'message'     => 'İşlem başarıyla kaydedildi.',
            'transaction' => $transaction->load(['project:id,name', 'submitter:id,name,surname']),
        ], 201);
    }

    /**
     * GET /admin/financials/{id}
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
     * PUT /admin/financials/{id}/approve  (Üst Admin onaylar)
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
     * PUT /admin/financials/{id}/reject  (Üst Admin reddeder)
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
     * PUT /admin/financials/{id}/pay  (Ödendi olarak işaretle)
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

        if ($transaction->status !== 'approved') {
            return response()->json(['message' => 'Sadece onaylanan işlemler ödenmiş olarak işaretlenebilir.'], 422);
        }

        $statusBefore = $transaction->status;
        $transaction->update(['status' => 'paid']);
        $transaction = $transaction->fresh();
        $this->attachFinancialAudit($request, $transaction, 'paid', $statusBefore);

        return response()->json(['message' => 'Ödeme tamamlandı.', 'transaction' => $transaction]);
    }

    /**
     * DELETE /admin/financials/{id}
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
     * GET /admin/financials/{id}/invoice  (Fatura PDF indir)
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
     * GET /admin/financials/export  (CSV/Excel çıktı)
     */
    public function export(Request $request)
    {
        $this->abortUnlessAllowed($request, 'financial.export');
        $query = FinancialTransaction::with([
            'project:id,name',
            'period:id,name',
            'submitter:id,name,surname',
            'approver:id,name,surname',
        ]);
        $this->scopeFinancialTransactionsForUser($query, $request->user(), 'financial.export');

        $this->applyFinancialFilters($query, $request);

        $transactions = $query->latest('submitted_at')->get();

        $format = $request->get('format', 'csv');
        $headings = ['ID', 'Proje', 'Donem', 'Tur', 'Kategori', 'Odeme Yapilacak Kisi/Firma', 'Tutar', 'Durum', 'Gonderen', 'Onaylayan', 'Gonderim Tarihi', 'Onay Tarihi'];
        $rows = $transactions->map(fn (FinancialTransaction $transaction) => [
            $transaction->id,
            $transaction->project->name ?? '-',
            $transaction->period->name ?? '-',
            $transaction->type,
            $transaction->category,
            $transaction->payee_name,
            number_format((float) $transaction->amount, 2, '.', ''),
            $transaction->status,
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
     * GET /coordinator/financials  (Koordinatör kendi projelerini görür)
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
        $headings = ['ID', 'Proje', 'Donem', 'Kategori', 'Alici', 'Tutar', 'Durum', 'Onaylayan', 'Gonderim Tarihi'];
        $rows = $transactions->map(fn (FinancialTransaction $transaction) => [
            $transaction->id,
            $transaction->project?->name ?? '-',
            $transaction->period?->name ?? '-',
            $transaction->category,
            $transaction->payee_name,
            number_format((float) $transaction->amount, 2, '.', ''),
            $transaction->status,
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
