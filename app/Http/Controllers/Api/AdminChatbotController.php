<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesGranularPermissions;
use App\Http\Controllers\Controller;
use App\Services\AdminChatbotService;
use App\Services\PermissionResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminChatbotController extends Controller
{
    use AuthorizesGranularPermissions;

    public function __construct(
        private readonly PermissionResolver $permissionResolver
    ) {
    }

    public function query(Request $request, AdminChatbotService $chatbot): \Illuminate\Http\JsonResponse
    {
        $this->abortUnlessAnyPermission($request, ['chatbot.manage', 'chatbot.view']);

        $validated = $request->validate([
            'message' => 'required|string|max:2000',
        ]);

        $payload = $chatbot->handle($request->user(), $validated['message']);

        return response()->json($payload);
    }

    public function export(Request $request, AdminChatbotService $chatbot, string $token): StreamedResponse|Response
    {
        $this->abortUnlessAnyPermission($request, ['chatbot.manage', 'chatbot.view']);

        if (! preg_match('/^[a-zA-Z0-9]{40,64}$/', $token)) {
            return response('Gecersiz disa aktarma istegi.', 400);
        }

        $payload = $chatbot->takeExportPayload($token);
        if ($payload === null) {
            return response('Disa aktarma suresi dolmus veya gecersiz.', 410);
        }

        if ((int) ($payload['user_id'] ?? 0) !== (int) $request->user()->id) {
            return response('Yetkisiz.', 403);
        }

        $headings = $payload['headings'] ?? [];
        $rows = $payload['rows'] ?? [];
        $filename = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) ($payload['filename'] ?? 'export')) . '.csv';

        return response()->streamDownload(function () use ($headings, $rows) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
            if ($headings !== []) {
                fputcsv($out, $headings, ';');
            }
            foreach ($rows as $row) {
                fputcsv($out, $row, ';');
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
