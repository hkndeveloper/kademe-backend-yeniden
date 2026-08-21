<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesGranularPermissions;
use App\Http\Controllers\Concerns\ResolvesProjectPeriodContext;
use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\CreditLog;
use App\Models\Feedback;
use App\Models\Participant;
use App\Models\Program;
use App\Models\ProgramPhoto;
use App\Services\CreditService;
use App\Services\PermissionResolver;
use App\Services\PeriodLifecycleService;
use App\Support\AdminExportResponder;
use App\Support\FeedbackFormResolver;
use App\Support\MediaStorage;
use App\Support\IstanbulDateTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * @group Programs & Attendance
 */
class AdminProgramController extends Controller
{
    use AuthorizesGranularPermissions;
    use ResolvesProjectPeriodContext;

    private const PUBLIC_HOMEPAGE_CACHE_KEY = 'public.homepage.v1';

    public function __construct(private readonly PermissionResolver $permissionResolver, private readonly CreditService $creditService) {}

    private function clearPublicHomepageCache(): void
    {
        Cache::forget(self::PUBLIC_HOMEPAGE_CACHE_KEY);
    }

    /**
     * List panel programs.
     *
     * Requires permission: `programs.view`. Project and period filters are resolved through action+scope; global users see all programs, scoped users only see programs in permitted projects. Exposed under both `/api/admin/programs` and `/api/panel/programs` aliases.
     *
     * @authenticated
     * @queryParam project_id integer Optional project filter. User must be allowed for `programs.view`. Example: 1
     * @queryParam period_id integer Optional period filter. Must belong to the selected/allowed project. Example: 3
     * @response 200 {"programs":[{"id":8,"title":"Haftalik Atolye","project_id":1,"status":"scheduled","attendance_count":12,"feedback_count":4}]}
     * @response 403 {"message":"Bu proje icin yetkiniz yok."}
     */
    public function index(Request $request): JsonResponse
    {
        $v=$request->validate(['project_id'=>'nullable|integer|exists:projects,id','period_id'=>'nullable|integer|exists:periods,id']);
        $ctx=$this->resolveProjectPeriodContext($request,'programs.view',!empty($v['project_id'])?(int)$v['project_id']:null,!empty($v['period_id'])?(int)$v['period_id']:null);
        $q=Program::query()->with(['project:id,name','period:id,name,status'])->withCount(['attendances','feedbacks'])->orderByDesc('start_at');
        $this->applyProjectPeriodContext($q,$ctx);
        return response()->json(['programs'=>$q->get()->map(fn(Program $p)=>$this->programPayload($p))->values()]);
    }

