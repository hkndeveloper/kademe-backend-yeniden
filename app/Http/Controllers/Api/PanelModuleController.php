<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\PanelModuleCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PanelModuleController extends Controller
{
    public function __construct(private readonly PanelModuleCatalog $panelModuleCatalog)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->panelModuleCatalog->visibleFor($request->user()));
    }
}
