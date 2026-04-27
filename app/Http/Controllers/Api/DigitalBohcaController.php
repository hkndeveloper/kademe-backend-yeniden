<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DigitalBohca;
use App\Models\Participant;
use Illuminate\Http\Request;

class DigitalBohcaController extends Controller
{
    /**
     * Öğrencinin katıldığı projelere ait Dijital Bohça materyallerini listeler
     */
    public function index(Request $request)
    {
        $user = $request->user();

        // Aktif projelerinin ID'lerini bul
        $projectIds = Participant::where('user_id', $user->id)
            ->where('status', 'active')
            ->pluck('project_id');

        // Bu projelere ait, öğrenciye görünür olan dosyalar (veya genele açık olanlar)
        $materials = DigitalBohca::where(function($query) use ($projectIds, $user) {
                // Sadece belli bir projeye atananlar
                $query->whereIn('project_id', $projectIds)
                      // Veya sadece bu öğrenciye özel yüklenenler
                      ->orWhere('user_id', $user->id)
                      // Veya herkese açık genel dosyalar
                      ->orWhereNull('project_id');
            })
            ->where('visible_to_student', true)
            ->with('uploader:id,name,surname,role')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'materials' => $materials
        ]);
    }
}