    /**
     * Get one panel program.
     *
     * Requires permission: `programs.view` for the program project. The project scope is
     * checked against the program itself so a guessed ID cannot bypass the action scope.
     *
     * @authenticated
     * @urlParam id integer required Program ID. Example: 8
     * @response 200 {"program":{"id":8,"title":"Haftalik Atolye","status":"scheduled"}}
     * @response 403 {"message":"Bu proje icin yetkiniz bulunmuyor."}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'programs.view');
        $program = Program::query()
            ->with(['project:id,name', 'period:id,name,status'])
            ->withCount(['attendances', 'feedbacks'])
            ->findOrFail($id);
        $this->abortUnlessProjectAllowed($request, 'programs.view', (int) $program->project_id);

        return response()->json(['program' => $this->programPayload($program)]);
    }

    /**
     * Return the minimum scoped context needed by the QR attendance screen.
     */
    public function qrContext(Request $request, int $id): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'programs.qr.manage');
        $program = Program::query()
            ->with(['period:id,name,status'])
            ->findOrFail($id);
        $this->abortUnlessProjectAllowed($request, 'programs.qr.manage', (int) $program->project_id);

        return response()->json([
            'program' => [
                'id' => (int) $program->id,
                'title' => $program->title,
                'period' => $program->period ? [
                    'id' => (int) $program->period->id,
                    'name' => $program->period->name,
                    'status' => $program->period->status,
                    'lifecycle' => [
                        'is_archive_mode' => PeriodLifecycleService::isArchiveStatus($program->period->status),
                        'write_capabilities' => PeriodLifecycleService::writeCapabilitiesForStatus($program->period->status),
                    ],
                ] : null,
            ],
        ]);
    }

    /**
     * Export panel programs.
     *
     * Requires permission: `programs.export`. Project/period context is resolved through action+scope. Returns a binary CSV/XLSX/PDF/DOCX file depending on `format`. Exposed under `/api/admin/programs/export` and `/api/panel/programs/export`.
     *
     * @authenticated
     * @queryParam project_id integer Optional project filter. Example: 1
     * @queryParam period_id integer Optional period filter. Example: 3
     * @queryParam format string Optional export format: `csv`, `xlsx`, `pdf`, `docx`, `excel` or `word`. Defaults to csv. Example: xlsx
     * @response 200 binary Programs export file.
     * @response 403 {"message":"Bu proje icin yetkiniz yok."}
     */
    public function export(Request $request)
    {
        $this->abortUnlessAllowed($request,'programs.export');
        $v=$request->validate(['project_id'=>'nullable|integer|exists:projects,id','period_id'=>'nullable|integer|exists:periods,id']);
        $ctx=$this->resolveProjectPeriodContext($request,'programs.export',!empty($v['project_id'])?(int)$v['project_id']:null,!empty($v['period_id'])?(int)$v['period_id']:null);
        $q=Program::query()->with(['project:id,name','period:id,name,status'])->orderByDesc('start_at');
        $this->applyProjectPeriodContext($q,$ctx);
        $rows=$q->get()->map(fn(Program $p)=>[$p->id,$p->project?->name??'-',$p->period?->name??'-',$p->title,$p->location??'-',IstanbulDateTime::format($p->start_at),IstanbulDateTime::format($p->end_at),$p->status,$p->credit_deduction??0])->all();
        return AdminExportResponder::download($request->string('format')->toString()?:'csv','programlar_'.now()->format('Ymd_His'),'Programlar',['ID','Proje','Donem','Program','Yer','Baslangic','Bitis','Durum','Kredi Kesintisi'],$rows);
    }

    /**
     * Create a panel program.
     *
     * Requires permission: `programs.create` for the selected project. Completed periods are locked unless the user also has archive update permission. Program time ranges cannot overlap another non-cancelled program.
     *
     * @authenticated
     * @bodyParam project_id integer required Project ID. Example: 1
     * @bodyParam period_id integer required Period ID. Example: 3
     * @bodyParam title string required Program title. Example: Haftalik Atolye
     * @bodyParam description string Optional description. Example: Uygulama calismasi.
     * @bodyParam location string Optional location text. Example: Kademe Salon 1
     * @bodyParam location_place_name string Optional selected map place name. Example: Kademe Genel Merkez
     * @bodyParam location_place_address string Optional selected map place address. Example: Bosna Hersek Mahallesi, Istanbul
     * @bodyParam location_place_id string Optional selected map provider place ID. Example: osm:node:123
     * @bodyParam location_place_provider string Optional selected map provider. Example: osm
     * @bodyParam latitude number Optional location latitude. Example: 41.0082
     * @bodyParam longitude number Optional location longitude. Example: 28.9784
     * @bodyParam radius_meters integer Optional QR geofence radius between 10 and 5000. Defaults to 100. Example: 150
     * @bodyParam guest_info object Optional guest/speaker metadata.
     * @bodyParam start_at date required Start datetime. Example: 2026-07-10 14:00:00
     * @bodyParam end_at date required End datetime, after or equal to start. Example: 2026-07-10 16:00:00
     * @bodyParam credit_deduction integer Optional credit deduction amount. Example: 10
     * @bodyParam application_quota integer Optional program-specific application quota. Example: 30
     * @bodyParam target_audience string[] Optional audience values: `student`, `alumni`. Example: ["student"]
     * @bodyParam feedback_form_template_id integer Optional feedback form template ID. Example: 2
     * @bodyParam status string Optional status: `scheduled`, `active`, `completed` or `cancelled`. Example: scheduled
     * @bodyParam is_public boolean Optional public site visibility. Example: true
     * @bodyParam is_featured boolean Optional featured flag. Example: false
     * @response 201 {"program":{"id":8,"title":"Haftalik Atolye","status":"scheduled"}}
     * @response 403 {"message":"Bu proje icin yetkiniz bulunmuyor."}
     * @response 422 {"message":"The given data was invalid."}
     * @response 423 {"message":"Tamamlanmis donem arsiv modundadir. Degisiklik icin arsiv duzeltme yetkisi gerekir."}
     */
    public function store(Request $request): JsonResponse
    {
        $v=$this->validatedProgramData($request,true);
        $this->abortUnlessProjectAllowed($request,'programs.create',(int)$v['project_id']);
        $this->assertPeriodWritable($request,(int)$v['period_id']);
        $this->assertNoOverlap($v['start_at'],$v['end_at']??null,null,$v['status']??'scheduled');
        $program=Program::query()->create($v+['created_by'=>$request->user()->id]);
        $this->clearPublicHomepageCache();
        return response()->json(['program'=>$this->programPayload($program->load(['project:id,name','period:id,name,status']))],201);
    }

    /**
     * Update a panel program.
     *
     * Requires permission: `programs.update` for the program project. If `period_id` changes, completed-period archive lock is checked against the target period. Time ranges cannot overlap another non-cancelled program.
     *
     * @authenticated
     * @urlParam id integer required Program ID. Example: 8
     * @bodyParam project_id integer Optional project ID. Example: 1
     * @bodyParam period_id integer Optional period ID. Example: 3
     * @bodyParam title string Optional program title. Example: Guncellenmis Atolye
     * @bodyParam start_at date Optional start datetime. Example: 2026-07-10 14:30:00
     * @bodyParam end_at date Optional end datetime. Example: 2026-07-10 16:30:00
     * @bodyParam status string Optional status: `scheduled`, `active`, `completed` or `cancelled`. Example: active
     * @response 200 {"program":{"id":8,"title":"Guncellenmis Atolye","status":"active"}}
     * @response 403 {"message":"Bu proje icin yetkiniz bulunmuyor."}
     * @response 422 {"message":"The given data was invalid."}
     */
    public function update(Request $request,int $id): JsonResponse
    {
        $program=Program::query()->with(['project:id,name','period:id,name,status'])->findOrFail($id);
        $this->abortUnlessProjectAllowed($request,'programs.update',(int)$program->project_id);
        $v=$this->validatedProgramData($request,false,$program);
        $periodId=array_key_exists('period_id',$v)?(int)$v['period_id']:(int)$program->period_id;
        if (isset($v['status']) && in_array($v['status'], ['completed', 'cancelled'], true)) {
            $this->assertPeriodResolvable($request, $periodId);
        } else {
            $this->assertPeriodWritable($request, $periodId);
        }
        $this->assertNoOverlap($v['start_at']??$program->start_at,$v['end_at']??$program->end_at,$program->id,$v['status']??$program->status);
        $program->update($v);
        $this->clearPublicHomepageCache();
        return response()->json(['program'=>$this->programPayload($program->fresh(['project:id,name','period:id,name,status']))]);
    }

    /**
     * Generate or rotate a program QR token.
     *
     * Requires permission: `programs.qr.manage` for the program project. The program attendance window must be open; scheduled programs are switched to active when QR is generated. Completed periods require archive update permission.
     *
     * @authenticated
     * @urlParam id integer required Program ID. Example: 8
     * @bodyParam rotation_seconds integer Optional QR rotation window between 15 and 300 seconds. Defaults to program setting or 30. Example: 30
     * @response 200 {"qr_token":"prg_8_xxxxx","expires_at":"2026-06-30T14:00:30+03:00","refresh_in_seconds":30}
     * @response 403 {"message":"Bu proje icin yetkiniz bulunmuyor."}
     * @response 422 {"message":"The given data was invalid.","errors":{"start_at":["QR yoklama sadece program saat araliginda baslatilabilir."]}}
     */
    public function generateQr(Request $request,int $id): JsonResponse
    {
        $this->abortUnlessAllowed($request,'programs.qr.manage');
        $v=$request->validate(['rotation_seconds'=>['nullable','integer','min:15','max:300']]);
        $program=Program::query()->with(['project:id,name','period:id,name,status'])->findOrFail($id);
        $this->abortUnlessProjectAllowed($request,'programs.qr.manage',(int)$program->project_id);
        $this->assertPeriodResolvable($request,$program->period_id);
        if(!$program->isAttendanceWindowOpen()) throw ValidationException::withMessages(['start_at'=>['QR yoklama sadece program saat araliginda baslatilabilir.']])->status(422);
        $rotation=(int)($v['rotation_seconds']??$program->qr_rotation_seconds??30);
        $token='prg_'.$program->id.'_'.Str::random(48);
        $expiresAt=now()->addSeconds($rotation);
        $program->update(['qr_token'=>$token,'qr_expires_at'=>$expiresAt,'qr_rotation_seconds'=>$rotation,'status'=>$program->status==='scheduled'?'active':$program->status]);
        return response()->json(['qr_token'=>$token,'expires_at'=>$expiresAt->toIso8601String(),'refresh_in_seconds'=>$rotation]);
    }

    /**
     * Complete a program and reconcile credits.
     *
     * Requires permission: `programs.complete` for the program project. Marks the program completed and deducts credit once for active participants according to attendance/credit rules. Completed periods require archive update permission.
     *
     * @authenticated
     * @urlParam id integer required Program ID. Example: 8
     * @response 200 {"message":"Program tamamlandi.","program":{"id":8,"status":"completed"},"deducted_participant_count":10}
     * @response 403 {"message":"Bu proje icin yetkiniz bulunmuyor."}
     * @response 423 {"message":"Tamamlanmis donem arsiv modundadir. Degisiklik icin arsiv duzeltme yetkisi gerekir."}
     */
    public function complete(Request $request,int $id): JsonResponse
    {
        $this->abortUnlessAllowed($request,'programs.complete');
        $program=Program::query()->with(['project:id,name','period:id,name,status'])->findOrFail($id);
        $this->abortUnlessProjectAllowed($request,'programs.complete',(int)$program->project_id);
        $this->assertPeriodResolvable($request,$program->period_id);
        $participants=$this->programParticipantsQuery($program)->where('status','active')->get();
        $validUserIds=Attendance::query()->where('program_id',$program->id)->where('is_valid',true)->pluck('user_id')->map(fn($id)=>(int)$id)->all();
        $deducted=0;
        DB::transaction(function()use($program,$participants,$validUserIds,$request,&$deducted){
            foreach($participants as $participant){
                if(!$this->participantHasCreditImpact($participant)) continue;
                $log=$this->creditService->deductOnceForProgram($participant,$program,$request->user()->id,in_array((int)$participant->user_id,$validUserIds,true)?'Etkinlik yoklamasi alindi, degerlendirme bekleniyor':'Etkinlik tamamlandi, katilim kaydi bulunamadi');
                if($log) $deducted++;
            }
            $program->update(['status'=>'completed']);
        });
        $request->attributes->set('audit.subject', $program);
        $request->attributes->set('audit.event', 'program.completed');
        $request->attributes->set('audit.description', 'program.completed');
        $request->attributes->set('audit.properties', ['operation'=>'program_complete','project_id'=>$program->project_id,'period_id'=>$program->period_id,'program_id'=>$program->id,'program_title'=>$program->title,'deducted_participant_count'=>$deducted]);
        return response()->json(['message'=>'Program tamamlandi.','program'=>$this->programPayload($program->fresh(['project:id,name','period:id,name,status'])),'deducted_participant_count'=>$deducted]);
    }
    /**
     * List program attendance details.
     *
     * Requires permission: `programs.attendance.view` for the program project. Returns participant attendance status, feedback state and credit impact summary for the selected program.
     *
     * @authenticated
     * @urlParam id integer required Program ID. Example: 8
     * @response 200 {"program":{"id":8,"title":"Haftalik Atolye"},"summary":{"attendance_count":12,"participant_count":20,"absent_count":8,"feedback_count":4},"records":[]}
     * @response 403 {"message":"Bu proje icin yetkiniz bulunmuyor."}
     */
    public function attendanceDetails(Request $request,int $id): JsonResponse
    {
        $this->abortUnlessAllowed($request,'programs.attendance.view');
        $program=Program::query()->with(['project:id,name','period:id,name,status'])->findOrFail($id);
        $this->abortUnlessProjectAllowed($request,'programs.attendance.view',(int)$program->project_id);
        $records=$this->attendanceRecords($program);
        $present=$records->filter(fn($r)=>(bool)$r['is_valid']&&$r['recorded_at'])->count();
        return response()->json(['program'=>['id'=>$program->id,'title'=>$program->title,'project'=>$program->project?->name,'period'=>$program->period?->name],'summary'=>[
            'attendance_count'=>$present,
            'participant_count'=>$records->count(),
            'absent_count'=>max($records->count()-$present,0),
            'feedback_count'=>Feedback::query()->where('program_id',$program->id)->count(),
            'deduction_count'=>CreditLog::query()->where('program_id',$program->id)->where('type','deduction')->count(),
            'restore_count'=>CreditLog::query()->where('program_id',$program->id)->where(fn($q)=>$q->where('type','restore')->orWhere('amount','>',0))->count(),
        ],'records'=>$records]);
    }

    /**
     * Mark participant attendance manually.
     *
     * Requires permission: `programs.attendance.manage` for the program project. Completed periods require archive update permission. For completed programs, credit deduction/restore is reconciled for the participant.
     *
     * @authenticated
     * @urlParam id integer required Program ID. Example: 8
     * @urlParam participantId integer required Participant ID in the program project/period. Example: 42
     * @bodyParam is_valid boolean required Whether the participant attended. Example: true
     * @bodyParam manual_note string Optional manual attendance note. Example: Imza listesiyle dogrulandi.
     * @response 200 {"message":"Yoklama katildi olarak isaretlendi.","attendance":{"program_id":8,"is_valid":true,"method":"manual"}}
     * @response 403 {"message":"Bu proje icin yetkiniz bulunmuyor."}
     * @response 422 {"message":"The given data was invalid."}
     */
    public function markManualAttendance(Request $request,int $id,int $participantId): JsonResponse
    {
        $this->abortUnlessAllowed($request,'programs.attendance.manage');
        $program=Program::query()->with(['project:id,name','period:id,name,status'])->findOrFail($id);
        $this->abortUnlessProjectAllowed($request,'programs.attendance.manage',(int)$program->project_id);
        $this->assertPeriodResolvable($request,$program->period_id);
        $v=$request->validate(['is_valid'=>['required','boolean'],'manual_note'=>['nullable','string','max:1000']]);
        $participant=$this->programParticipantsQuery($program)->where('id',$participantId)->with('user:id,role')->firstOrFail();
        $attendance=DB::transaction(function()use($program,$participant,$v,$request){
            $attendance=Attendance::query()->updateOrCreate(['program_id'=>$program->id,'user_id'=>$participant->user_id],[
                'method'=>'manual','is_valid'=>(bool)$v['is_valid'],'manual_note'=>$v['manual_note']??null,'recorded_by'=>$request->user()->id,'latitude'=>null,'longitude'=>null,
            ]);
            if($this->participantHasCreditImpact($participant)) $this->creditService->reconcileCompletedProgramAttendance($program,$participant,(bool)$v['is_valid'],$request->user()->id);
            return $attendance;
        });
        $request->attributes->set('audit.subject', $program);
        $request->attributes->set('audit.event', 'attendance.manual_updated');
        $request->attributes->set('audit.description', 'attendance.manual_updated');
        $request->attributes->set('audit.properties', ['operation'=>'manual_attendance_update','project_id'=>$program->project_id,'period_id'=>$program->period_id,'program_id'=>$program->id,'program_title'=>$program->title,'participant_id'=>$participant->id,'student_user_id'=>$participant->user_id,'attendance_id'=>$attendance->id,'is_valid'=>(bool)$v['is_valid'],'manual_note_present'=>!empty($v['manual_note']??null),'program_status'=>$program->status]);
        return response()->json(['message'=>$attendance->is_valid?'Yoklama katildi olarak isaretlendi.':'Yoklama gelmedi olarak isaretlendi.','attendance'=>$attendance]);
    }

    /**
     * Export program attendance details.
     *
     * Requires permission: `programs.attendance.export` for the program project. Returns a binary CSV/XLSX/PDF/DOCX file depending on `format`.
     *
     * @authenticated
     * @urlParam id integer required Program ID. Example: 8
     * @queryParam format string Optional export format: `csv`, `xlsx`, `pdf`, `docx`, `excel` or `word`. Defaults to csv. Example: pdf
     * @response 200 binary Program attendance export file.
     * @response 403 {"message":"Bu proje icin yetkiniz bulunmuyor."}
     */
    public function exportAttendanceDetails(Request $request,int $id)
    {
        $this->abortUnlessAllowed($request,'programs.attendance.export');
        $program=Program::query()->with(['project:id,name','period:id,name,status'])->findOrFail($id);
        $this->abortUnlessProjectAllowed($request,'programs.attendance.export',(int)$program->project_id);
        $rows=$this->attendanceRecords($program)->map(fn(array $r)=>[$r['id']??'-',$r['student'],$r['email']??'-',$r['attendance_status']??'-',$r['method']??'-',$r['feedback_submitted']?'evet':'hayir',$r['credit_deducted']?'evet':'hayir',$r['credit_restored']?'evet':'hayir',$r['recorded_at']??'-'])->all();
        return AdminExportResponder::download($request->string('format')->toString()?:'csv','program_'.$program->id.'_yoklama_'.now()->format('Ymd_His'),'Program Yoklama Detaylari',['Yoklama ID','Katilimci','E-posta','Durum','Yontem','Feedback','Kredi Kesildi','Kredi Iade','Kayit Zamani'],$rows);
    }

    /**
     * Get program feedback summary.
     *
     * Requires permission: `programs.view`. Project and period filters are resolved through action+scope. Returns aggregate feedback averages, question distributions, project/period breakdowns and recent anonymous comments.
     *
     * @authenticated
     * @queryParam project_id integer Optional project filter. Example: 1
     * @queryParam period_id integer Optional period filter. Example: 3
     * @response 200 {"summary":{"program_count":2,"total_feedback":12,"with_comment":5,"overall_average":4.4},"programs":[],"question_stats":[]}
     * @response 403 {"message":"Bu proje icin yetkiniz yok."}
     */
    public function feedbackSummary(Request $request): JsonResponse
    {
        $this->abortUnlessAllowed($request,'programs.view');
        return response()->json($this->buildFeedbackSummary($request));
    }

    /**
     * Export program feedback summary.
     *
     * Requires permission: `programs.view`. Uses the same project/period action+scope filters as the summary endpoint and returns a binary CSV/XLSX/PDF/DOCX file depending on `format`.
     *
     * @authenticated
     * @queryParam project_id integer Optional project filter. Example: 1
     * @queryParam period_id integer Optional period filter. Example: 3
     * @queryParam format string Optional export format: `csv`, `xlsx`, `pdf`, `docx`, `excel` or `word`. Defaults to csv. Example: xlsx
     * @response 200 binary Feedback summary export file.
     * @response 403 {"message":"Bu proje icin yetkiniz yok."}
     */
    public function exportFeedbackSummary(Request $request)
    {
        $this->abortUnlessAllowed($request,'programs.view');
        $summary=$this->buildFeedbackSummary($request);
        $rows=collect($summary['programs']??[])->map(fn(array $p)=>[$p['id'],$p['project']??'-',$p['period']??'-',$p['title'],$p['feedback_count'],$p['overall_average']??'-',$p['with_comment']])->all();
        return AdminExportResponder::download($request->string('format')->toString()?:'csv','program_degerlendirme_ozeti_'.now()->format('Ymd_His'),'Program Degerlendirme Ozeti',['Program ID','Proje','Donem','Program','Degerlendirme','Sayisal Ortalama','Yorumlu Degerlendirme'],$rows);
    }

    /**
     * Get detailed feedback stats for a program.
     *
     * Requires permission: `programs.view` for the program project. Feedback identities are intentionally anonymized; the response includes question definitions, rating/choice distributions, anonymous text responses and public report IDs.
     *
     * @authenticated
     * @urlParam id integer required Program ID. Example: 8
     * @response 200 {"program":{"id":8,"title":"Haftalik Atolye"},"summary":{"total_feedback":12,"anonymous":true,"identity_redacted":true},"questions":[],"question_stats":[],"responses":[]}
     * @response 403 {"message":"Bu proje icin yetkiniz bulunmuyor."}
     */
    public function feedbackStats(Request $request,int $id): JsonResponse
    {
        $this->abortUnlessAllowed($request,'programs.view');
        $program=Program::query()->with(['project:id,name','period:id,name,status'])->findOrFail($id);
        $this->abortUnlessProjectAllowed($request,'programs.view',(int)$program->project_id);
        $feedbacks=Feedback::query()->where('program_id',$program->id)->orderByDesc('submitted_at')->get();
        $questions=FeedbackFormResolver::forProgram($program);
        $ratingQuestions=FeedbackFormResolver::ratingQuestions($questions);
        $choiceQuestions=FeedbackFormResolver::choiceQuestions($questions);
        $commentQuestions=FeedbackFormResolver::commentQuestions($questions);
        $questionStats=[];
        foreach($ratingQuestions as $q){
            $key=$q['id'];
            $values=$feedbacks->map(fn(Feedback $f)=>$f->responses[$key]??null)->filter(fn($v)=>is_numeric($v))->map(fn($v)=>(float)$v)->values();
            $dist=[]; for($s=(int)($q['min']??1);$s<=(int)($q['max']??5);$s++) $dist[(string)$s]=$values->filter(fn($v)=>(float)$v===(float)$s)->count();
            $questionStats[$key]=['label'=>$q['label'],'type'=>'rating','count'=>$values->count(),'average'=>$values->count()>0?round($values->avg(),2):null,'min'=>$values->count()>0?$values->min():null,'max'=>$values->count()>0?$values->max():null,'distribution'=>$dist];
        }
        foreach($choiceQuestions as $q){
            $key=$q['id'];
            $values=$feedbacks->map(fn(Feedback $f)=>$f->responses[$key]??null)->filter(fn($v)=>$v!==null&&$v!=='')->values();
            $dist=collect($q['options']??[])->mapWithKeys(fn($o)=>[$o=>$values->filter(fn($v)=>(string)$v===(string)$o)->count()])->all();
            $questionStats[$key]=['label'=>$q['label'],'type'=>'choice','count'=>$values->count(),'distribution'=>$dist];
        }        $allRatings=$feedbacks->flatMap(fn(Feedback $f)=>collect($ratingQuestions)->map(fn($q)=>$f->responses[$q['id']]??null)->filter(fn($v)=>is_numeric($v))->map(fn($v)=>(float)$v));
        $responses=$feedbacks->map(function(Feedback $f)use($questions,$commentQuestions){
            $answers=collect($questions)->mapWithKeys(fn($q)=>[$q['id']=>$f->responses[$q['id']]??null])->all();
            $textAnswers=collect($commentQuestions)->map(function($q)use($f){$value=trim((string)($f->responses[$q['id']]??'')); return $value===''?null:['question_id'=>$q['id'],'question'=>$q['label'],'answer'=>$value];})->filter()->values()->all();
            $primary=$commentQuestions[0]['id']??'comment';
            return ['id'=>$f->id,'anonymous_report_id'=>$this->feedbackReportId($f),'is_anonymous'=>true,'identity_redacted'=>true,'answers'=>$answers,'text_answers'=>$textAnswers,'comment'=>$answers[$primary]??null,'submitted_at'=>optional($f->submitted_at)?->toIso8601String()]+$answers;
        })->values();
        $textResponses=$responses->flatMap(fn(array $r)=>collect($r['text_answers']??[])->map(fn(array $a)=>['anonymous_report_id'=>$r['anonymous_report_id'],'question_id'=>$a['question_id'],'question'=>$a['question'],'answer'=>$a['answer'],'submitted_at'=>$r['submitted_at']]))->values()->all();
        $withComment=$feedbacks->filter(fn(Feedback $f)=>collect($commentQuestions)->contains(fn($q)=>trim((string)($f->responses[$q['id']]??''))!==''))->count();
        $textCount=$feedbacks->sum(fn(Feedback $f)=>collect($commentQuestions)->filter(fn($q)=>trim((string)($f->responses[$q['id']]??''))!=='')->count());
        return response()->json(['program'=>['id'=>$program->id,'title'=>$program->title,'project'=>$program->project?->name,'period'=>$program->period?->name,'start_at'=>optional($program->start_at)?->toIso8601String(),'status'=>$program->status],'summary'=>['total_feedback'=>$feedbacks->count(),'with_comment'=>$withComment,'overall_average'=>$allRatings->count()>0?round($allRatings->avg(),2):null,'rating_question_count'=>count($ratingQuestions),'choice_question_count'=>count($choiceQuestions),'text_question_count'=>count($commentQuestions),'text_response_count'=>$textCount,'anonymous'=>true,'identity_redacted'=>true,'public_id_enabled'=>Feedback::usesPublicIdColumn()],'questions'=>$questions,'question_stats'=>$questionStats,'text_responses'=>$textResponses,'responses'=>$responses]);
    }

    /**
     * Export detailed feedback for a program.
     *
     * Requires permission: `programs.view` for the program project. Exported rows use anonymous report IDs instead of participant identity and return a binary CSV/XLSX/PDF/DOCX file depending on `format`.
     *
     * @authenticated
     * @urlParam id integer required Program ID. Example: 8
     * @queryParam format string Optional export format: `csv`, `xlsx`, `pdf`, `docx`, `excel` or `word`. Defaults to csv. Example: csv
     * @response 200 binary Program feedback export file.
     * @response 403 {"message":"Bu proje icin yetkiniz bulunmuyor."}
     */
    public function exportFeedback(Request $request,int $id)
    {
        $this->abortUnlessAllowed($request,'programs.view');
        $program=Program::query()->with(['project:id,name'])->findOrFail($id);
        $this->abortUnlessProjectAllowed($request,'programs.view',(int)$program->project_id);
        $feedbacks=Feedback::query()->where('program_id',$program->id)->orderByDesc('submitted_at')->get();
        $questions=FeedbackFormResolver::forProgram($program);
        $headings=array_merge(['Anonim Rapor ID'],array_map(fn($q)=>$q['label'],$questions),['Gonderim Zamani']);
        $rows=$feedbacks->map(fn(Feedback $f)=>array_merge([$this->feedbackReportId($f)],array_map(fn($q)=>$f->responses[$q['id']]??'-',$questions),[optional($f->submitted_at)?->format('d.m.Y H:i')??'-']))->all();
        return AdminExportResponder::download($request->string('format')->toString()?:'csv','program_'.$program->id.'_feedback_'.now()->format('Ymd_His'),'Program Degerlendirme Sonuclari',$headings,$rows);
    }

    /**
     * List program photos.
     *
     * Requires permission: `programs.view` for the program project. This media endpoint is exposed under the unified `/api/panel/programs/{id}/photos` route.
     *
     * @authenticated
     * @urlParam id integer required Program ID. Example: 8
     * @response 200 {"photos":[{"id":1,"program_id":8,"caption":"Sahne"}]}
     * @response 403 {"message":"Bu proje icin yetkiniz bulunmuyor."}
     */
    public function photos(Request $request,int $id): JsonResponse
    {
        $this->abortUnlessAllowed($request,'programs.view');
        $program=Program::query()->findOrFail($id);
        $this->abortUnlessProjectAllowed($request,'programs.view',(int)$program->project_id);
        return response()->json(['photos'=>$program->photos()->get()]);
    }

    /**
     * Upload a program photo.
     *
     * Requires permission: `programs.media.upload` for the program project. Send as `multipart/form-data`. Completed periods require archive update permission.
     *
     * @authenticated
     * @urlParam id integer required Program ID. Example: 8
     * @bodyParam photo file required Image file, maximum 5 MB.
     * @bodyParam caption string Optional photo caption. Example: Atolye baslangici
     * @response 201 {"photo":{"id":1,"program_id":8,"caption":"Atolye baslangici"}}
     * @response 403 {"message":"Bu proje icin yetkiniz bulunmuyor."}
     * @response 422 {"message":"The given data was invalid."}
     */
    public function uploadPhoto(Request $request,int $id): JsonResponse
    {
        $this->abortUnlessAllowed($request,'programs.media.upload');
        $program=Program::query()->findOrFail($id);
        $this->abortUnlessProjectAllowed($request,'programs.media.upload',(int)$program->project_id);
        $this->assertPeriodWritable($request,$program->period_id);
        $v=$request->validate(['photo'=>['required','image','max:5120'],'caption'=>['nullable','string','max:255']]);
        $path=MediaStorage::putFile('program-photos/'.$program->id,$v['photo']);
        $photo=ProgramPhoto::query()->create(['program_id'=>$program->id,'url'=>$path,'caption'=>$v['caption']??null,'sort_order'=>((int)ProgramPhoto::query()->where('program_id',$program->id)->max('sort_order'))+1,'created_by'=>$request->user()->id]);
        return response()->json(['photo'=>$photo],201);
    }

    /**
     * Reorder program photos.
     *
     * Requires permission: `programs.media.upload` for the program project. Completed periods require archive update permission.
     *
     * @authenticated
     * @urlParam id integer required Program ID. Example: 8
     * @bodyParam photo_ids integer[] required Ordered photo IDs for the program. Example: [3,1,2]
     * @response 200 {"photos":[]}
     * @response 403 {"message":"Bu proje icin yetkiniz bulunmuyor."}
     */
    public function reorderPhotos(Request $request,int $id): JsonResponse
    {
        $this->abortUnlessAllowed($request,'programs.media.upload');
        $program=Program::query()->findOrFail($id);
        $this->abortUnlessProjectAllowed($request,'programs.media.upload',(int)$program->project_id);
        $this->assertPeriodWritable($request,$program->period_id);
        $v=$request->validate(['photo_ids'=>['required','array']]);
        foreach($v['photo_ids'] as $i=>$photoId) ProgramPhoto::query()->where('program_id',$program->id)->where('id',$photoId)->update(['sort_order'=>$i+1]);
        return response()->json(['photos'=>$program->photos()->get()]);
    }

    /**
     * Update a program photo caption.
     *
     * Requires permission: `programs.media.upload` for the program project. Completed periods require archive update permission.
     *
     * @authenticated
     * @urlParam id integer required Program ID. Example: 8
     * @urlParam photoId integer required Program photo ID. Example: 3
     * @bodyParam caption string Optional photo caption. Example: Kapanis oturumu
     * @response 200 {"photo":{"id":3,"caption":"Kapanis oturumu"}}
     * @response 403 {"message":"Bu proje icin yetkiniz bulunmuyor."}
     */
    public function updatePhoto(Request $request,int $id,int $photoId): JsonResponse
    {
        $this->abortUnlessAllowed($request,'programs.media.upload');
        $program=Program::query()->findOrFail($id);
        $this->abortUnlessProjectAllowed($request,'programs.media.upload',(int)$program->project_id);
        $this->assertPeriodWritable($request,$program->period_id);
        $v=$request->validate(['caption'=>['nullable','string','max:255']]);
        $photo=ProgramPhoto::query()->where('program_id',$program->id)->findOrFail($photoId);
        $photo->update(['caption'=>$v['caption']??null]);
        return response()->json(['photo'=>$photo]);
    }

    /**
     * Delete a program photo.
     *
     * Requires permission: `programs.media.upload` for the program project. Deletes the media file from configured storage and removes the photo row. Completed periods require archive update permission.
     *
     * @authenticated
     * @urlParam id integer required Program ID. Example: 8
     * @urlParam photoId integer required Program photo ID. Example: 3
     * @response 200 {"message":"Fotograf silindi."}
     * @response 403 {"message":"Bu proje icin yetkiniz bulunmuyor."}
     */
    public function deletePhoto(Request $request,int $id,int $photoId): JsonResponse
    {
        $this->abortUnlessAllowed($request,'programs.media.upload');
        $program=Program::query()->findOrFail($id);
        $this->abortUnlessProjectAllowed($request,'programs.media.upload',(int)$program->project_id);
        $this->assertPeriodWritable($request,$program->period_id);
        $photo=ProgramPhoto::query()->where('program_id',$program->id)->findOrFail($photoId);
        MediaStorage::delete($photo->getRawOriginal('url'));
        $photo->delete();
        return response()->json(['message'=>'Fotograf silindi.']);
    }

    /**
     * Update public visibility for a program.
     *
     * Requires permission: `programs.update` for the program project. This panel-only endpoint toggles public listing and featured state without changing the rest of the program payload. Completed periods require archive update permission.
     *
     * @authenticated
     * @urlParam id integer required Program ID. Example: 8
     * @bodyParam is_public boolean Optional public visibility flag. Example: true
     * @bodyParam is_featured boolean Optional featured flag. Example: false
     * @response 200 {"program":{"id":8,"is_public":true,"is_featured":false}}
     * @response 403 {"message":"Bu proje icin yetkiniz bulunmuyor."}
     */
    public function updateVisibility(Request $request,int $id): JsonResponse
    {
        $this->abortUnlessAllowed($request,'programs.update');
        $program=Program::query()->with(['project:id,name','period:id,name,status'])->findOrFail($id);
        $this->abortUnlessProjectAllowed($request,'programs.update',(int)$program->project_id);
        $this->assertPeriodWritable($request,$program->period_id);
        $v=$request->validate(['is_public'=>['sometimes','boolean'],'is_featured'=>['sometimes','boolean']]);
        $program->update($v);
        $this->clearPublicHomepageCache();
        return response()->json(['program'=>$this->programPayload($program->fresh(['project:id,name','period:id,name,status']))]);
    }
    private function validatedProgramData(Request $request,bool $creating,?Program $program=null): array
    {
        $rules=['project_id'=>[$creating?'required':'sometimes','integer','exists:projects,id'],'period_id'=>[$creating?'required':'sometimes','integer','exists:periods,id'],'title'=>[$creating?'required':'sometimes','string','max:255'],'description'=>['nullable','string'],'location'=>['nullable','string','max:255'],'location_place_name'=>['nullable','string','max:255'],'location_place_address'=>['nullable','string','max:500'],'location_place_id'=>['nullable','string','max:255'],'location_place_provider'=>['nullable','string','max:50'],'latitude'=>['nullable','numeric','between:-90,90'],'longitude'=>['nullable','numeric','between:-180,180'],'radius_meters'=>['nullable','integer','min:10','max:5000'],'guest_info'=>['nullable','array'],'start_at'=>[$creating?'required':'sometimes','date'],'end_at'=>[$creating?'required':'sometimes','date','after_or_equal:start_at'],'credit_deduction'=>['nullable','integer','min:0'],'application_quota'=>['nullable','integer','min:1'],'target_audience'=>['nullable','array'],'target_audience.*'=>['string',Rule::in(['student','alumni'])],'feedback_form_template_id'=>['nullable','integer','exists:feedback_form_templates,id'],'status'=>['nullable',Rule::in(['scheduled','active','completed','cancelled'])],'is_public'=>['nullable','boolean'],'is_featured'=>['nullable','boolean']];
        $v=$request->validate($rules);
        if(isset($v['target_audience'])) $v['target_audience']=collect($v['target_audience'])->unique()->values()->all(); elseif($creating) $v['target_audience']=['student'];
        $v['radius_meters']=$v['radius_meters']??$program?->radius_meters??100;
        $v['credit_deduction']=$v['credit_deduction']??$program?->credit_deduction??0;
        $v['status']=$v['status']??$program?->status??'scheduled';
        $v=IstanbulDateTime::normalizeFields($v,['start_at','end_at']);
        return $v;
    }

    private function assertNoOverlap(mixed $startAt,mixed $endAt,?int $ignoreId,?string $status): void
    {
        if ($status === 'cancelled') {
            return;
        }

        $start=IstanbulDateTime::toUtc($startAt);
        $end=IstanbulDateTime::toUtc($endAt);

        if ($start === null || $end === null) {
            return;
        }

        $overlapExists=Program::query()
            ->where('status','!=','cancelled')
            ->when($ignoreId !== null,fn($query)=>$query->whereKeyNot($ignoreId))
            ->where('start_at','<',$end)
            ->where('end_at','>',$start)
            ->exists();

        if ($overlapExists) {
            throw ValidationException::withMessages([
                'start_at'=>['Bu saat araliginda baska bir program bulunuyor.'],
            ])->status(422);
        }
    }

    private function programPayload(Program $p): array
    {
        return ['id'=>$p->id,'title'=>$p->title,'description'=>$p->description,'location'=>$p->location,'location_place_name'=>$p->location_place_name,'location_place_address'=>$p->location_place_address,'location_place_id'=>$p->location_place_id,'location_place_provider'=>$p->location_place_provider,'latitude'=>$p->latitude,'longitude'=>$p->longitude,'radius_meters'=>$p->radius_meters,'guest_info'=>$p->guest_info,'start_at'=>optional($p->start_at)?->toIso8601String(),'end_at'=>optional($p->end_at)?->toIso8601String(),'credit_deduction'=>$p->credit_deduction,'application_quota'=>$p->application_quota,'target_audience'=>$p->targetAudience(),'feedback_form_template_id'=>$p->feedback_form_template_id,'status'=>$p->status,'project_id'=>$p->project_id,'project'=>$p->project?['id'=>$p->project->id,'name'=>$p->project->name]:null,'period'=>$p->period?['id'=>$p->period->id,'name'=>$p->period->name,'status'=>$p->period->status,'lifecycle'=>['is_archive_mode'=>PeriodLifecycleService::isArchiveStatus($p->period->status),'write_capabilities'=>PeriodLifecycleService::writeCapabilitiesForStatus($p->period->status)]]:null,'attendance_count'=>$p->attendances_count??null,'feedback_count'=>$p->feedbacks_count??null,'is_public'=>(bool)$p->is_public,'is_featured'=>(bool)$p->is_featured,'questions'=>FeedbackFormResolver::forProgram($p)];
    }

    private function programParticipantsQuery(Program $program)
    {
        return Participant::query()->with('user:id,name,surname,email,role')->where('project_id',$program->project_id)->when($program->period_id,fn($q)=>$q->where('period_id',$program->period_id));
    }

    private function participantHasCreditImpact(Participant $p): bool
    {
        return $p->user?->role!=='alumni'&&$p->status!=='graduated'&&$p->graduation_status!=='graduated';
    }

    private function attendanceRecords(Program $program)
    {
        $att=Attendance::query()->where('program_id',$program->id)->get()->keyBy('user_id');
        $logsByUser=CreditLog::query()->where('program_id',$program->id)->get()->groupBy('user_id');
        $tokens=Feedback::query()->where('program_id',$program->id)->pluck('anonymous_token')->filter()->all();
        return $this->programParticipantsQuery($program)->orderBy('id')->get()->map(function(Participant $p)use($att,$logsByUser,$tokens,$program){
            $a=$att->get($p->user_id); $logs=$logsByUser->get($p->user_id,collect());
            $deducted=$logs->contains(fn(CreditLog $l)=>$l->type==='deduction'||(int)$l->amount<0);
            $restored=$logs->contains(fn(CreditLog $l)=>$l->type==='restore'||(int)$l->amount>0);
            $expected=hash('sha256',sprintf('%s:%s:%s',$p->user_id,$program->id,config('app.key')));
            return ['id'=>$a?->id,'participant_id'=>$p->id,'student'=>$p->user?trim($p->user->name.' '.$p->user->surname):'Silinmis kullanici','email'=>$p->user?->email,'role'=>$p->user?->role,'credit_applicable'=>$this->participantHasCreditImpact($p),'method'=>$a?->method,'is_valid'=>(bool)($a?->is_valid??false),'attendance_status'=>$a?->is_valid?'present':'absent','latitude'=>$a?->latitude,'longitude'=>$a?->longitude,'feedback_submitted'=>in_array($expected,$tokens,true)||$restored,'credit_deducted'=>$deducted,'credit_restored'=>$restored,'recorded_at'=>optional($a?->created_at)?->toIso8601String()];
        })->values();
    }

    private function buildFeedbackSummary(Request $request): array
    {
        $v=$request->validate(['project_id'=>'nullable|integer|exists:projects,id','period_id'=>'nullable|integer|exists:periods,id']);
        $ctx=$this->resolveProjectPeriodContext($request,'programs.view',!empty($v['project_id'])?(int)$v['project_id']:null,!empty($v['period_id'])?(int)$v['period_id']:null);
        $q=Program::query()->with(['project:id,name','period:id,name,status'])->whereHas('feedbacks')->orderByDesc('start_at');
        $this->applyProjectPeriodContext($q,$ctx);
        $programs=$q->get(); $byProgram=Feedback::query()->whereIn('program_id',$programs->pluck('id'))->orderByDesc('submitted_at')->get()->groupBy('program_id');
        $questionStats=[]; $programRows=[]; $recent=[]; $allRatings=collect(); $withComment=0;
        foreach($programs as $program){
            $feedbacks=$byProgram->get($program->id,collect()); $questions=FeedbackFormResolver::forProgram($program); $rating=FeedbackFormResolver::ratingQuestions($questions); $choices=FeedbackFormResolver::choiceQuestions($questions); $comments=FeedbackFormResolver::commentQuestions($questions); $programRatings=collect(); $programWithComment=0;
            foreach($rating as $question){$key=$question['id']; $values=$feedbacks->map(fn(Feedback $f)=>$f->responses[$key]??null)->filter(fn($v)=>is_numeric($v))->map(fn($v)=>(float)$v)->values(); $questionStats[$key]??=['label'=>$question['label'],'type'=>'rating','count'=>0,'sum'=>0,'distribution'=>[]]; for($s=(int)($question['min']??1);$s<=(int)($question['max']??5);$s++){ $questionStats[$key]['distribution'][(string)$s]??=0; $questionStats[$key]['distribution'][(string)$s]+=$values->filter(fn($v)=>(float)$v===(float)$s)->count(); } $questionStats[$key]['count']+=$values->count(); $questionStats[$key]['sum']+=$values->sum(); $programRatings=$programRatings->merge($values); $allRatings=$allRatings->merge($values);}
            foreach($choices as $question){$key=$question['id']; $values=$feedbacks->map(fn(Feedback $f)=>$f->responses[$key]??null)->filter(fn($v)=>$v!==null&&$v!=='')->values(); $questionStats[$key]??=['label'=>$question['label'],'type'=>'choice','count'=>0,'distribution'=>collect($question['options']??[])->mapWithKeys(fn($o)=>[$o=>0])->all()]; foreach(($question['options']??[]) as $o){$questionStats[$key]['distribution'][$o]??=0; $questionStats[$key]['distribution'][$o]+=$values->filter(fn($v)=>(string)$v===(string)$o)->count();} $questionStats[$key]['count']+=$values->count();}
            foreach($feedbacks as $f){$has=false; foreach($comments as $question){$comment=trim((string)($f->responses[$question['id']]??'')); if($comment==='') continue; $has=true; $recent[]=['program_id'=>$program->id,'program_title'=>$program->title,'project'=>$program->project?->name,'question'=>$question['label'],'comment'=>$comment,'submitted_at'=>optional($f->submitted_at)?->toIso8601String()];} if($has){$programWithComment++; $withComment++;}}
            $programRows[]=['id'=>$program->id,'title'=>$program->title,'project'=>$program->project?->name,'period'=>$program->period?->name,'start_at'=>optional($program->start_at)?->toIso8601String(),'feedback_count'=>$feedbacks->count(),'with_comment'=>$programWithComment,'overall_average'=>$programRatings->count()>0?round($programRatings->avg(),2):null,'rating_count'=>$programRatings->count()];
        }
        $normalized=collect($questionStats)->map(function(array $s){ if(($s['type']??null)==='rating'){ $c=(int)($s['count']??0); $s['average']=$c>0?round(((float)$s['sum'])/$c,2):null; unset($s['sum']); } return $s; })->all();
        $rows=collect($programRows); $break=function(string $key,string $fallback)use($rows):array{return $rows->groupBy(fn(array $r)=>$r[$key]?:$fallback)->map(function($items,string $name){$count=(int)$items->sum('rating_count');$avg=$count>0?round($items->sum(fn(array $r)=>(($r['overall_average']??0)*(int)($r['rating_count']??0)))/$count,2):null; return ['name'=>$name,'program_count'=>$items->count(),'feedback_count'=>(int)$items->sum('feedback_count'),'with_comment'=>(int)$items->sum('with_comment'),'overall_average'=>$avg];})->sortByDesc('feedback_count')->values()->all();};
        return ['summary'=>['program_count'=>$programs->count(),'total_feedback'=>$byProgram->flatten(1)->count(),'with_comment'=>$withComment,'overall_average'=>$allRatings->count()>0?round($allRatings->avg(),2):null],'programs'=>$programRows,'project_breakdown'=>$break('project','Projesiz'),'period_breakdown'=>$break('period','Donemsiz'),'question_stats'=>$normalized,'recent_comments'=>collect($recent)->sortByDesc('submitted_at')->take(20)->values()->all()];
    }

    private function feedbackReportId(Feedback $f): string
    {
        $source=$f->public_id?:($f->anonymous_token?:(string)$f->id);
        return strtoupper(substr(hash('sha256',$source.':'.config('app.key')),0,12));
    }
}
