<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesGranularPermissions;
use App\Http\Controllers\Controller;
use App\Services\AdminChatbotService;
use App\Services\PermissionResolver;
use App\Support\AdminExportResponder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * @group Chatbot
 */
class AdminChatbotController extends Controller
{
    use AuthorizesGranularPermissions;

    public function __construct(
        private readonly PermissionResolver $permissionResolver
    ) {
    }

    /**
     * Query the panel data assistant.
     *
     * Panel/admin endpoint exposed under `/admin/chatbot/query` and `/panel/chatbot/query`. The caller must have global `all` scope for `chatbot.view` or `chatbot.manage`; having only the chatbot permission does not bypass data permissions. The service is rule-based, never executes raw SQL from user input, and each intent checks its own action+scope and project visibility before reading data.
     *
     * Supported examples include participant/application/program/financial/project comparison summaries, trainers, logs, users, staff, support tickets, workflow requests, announcements, periods, volunteer opportunities, digital bohca, assignments, certificates, project special modules, and system guidance about action+scope, project-specific modules, or CV/PDF behavior.
     *
     * Responses may include `table.columns`, `table.rows`, `stats`, and an `export_token`. Export tokens are cached for the requesting user for 15 minutes and can be downloaded from the export endpoint as CSV, XLSX/Excel, PDF, DOCX/Word.
     *
     * @bodyParam message string required Natural-language command or question. Max 2000 characters. Example: Diplomasi360 katilimci listesi son 30 gun limit 50
     *
     * @response 200 {
     *   "reply": "Diplomasi360 katilimci listesi hazirlandi.",
     *   "intent": "participant_list",
     *   "table": {"columns": ["ID", "Ad Soyad"], "rows": [["42", "Ada Yilmaz"]]},
     *   "stats": null,
     *   "export_token": "V5T4uQ7nYp8bC1dE2fG3hI4jK5lM6nO7pQ8rS9tU0vW1xY2z",
     *   "export_available": true
     * }
     * @response 200 {"reply":"Bu veri icin gerekli yetki bulunmuyor: projects.participants.view.","intent":"permission_denied","table":null,"stats":null,"export_token":null,"export_available":false}
     * @response 403 {"message":"Veri asistanı için tüm sistem kapsamı gerekir."}
     * @response 422 {"message":"The message field is required.","errors":{"message":["The message field is required."]}}
     */
    public function query(Request $request, AdminChatbotService $chatbot): \Illuminate\Http\JsonResponse
    {
        $this->abortUnlessGlobalChatbotPermission($request);

        $validated = $request->validate([
            'message' => 'required|string|max:2000',
        ]);

        $payload = $chatbot->handle($request->user(), $validated['message']);

        return response()->json($payload);
    }

    /**
     * Download a chatbot table export.
     *
     * Panel/admin endpoint exposed under `/admin/chatbot/export/{token}` and `/panel/chatbot/export/{token}`. Requires global `all` scope for `chatbot.view` or `chatbot.manage`, validates the token shape, then confirms the cached export belongs to the authenticated user.
     *
     * The token is generated only by successful chatbot answers with table data, is cached for 15 minutes, and is consumed by this endpoint through `AdminExportResponder`. Supported formats are `csv`, `xlsx`, `excel`, `pdf`, `docx`, and `word`; omitted format defaults to CSV.
     *
     * @urlParam token string required 40-64 character export token returned by the query endpoint. Example: V5T4uQ7nYp8bC1dE2fG3hI4jK5lM6nO7pQ8rS9tU0vW1xY2z
     * @queryParam format string Export format: csv, xlsx, excel, pdf, docx, or word. Example: xlsx
     *
     * @response 200 scenario="csv" "ID,Ad Soyad\n42,Ada Yilmaz"
     * @response 400 "Geçersiz dışa aktarma isteği."
     * @response 403 "Yetkisiz."
     * @response 410 "Dışa aktarma süresi dolmuş veya geçersiz."
     * @response 422 {"message":"The selected format is invalid.","errors":{"format":["The selected format is invalid."]}}
     */
    public function export(Request $request, AdminChatbotService $chatbot, string $token): Response|BinaryFileResponse
    {
        $this->abortUnlessGlobalChatbotPermission($request);

        if (! preg_match('/^[a-zA-Z0-9]{40,64}$/', $token)) {
            return response('Geçersiz dışa aktarma isteği.', 400);
        }

        $payload = $chatbot->takeExportPayload($token);
        if ($payload === null) {
            return response('Dışa aktarma süresi dolmuş veya geçersiz.', 410);
        }

        if ((int) ($payload['user_id'] ?? 0) !== (int) $request->user()->id) {
            return response('Yetkisiz.', 403);
        }

        $validated = $request->validate([
            'format' => 'nullable|string|in:csv,xlsx,excel,pdf,docx,word',
        ]);

        $headings = $payload['headings'] ?? [];
        $rows = $payload['rows'] ?? [];
        $filename = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) ($payload['filename'] ?? 'export'));

        return AdminExportResponder::download(
            $validated['format'] ?? 'csv',
            $filename,
            'Veri Asistanı Çıktısı',
            $headings,
            $rows,
        );
    }

    private function abortUnlessGlobalChatbotPermission(Request $request): void
    {
        $user = $request->user();

        abort_unless(
            $this->permissionResolver->hasGlobalScope($user, 'chatbot.manage')
                || $this->permissionResolver->hasGlobalScope($user, 'chatbot.view'),
            403,
            'Veri asistanı için tüm sistem kapsamı gerekir.'
        );
    }
}
