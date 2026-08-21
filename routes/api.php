<?php

use App\Http\Controllers\Api\AdminApplicationController;
use App\Http\Controllers\Api\AdminCertificateController;
use App\Http\Controllers\Api\AdminChatbotController;
use App\Http\Controllers\Api\AdminCreditController;
use App\Http\Controllers\Api\AdminDashboardController;
use App\Http\Controllers\Api\AdminKpdController;
use App\Http\Controllers\Api\AdminProgramController;
use App\Http\Controllers\Api\AlumniOpportunityController;
use App\Http\Controllers\Api\AnnouncementController;
use App\Http\Controllers\Api\ApplicationController;
use App\Http\Controllers\Api\ApplicationIntakeController;
use App\Http\Controllers\Api\AssignmentController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CalendarController;
use App\Http\Controllers\Api\CertificateController;
use App\Http\Controllers\Api\ContentManagementController;
use App\Http\Controllers\Api\CoordinatorParticipantController;
use App\Http\Controllers\Api\DigitalBohcaController;
use App\Http\Controllers\Api\FeedbackController;
use App\Http\Controllers\Api\FeedbackFormTemplateController;
use App\Http\Controllers\Api\FinancialTransactionController;
use App\Http\Controllers\Api\ForumController;
use App\Http\Controllers\Api\InboxController;
use App\Http\Controllers\Api\MediaUploadController;
use App\Http\Controllers\Api\MotivationController;
use App\Http\Controllers\Api\NewsletterController;
use App\Http\Controllers\Api\PanelModuleController;
use App\Http\Controllers\Api\PeriodController;
use App\Http\Controllers\Api\PermissionMatrixController;
use App\Http\Controllers\Api\PersonalityTestController;
use App\Http\Controllers\Api\PersonalityTestTemplateController;
use App\Http\Controllers\Api\ProgramController;
use App\Http\Controllers\Api\ProjectContentController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\ProjectFamilyPanelController;
use App\Http\Controllers\Api\ProjectSpecialModuleController;
use App\Http\Controllers\Api\Public\PublicContentController;
use App\Http\Controllers\Api\RequestController;
use App\Http\Controllers\Api\SiteSettingsController;
use App\Http\Controllers\Api\SocialSharingController;
use App\Http\Controllers\Api\StaffController;
use App\Http\Controllers\Api\StudentDashboardController;
use App\Http\Controllers\Api\StudentKpdController;
use App\Http\Controllers\Api\SupportTicketController;
use App\Http\Controllers\Api\SystemNotificationController;
use App\Http\Controllers\Api\TrainerController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\VolunteerController;
use Illuminate\Support\Facades\Route;

// Genel Test Endpoint'i
/**
 * API health check.
 *
 * @group Health
 *
 * @unauthenticated
 *
 * @response 200 {"message":"KADEME API is running!"}
 */
Route::get('/ping', function () {
    return response()->json(['message' => 'KADEME API is running!']);
});

// --- GENEL ÃƒÆ’Ã¢â‚¬ÂÃƒâ€šÃ‚Â°ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¡ERÃƒÆ’Ã¢â‚¬ÂÃƒâ€šÃ‚Â°K (PUBLIC) --- //
Route::get('/blogs', [PublicContentController::class, 'blogs']);
Route::get('/blogs/{slug}', [PublicContentController::class, 'blogDetail']);
Route::get('/faqs', [PublicContentController::class, 'faqs']);
Route::get('/activities', [PublicContentController::class, 'activities']);
Route::get('/activities/{id}', [PublicContentController::class, 'activityDetail']);
Route::get('/certificates/verify/{verificationCode}', [CertificateController::class, 'verify']);
Route::get('/certificates/{verificationCode}/download', [CertificateController::class, 'download']);
Route::get('/site-config', [SiteSettingsController::class, 'public']);
Route::get('/homepage', [SiteSettingsController::class, 'homepage']);
Route::get('/motivation/current', [MotivationController::class, 'current']);
Route::post('/contact', [SupportTicketController::class, 'storePublic'])
    ->middleware('throttle:10,1');
Route::post('/newsletter/subscribe', [NewsletterController::class, 'subscribe'])
    ->middleware('throttle:20,1');
Route::get('/newsletter/unsubscribe', [NewsletterController::class, 'unsubscribe'])
    ->middleware('throttle:20,1');

// --- AUTH ROUTLARI --- //
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:5,1');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:10,1');

    Route::middleware(['auth:sanctum', 'blacklist', 'audit.action'])->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::middleware('password.not_pending_setup')->group(function () {
            Route::get('/me', [AuthController::class, 'me']);
        });
    });
});

// --- KULLANICI & PROFÃƒÆ’Ã¢â‚¬ÂÃƒâ€šÃ‚Â°L --- //
// KVKK onay endpointi haricindekilere 'kvkk' kÃƒÆ’Ã¢â‚¬ÂÃƒâ€šÃ‚Â±sÃƒÆ’Ã¢â‚¬ÂÃƒâ€šÃ‚Â±tlamasÃƒÆ’Ã¢â‚¬ÂÃƒâ€šÃ‚Â± getiriyoruz
Route::middleware(['auth:sanctum', 'blacklist', 'password.not_pending_setup', 'audit.action'])->prefix('user')->group(function () {
    Route::post('/consent-kvkk', [UserController::class, 'consentKvkk']);
    Route::post('/kvkk/forget-request', [UserController::class, 'requestKvkkForget']);

    Route::middleware('kvkk')->group(function () {
        Route::get('/profile', [UserController::class, 'getProfile']);
        Route::put('/profile', [UserController::class, 'updateProfile']);
        Route::post('/change-password', [UserController::class, 'changePassword']);
        Route::get('/personality-test', [PersonalityTestController::class, 'show']);
        Route::post('/personality-test', [PersonalityTestController::class, 'submit']);

        // Sistem bildirimleri
        Route::get('/notifications', [SystemNotificationController::class, 'index']);
        Route::patch('/notifications/{id}/read', [SystemNotificationController::class, 'markRead']);
        Route::post('/notifications/read-all', [SystemNotificationController::class, 'markAllRead']);
        Route::delete('/notifications/{id}', [SystemNotificationController::class, 'destroy']);
    });
});

// --- PROJELER & BAÃƒÆ’Ã¢â‚¬Â¦Ãƒâ€šÃ‚ÂVURULAR --- //
Route::prefix('projects')->group(function () {
    // Herkese aÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â§ÃƒÆ’Ã¢â‚¬ÂÃƒâ€šÃ‚Â±k (ZiyaretÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â§iler dahil) projeleri listeleme
    Route::get('/', [ProjectController::class, 'index']);
    Route::get('/{slug}', [ProjectController::class, 'show']);
});

// BaÃƒÆ’Ã¢â‚¬Â¦Ãƒâ€¦Ã‚Â¸vuru iÃƒÆ’Ã¢â‚¬Â¦Ãƒâ€¦Ã‚Â¸lemleri (Oturum gerektirir)
Route::middleware(['auth:sanctum', 'blacklist', 'password.not_pending_setup', 'kvkk', 'audit.action'])->prefix('applications')->group(function () {
    Route::get('/', [ApplicationController::class, 'myApplications']);
    Route::get('/{id}', [ApplicationController::class, 'show']);
    Route::post('/{id}/waitlist-response', [ApplicationController::class, 'respondWaitlistInvitation']);
    Route::post('/', [ApplicationController::class, 'store']);
});

// Public project application endpoint (guest users can apply).
Route::post('/applications/public', [ApplicationController::class, 'storePublic'])
    ->middleware('throttle:10,1');

// --- PROGRAM (ETKÃƒÆ’Ã¢â‚¬ÂÃƒâ€šÃ‚Â°NLÃƒÆ’Ã¢â‚¬ÂÃƒâ€šÃ‚Â°K) & YOKLAMA --- //
Route::middleware(['auth:sanctum', 'blacklist', 'password.not_pending_setup', 'kvkk', 'audit.action'])->group(function () {

    // ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Å“ÃƒÆ’Ã¢â‚¬ÂÃƒâ€¦Ã‚Â¸rencinin kendi programlarÃƒÆ’Ã¢â‚¬ÂÃƒâ€šÃ‚Â±nÃƒÆ’Ã¢â‚¬ÂÃƒâ€šÃ‚Â± listelemesi
    Route::get('/programs', [ProgramController::class, 'myPrograms'])->middleware('scoped.permission:participant.programs.view');
    Route::get('/programs/{id}', [ProgramController::class, 'show'])->middleware('scoped.permission:participant.programs.view');

    // ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Å“ÃƒÆ’Ã¢â‚¬ÂÃƒâ€¦Ã‚Â¸rencinin QR Kod ile yoklama vermesi
    Route::post('/attendances/qr', [AttendanceController::class, 'markQrAttendance'])->middleware('scoped.permission:participant.qr.use');

    // --- ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Å“ÃƒÆ’Ã¢â‚¬ÂÃƒâ€šÃ‚ÂRENCÃƒÆ’Ã¢â‚¬ÂÃƒâ€šÃ‚Â° PANELÃƒÆ’Ã¢â‚¬ÂÃƒâ€šÃ‚Â° (KREDÃƒÆ’Ã¢â‚¬ÂÃƒâ€šÃ‚Â°, BOHÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¡A, ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Å“DEV) --- //
    Route::get('/dashboard/summary', [StudentDashboardController::class, 'summary'])->middleware('scoped.permission:participant.dashboard.view');
    Route::get('/dashboard/projects', [StudentDashboardController::class, 'projects'])->middleware('scoped.permission:participant.projects.view');
    Route::get('/dashboard/digital-cv', [StudentDashboardController::class, 'digitalCv'])->middleware('scoped.permission:participant.cv.manage');
    Route::put('/dashboard/digital-cv', [StudentDashboardController::class, 'saveDigitalCv'])->middleware('scoped.permission:participant.cv.manage');
    Route::post('/dashboard/digital-cv/pdf', [StudentDashboardController::class, 'digitalCvPdf'])->middleware('scoped.permission:participant.cv.manage');
    Route::get('/dashboard/project-specials', [StudentDashboardController::class, 'projectSpecials'])->middleware('scoped.permission:participant.projects.view');
    Route::post('/dashboard/projects/{projectId}/kademe-modules/{moduleId}/enroll', [StudentDashboardController::class, 'enrollKademeModule'])->middleware('scoped.permission:participant.projects.view');
    Route::get('/dashboard/projects/{projectId}/badge-leaderboard', [StudentDashboardController::class, 'badgeLeaderboard'])->middleware('scoped.permission:participant.projects.view');
    Route::get('/announcements', [AnnouncementController::class, 'recipientAnnouncements'])->middleware('scoped.permission:participant.inbox.view');
    Route::get('/alumni-opportunities', [AlumniOpportunityController::class, 'recipientIndex'])->middleware('scoped.permission:alumni.opportunities.view');
    Route::get('/forum/posts', [ForumController::class, 'index'])->middleware('scoped.permission:participant.forum.view');
    Route::post('/forum/posts', [ForumController::class, 'store'])->middleware('scoped.permission:participant.forum.view');
    Route::post('/forum/posts/{postId}/replies', [ForumController::class, 'reply'])->middleware('scoped.permission:participant.forum.view');
    Route::get('/inbox/messages', [InboxController::class, 'recipientMessages'])->middleware('scoped.permission:participant.inbox.view');
    Route::put('/inbox/messages/state', [InboxController::class, 'upsertState'])->middleware('scoped.permission:participant.inbox.view');
    // -- SOSYAL MEDYA PAYLASIM WEBHOOK
    Route::post('/social-sharing/post', [SocialSharingController::class, 'post'])->middleware('scoped.permission:participant.profile.manage');

    Route::get('/digital-bohca', [DigitalBohcaController::class, 'index'])->middleware('scoped.permission:participant.bohca.view');
    Route::get('/digital-bohca/{id}/download', [DigitalBohcaController::class, 'download'])->middleware('scoped.permission:participant.bohca.view');
    Route::get('/certificates', [CertificateController::class, 'index'])->middleware('scoped.permission:participant.certificates.view');
    Route::post('/certificates', [CertificateController::class, 'store'])->middleware('scoped.permission:participant.certificates.view');
    Route::get('/feedbacks', [FeedbackController::class, 'index'])->middleware('scoped.permission:participant.feedback.create');
    Route::post('/feedbacks', [FeedbackController::class, 'store'])->middleware('scoped.permission:participant.feedback.create');
    Route::get('/requests', [RequestController::class, 'index'])->middleware('scoped.permission:participant.support.manage');
    Route::get('/requests/export', [RequestController::class, 'export'])->middleware('scoped.permission:participant.support.manage');
    Route::post('/requests', [RequestController::class, 'store'])->middleware('scoped.permission:participant.support.manage');
    Route::put('/requests/{id}/status', [RequestController::class, 'updateStatus'])->middleware('scoped.permission:participant.support.manage');
    Route::post('/requests/{id}/upload-response', [RequestController::class, 'uploadResponseFile'])->middleware('scoped.permission:participant.support.manage');
    Route::get('/requests/{id}/response-file', [RequestController::class, 'downloadResponseFile'])->middleware('scoped.permission:participant.support.manage');
    Route::get('/kpd/appointments', [StudentKpdController::class, 'index'])->middleware('scoped.permission:participant.kpd.view');
    Route::post('/kpd/appointments', [StudentKpdController::class, 'store'])->middleware('scoped.permission:participant.kpd.view');
    Route::post('/kpd/appointments/{id}/cancel', [StudentKpdController::class, 'cancel'])->middleware('scoped.permission:participant.kpd.view');
    Route::get('/kpd/reports/{id}/download', [StudentKpdController::class, 'downloadReport'])->middleware('scoped.permission:participant.kpd.view');
    Route::get('/volunteer/opportunities', [VolunteerController::class, 'index'])->middleware('scoped.permission:participant.volunteer.apply');
    Route::post('/volunteer/opportunities/{id}/apply', [VolunteerController::class, 'apply'])->middleware('scoped.permission:participant.volunteer.apply');

    Route::get('/assignments', [AssignmentController::class, 'index'])->middleware('scoped.permission:participant.assignments.view');
    Route::post('/assignments/{id}/submit', [AssignmentController::class, 'submit'])->middleware('scoped.permission:participant.assignments.submit');
    Route::get('/assignment-submissions/{id}/download', [AssignmentController::class, 'downloadSubmission'])->middleware('scoped.permission:participant.assignments.view');
    Route::get('/assignment-attachments/{id}/download', [AssignmentController::class, 'downloadAttachment'])->middleware('scoped.permission:participant.assignments.view');

    // Destek Talepleri (ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Å“ÃƒÆ’Ã¢â‚¬ÂÃƒâ€¦Ã‚Â¸renci TarafÃƒÆ’Ã¢â‚¬ÂÃƒâ€šÃ‚Â±)
    Route::get('/tickets', [SupportTicketController::class, 'myTickets'])->middleware('scoped.permission:participant.support.manage');
    Route::get('/tickets/export', [SupportTicketController::class, 'exportMyTickets'])->middleware('scoped.permission:participant.support.manage');
    Route::post('/tickets', [SupportTicketController::class, 'store'])->middleware('scoped.permission:participant.support.manage');
    Route::post('/tickets/{id}/reply', [SupportTicketController::class, 'reply'])->middleware('scoped.permission:participant.support.manage');
    Route::get('/tickets/{id}/attachment', [SupportTicketController::class, 'downloadTicketAttachment'])->middleware('scoped.permission:participant.support.manage');
    Route::get('/tickets/replies/{id}/attachment', [SupportTicketController::class, 'downloadReplyAttachment'])->middleware('scoped.permission:participant.support.manage');
});

// --- ADMIN / KOORDÃƒÆ’Ã¢â‚¬ÂÃƒâ€šÃ‚Â°NATÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Å“R PANELÃƒÆ’Ã¢â‚¬ÂÃƒâ€šÃ‚Â° --- //
Route::middleware(['auth:sanctum', 'blacklist', 'password.not_pending_setup', 'role:super_admin|coordinator|staff', 'audit.action'])->prefix('admin')->group(function () {

    // Dashboard ÃƒÆ’Ã¢â‚¬ÂÃƒâ€šÃ‚Â°statistikleri
    Route::get('/dashboard/stats', [AdminDashboardController::class, 'stats']);
    Route::get('/dashboard/activity-logs', [AdminDashboardController::class, 'activityLogs']);
    Route::get('/dashboard/activity-logs/export', [AdminDashboardController::class, 'exportActivityLogs']);
    Route::get('/dashboard/credit-risk/export', [AdminDashboardController::class, 'exportCreditRisk']);

    // BaÃƒÆ’Ã¢â‚¬Â¦Ãƒâ€¦Ã‚Â¸vurularÃƒÆ’Ã¢â‚¬ÂÃƒâ€šÃ‚Â± YÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¶net
    Route::get('/applications', [AdminApplicationController::class, 'index']);
    Route::get('/applications/export', [AdminApplicationController::class, 'export']);
    Route::get('/applications/{id}/form-files/{field}', [AdminApplicationController::class, 'downloadFormFile']);
    Route::put('/applications/{id}/status', [AdminApplicationController::class, 'updateStatus']);
    Route::put('/applications/{id}/interview', [AdminApplicationController::class, 'planInterview']);
    Route::post('/applications/{id}/waitlist', [AdminApplicationController::class, 'addToWaitlist']);
    Route::put('/applications/{id}/waitlist-order', [AdminApplicationController::class, 'updateWaitlistOrder']);
    Route::post('/applications/{id}/waitlist-invite', [AdminApplicationController::class, 'inviteFromWaitlist']);
    Route::post('/applications/{id}/waitlist-refresh', [AdminApplicationController::class, 'refreshWaitlistInvitations']);

    // Etkinlik (Program) ve QR YÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¶netimi
    Route::get('/programs', [AdminProgramController::class, 'index']);
    Route::get('/programs/export', [AdminProgramController::class, 'export']);
    Route::get('/programs/{id}', [AdminProgramController::class, 'show'])->whereNumber('id');
    Route::get('/programs/{id}/qr-context', [AdminProgramController::class, 'qrContext'])->whereNumber('id');
    Route::post('/programs', [AdminProgramController::class, 'store']);
    Route::put('/programs/{id}', [AdminProgramController::class, 'update']);
    Route::post('/programs/{id}/generate-qr', [AdminProgramController::class, 'generateQr']);
    Route::post('/programs/{id}/complete', [AdminProgramController::class, 'complete']);
    Route::get('/programs/{id}/attendances', [AdminProgramController::class, 'attendanceDetails']);
    Route::put('/programs/{id}/attendances/{participantId}', [AdminProgramController::class, 'markManualAttendance']);
    Route::get('/programs/{id}/attendances/export', [AdminProgramController::class, 'exportAttendanceDetails']);
    Route::get('/programs/feedback-summary', [AdminProgramController::class, 'feedbackSummary']);
    Route::get('/programs/feedback-summary/export', [AdminProgramController::class, 'exportFeedbackSummary']);
    Route::get('/programs/{id}/feedback-stats', [AdminProgramController::class, 'feedbackStats']);
    Route::get('/programs/{id}/feedback-stats/export', [AdminProgramController::class, 'exportFeedback']);

    // Kredi (Puan) ve Rozet YÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¶netimi
    Route::post('/credits/adjust', [AdminCreditController::class, 'adjustCredit']);
    Route::post('/badges/award', [AdminCreditController::class, 'awardBadge']);
    // Sertifika YÃƒÆ’Ã‚Â¶netimi
    Route::get('/certificates', [AdminCertificateController::class, 'index']);
    Route::get('/certificates/export', [AdminCertificateController::class, 'export']);
    Route::post('/certificates', [AdminCertificateController::class, 'store']);
    Route::delete('/certificates/{id}', [AdminCertificateController::class, 'destroy']);
    Route::get('/projects/manageable', [ProjectContentController::class, 'manageable']);
    Route::get('/projects/export', [ProjectContentController::class, 'exportManageable']);
    Route::get('/projects/{id}/modules', [ProjectContentController::class, 'modules']);
    Route::get('/projects/{id}/application-settings', [ApplicationIntakeController::class, 'show']);
    Route::patch('/projects/{id}/application-settings', [ApplicationIntakeController::class, 'update']);
    Route::get('/projects/{id}/special-modules', [ProjectSpecialModuleController::class, 'index']);
    Route::post('/projects/{id}/special-modules/internships', [ProjectSpecialModuleController::class, 'storeInternship']);
    Route::put('/projects/{id}/special-modules/internships/{item}', [ProjectSpecialModuleController::class, 'updateInternship']);
    Route::delete('/projects/{id}/special-modules/internships/{item}', [ProjectSpecialModuleController::class, 'destroyInternship']);
    Route::post('/projects/{id}/special-modules/mentors', [ProjectSpecialModuleController::class, 'storeMentor']);
    Route::put('/projects/{id}/special-modules/mentors/{item}', [ProjectSpecialModuleController::class, 'updateMentor']);
    Route::delete('/projects/{id}/special-modules/mentors/{item}', [ProjectSpecialModuleController::class, 'destroyMentor']);
    Route::get('/projects/{id}/special-modules/mentors/{mentorId}/participants', [ProjectSpecialModuleController::class, 'mentorParticipants']);
    Route::post('/projects/{id}/special-modules/mentors/{mentorId}/participants', [ProjectSpecialModuleController::class, 'assignMentorToParticipant']);
    Route::delete('/projects/{id}/special-modules/mentors/{mentorId}/participants/{participantId}', [ProjectSpecialModuleController::class, 'unassignMentorFromParticipant']);
    Route::post('/projects/{id}/special-modules/eurodesk-projects', [ProjectSpecialModuleController::class, 'storeEurodeskProject']);
    Route::put('/projects/{id}/special-modules/eurodesk-projects/{item}', [ProjectSpecialModuleController::class, 'updateEurodeskProject']);
    Route::delete('/projects/{id}/special-modules/eurodesk-projects/{item}', [ProjectSpecialModuleController::class, 'destroyEurodeskProject']);
    Route::post('/projects/{id}/special-modules/eurodesk-projects/{eurodeskProjectId}/partnerships', [ProjectSpecialModuleController::class, 'storeEurodeskPartnership']);
    Route::put('/projects/{id}/special-modules/eurodesk-projects/{eurodeskProjectId}/partnerships/{partnershipId}', [ProjectSpecialModuleController::class, 'updateEurodeskPartnership']);
    Route::delete('/projects/{id}/special-modules/eurodesk-projects/{eurodeskProjectId}/partnerships/{partnershipId}', [ProjectSpecialModuleController::class, 'destroyEurodeskPartnership']);
    Route::post('/projects/{id}/special-modules/reward-tiers', [ProjectSpecialModuleController::class, 'storeRewardTier']);
    Route::put('/projects/{id}/special-modules/reward-tiers/{item}', [ProjectSpecialModuleController::class, 'updateRewardTier']);
    Route::delete('/projects/{id}/special-modules/reward-tiers/{item}', [ProjectSpecialModuleController::class, 'destroyRewardTier']);
    Route::post('/projects/{id}/special-modules/reward-awards', [ProjectSpecialModuleController::class, 'storeRewardAward']);
    Route::patch('/projects/{id}/special-modules/reward-awards/{item}/deliver', [ProjectSpecialModuleController::class, 'markRewardDelivered']);
    Route::delete('/projects/{id}/special-modules/reward-awards/{item}', [ProjectSpecialModuleController::class, 'destroyRewardAward']);
    Route::post('/projects/{id}/special-modules/kademe-modules', [ProjectSpecialModuleController::class, 'storeKademeModule']);
    Route::put('/projects/{id}/special-modules/kademe-modules/{item}', [ProjectSpecialModuleController::class, 'updateKademeModule']);
    Route::delete('/projects/{id}/special-modules/kademe-modules/{item}', [ProjectSpecialModuleController::class, 'destroyKademeModule']);
    Route::put('/projects/{id}/special-modules/kademe-module-enrollments/{enrollment}', [ProjectSpecialModuleController::class, 'updateKademeModuleEnrollment']);
    Route::get('/projects/{id}/content', [ProjectContentController::class, 'show']);
    Route::put('/projects/{id}/content', [ProjectContentController::class, 'update']);
    Route::get('/projects/{id}/application-form', [ProjectContentController::class, 'applicationForm']);
    Route::put('/projects/{id}/application-form', [ProjectContentController::class, 'updateApplicationForm']);
    Route::get('/periods', [PeriodController::class, 'index']);
    Route::get('/periods/export', [PeriodController::class, 'export']);
    Route::post('/periods', [PeriodController::class, 'store']);
    Route::get('/periods/{id}', [PeriodController::class, 'show']);
    Route::get('/periods/{id}/closure-summary', [PeriodController::class, 'closureSummary']);
    Route::get('/periods/{id}/archives', [PeriodController::class, 'archives']);
    Route::get('/periods/{id}/archives/{archiveId}', [PeriodController::class, 'archive']);
    Route::post('/periods/{id}/archives/{archiveId}/verify', [PeriodController::class, 'verifyArchive']);
    Route::post('/periods/{id}/activate', [PeriodController::class, 'activate']);
    Route::post('/periods/{id}/closing/start', [PeriodController::class, 'startClosing']);
    Route::post('/periods/{id}/closing/cancel', [PeriodController::class, 'cancelClosing']);
    Route::post('/periods/{id}/complete', [PeriodController::class, 'complete']);
    Route::post('/periods/{id}/reopen', [PeriodController::class, 'reopen']);
    Route::post('/periods/{id}/cancel', [PeriodController::class, 'cancel']);
    Route::put('/periods/{id}', [PeriodController::class, 'update']);

    // KPD YÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¶netimi
    Route::get('/kpd/appointments', [AdminKpdController::class, 'index']);
    Route::post('/kpd/appointments', [AdminKpdController::class, 'store']);
    Route::put('/kpd/appointments/{id}/status', [AdminKpdController::class, 'updateStatus']);
    Route::get('/kpd/options', [AdminKpdController::class, 'options']);
    Route::get('/kpd/reports', [AdminKpdController::class, 'reports']);
    Route::post('/kpd/reports', [AdminKpdController::class, 'storeReport']);
    Route::get('/kpd/reports/{id}/download', [AdminKpdController::class, 'downloadReport']);
    Route::delete('/kpd/reports/{id}', [AdminKpdController::class, 'destroyReport']);

    // Site AyarlarÃƒÆ’Ã¢â‚¬ÂÃƒâ€šÃ‚Â± & ÃƒÆ’Ã¢â‚¬ÂÃƒâ€šÃ‚Â°ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â§erik
    Route::get('/site-settings', [SiteSettingsController::class, 'admin']);
    Route::put('/site-settings', [SiteSettingsController::class, 'update']);
    Route::post('/media/upload', [MediaUploadController::class, 'store']);
    Route::post('/chatbot/query', [AdminChatbotController::class, 'query']);
    Route::get('/chatbot/export/{token}', [AdminChatbotController::class, 'export']);

    Route::get('/content', [ContentManagementController::class, 'index']);
    Route::get('/content/blogs/export', [ContentManagementController::class, 'exportBlogs']);
    Route::get('/content/faqs/export', [ContentManagementController::class, 'exportFaqs']);
    Route::post('/content/blogs', [ContentManagementController::class, 'storeBlog']);
    Route::put('/content/blogs/{id}', [ContentManagementController::class, 'updateBlog']);
    Route::delete('/content/blogs/{id}', [ContentManagementController::class, 'deleteBlog']);
    Route::post('/content/faqs', [ContentManagementController::class, 'storeFaq']);
    Route::put('/content/faqs/{id}', [ContentManagementController::class, 'updateFaq']);
    Route::delete('/content/faqs/{id}', [ContentManagementController::class, 'deleteFaq']);
    Route::get('/personality-test-templates', [PersonalityTestTemplateController::class, 'index']);
    Route::post('/personality-test-templates', [PersonalityTestTemplateController::class, 'store']);
    Route::put('/personality-test-templates/{id}', [PersonalityTestTemplateController::class, 'update']);
    Route::post('/personality-test-templates/{id}/activate', [PersonalityTestTemplateController::class, 'activate']);
    Route::delete('/personality-test-templates/{id}', [PersonalityTestTemplateController::class, 'destroy']);

    Route::get('/newsletter/subscribers', [NewsletterController::class, 'adminSubscribers']);
    Route::get('/newsletter/subscribers/export', [NewsletterController::class, 'exportSubscribers']);

    // ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ MALÃƒÆ’Ã¢â‚¬ÂÃƒâ€šÃ‚Â° ÃƒÆ’Ã¢â‚¬ÂÃƒâ€šÃ‚Â°ÃƒÆ’Ã¢â‚¬Â¦Ãƒâ€šÃ‚ÂLEMLER ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬
    Route::get('/financials/export', [FinancialTransactionController::class, 'export']);
    Route::get('/financials', [FinancialTransactionController::class, 'index']);
    Route::post('/financials', [FinancialTransactionController::class, 'store']);
    Route::get('/financials/{id}', [FinancialTransactionController::class, 'show']);
    Route::put('/financials/{id}/approve', [FinancialTransactionController::class, 'approve']);
    Route::put('/financials/{id}/reject', [FinancialTransactionController::class, 'reject']);
    Route::put('/financials/{id}/pay', [FinancialTransactionController::class, 'markPaid']);
    Route::delete('/financials/{id}', [FinancialTransactionController::class, 'destroy']);
    Route::get('/financials/{id}/invoice', [FinancialTransactionController::class, 'downloadInvoice']);

    // ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ PERSONEL YÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Å“NETÃƒÆ’Ã¢â‚¬ÂÃƒâ€šÃ‚Â°MÃƒÆ’Ã¢â‚¬ÂÃƒâ€šÃ‚Â° ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬
    Route::get('/staff/export', [StaffController::class, 'export']);
    Route::get('/staff/active', [StaffController::class, 'active']);
    Route::get('/staff/create-options', [StaffController::class, 'createOptions']);
    Route::post('/staff', [StaffController::class, 'store']);
    Route::get('/staff', [StaffController::class, 'index']);
    Route::get('/staff/{id}', [StaffController::class, 'show']);
    Route::put('/staff/{id}', [StaffController::class, 'update']);
    Route::put('/staff/{id}/projects', [StaffController::class, 'syncProjects']);
    Route::post('/staff/{id}/documents', [StaffController::class, 'uploadDocument']);

    // ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ ÃƒÆ’Ã¢â‚¬ÂÃƒâ€šÃ‚Â°ZÃƒÆ’Ã¢â‚¬ÂÃƒâ€šÃ‚Â°N TALEPLERÃƒÆ’Ã¢â‚¬ÂÃƒâ€šÃ‚Â° (Admin gÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¶rÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¼nÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¼mÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¼) ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬

    // ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ DUYURULAR ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬
    Route::post('/announcements/send-sms', [AnnouncementController::class, 'sendSms']);
    Route::post('/announcements/send-email', [AnnouncementController::class, 'sendEmail']);
    Route::get('/announcements/communication-logs', [AnnouncementController::class, 'communicationLogs']);
    Route::get('/announcements/communication-logs/export', [AnnouncementController::class, 'exportCommunicationLogs']);
    Route::get('/announcements/communication-logs/{id}/attachment', [AnnouncementController::class, 'downloadCommunicationAttachment']);
    Route::get('/motivation/lists', [MotivationController::class, 'index']);
    Route::post('/motivation/lists', [MotivationController::class, 'storeList']);
    Route::put('/motivation/lists/{id}', [MotivationController::class, 'updateList']);
    Route::delete('/motivation/lists/{id}', [MotivationController::class, 'destroyList']);
    Route::post('/motivation/lists/{id}/quotes', [MotivationController::class, 'storeQuote']);
    Route::put('/motivation/quotes/{id}', [MotivationController::class, 'updateQuote']);
    Route::delete('/motivation/quotes/{id}', [MotivationController::class, 'destroyQuote']);
    Route::get('/announcements', [AnnouncementController::class, 'index']);
    Route::get('/announcements/export', [AnnouncementController::class, 'export']);
    Route::post('/announcements', [AnnouncementController::class, 'store']);
    Route::get('/announcements/{id}', [AnnouncementController::class, 'show']);
    Route::put('/announcements/{id}', [AnnouncementController::class, 'update']);
    Route::delete('/announcements/{id}', [AnnouncementController::class, 'destroy']);
    Route::get('/inbox/messages', [InboxController::class, 'recipientMessages']);
    Route::put('/inbox/messages/state', [InboxController::class, 'upsertState']);
    Route::get('/forum/posts', [ForumController::class, 'panelIndex']);
    // -- SOSYAL MEDYA PAYLASIM WEBHOOK
    Route::post('/social-sharing/post', [SocialSharingController::class, 'post']);

    // ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ KULLANICI YÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Å“NETÃƒÆ’Ã¢â‚¬ÂÃƒâ€šÃ‚Â°MÃƒÆ’Ã¢â‚¬ÂÃƒâ€šÃ‚Â° ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬
    Route::get('/users/export', [UserController::class, 'exportUsers']);
    Route::get('/users/create-options', [UserController::class, 'createOptions']);
    Route::post('/users', [UserController::class, 'storeUser']);
    Route::get('/users', [UserController::class, 'index']);
    Route::get('/kvkk/forget-requests', [UserController::class, 'listKvkkForgetRequests']);
    Route::post('/kvkk/forget-requests/{id}/resolve', [UserController::class, 'resolveKvkkForgetRequest']);
    Route::put('/users/{id}/coordinated-projects', [UserController::class, 'syncCoordinatedProjects']);
    Route::get('/users/{id}', [UserController::class, 'showUser']);
    Route::put('/users/{id}', [UserController::class, 'updateUser']);
    Route::get('/permissions-matrix', [PermissionMatrixController::class, 'index']);
    Route::put('/permissions-matrix', [PermissionMatrixController::class, 'update']);
    Route::get('/permissions-matrix/audit', [PermissionMatrixController::class, 'audit']);
    Route::get('/permissions-matrix/users', [PermissionMatrixController::class, 'users']);
    Route::get('/permissions-matrix/users/{id}', [PermissionMatrixController::class, 'showUserOverrides']);
    Route::put('/permissions-matrix/users/{id}', [PermissionMatrixController::class, 'updateUserOverrides']);
    Route::put('/permissions-matrix/users/{id}/roles', [PermissionMatrixController::class, 'assignUserRoles']);
    Route::get('/permissions-matrix/roles', [PermissionMatrixController::class, 'roleCatalog']);
    Route::post('/permissions-matrix/roles', [PermissionMatrixController::class, 'createRole']);
    Route::put('/permissions-matrix/roles/{id}', [PermissionMatrixController::class, 'updateRole']);
    Route::delete('/permissions-matrix/roles/{id}', [PermissionMatrixController::class, 'deleteRole']);

    // ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ DESTEK MERKEZÃƒÆ’Ã¢â‚¬ÂÃƒâ€šÃ‚Â° (Admin) ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬
    Route::get('/support/tickets', [SupportTicketController::class, 'index']);
    Route::get('/support/assignable-users', [SupportTicketController::class, 'assignableUsers']);
    Route::get('/support/tickets/export', [SupportTicketController::class, 'export']);
    Route::post('/support/tickets/{id}/reply', [SupportTicketController::class, 'reply']);
    Route::get('/support/tickets/{id}/attachment', [SupportTicketController::class, 'downloadTicketAttachment']);
    Route::put('/support/tickets/{id}/assign', [SupportTicketController::class, 'assign']);
    Route::put('/support/tickets/{id}/close', [SupportTicketController::class, 'close']);
    Route::get('/tickets/replies/{id}/attachment', [SupportTicketController::class, 'downloadReplyAttachment']);
});

// Unified panel icin rol-prefix bagimsiz generic alias endpointleri.
// /admin/* endpointleri geriye donuk uyumluluk icin oldugu gibi korunur.
Route::middleware(['auth:sanctum', 'blacklist', 'password.not_pending_setup', 'audit.action'])->prefix('panel')->group(function () {
    Route::get('/modules', [PanelModuleController::class, 'index']);

    Route::get('/programs', [AdminProgramController::class, 'index']);
    Route::get('/programs/export', [AdminProgramController::class, 'export']);
    Route::get('/programs/{id}', [AdminProgramController::class, 'show'])->whereNumber('id');
    Route::get('/programs/{id}/qr-context', [AdminProgramController::class, 'qrContext'])->whereNumber('id');
    Route::post('/programs', [AdminProgramController::class, 'store']);
    Route::put('/programs/{id}', [AdminProgramController::class, 'update']);
    Route::post('/programs/{id}/complete', [AdminProgramController::class, 'complete']);
    Route::post('/programs/{id}/generate-qr', [AdminProgramController::class, 'generateQr']);
    Route::get('/programs/{id}/attendances', [AdminProgramController::class, 'attendanceDetails']);
    Route::put('/programs/{id}/attendances/{participantId}', [AdminProgramController::class, 'markManualAttendance']);
    Route::get('/programs/{id}/attendances/export', [AdminProgramController::class, 'exportAttendanceDetails']);
    Route::get('/programs/feedback-summary', [AdminProgramController::class, 'feedbackSummary']);
    Route::get('/programs/feedback-summary/export', [AdminProgramController::class, 'exportFeedbackSummary']);
    Route::get('/programs/{id}/feedback-stats', [AdminProgramController::class, 'feedbackStats']);
    Route::get('/programs/{id}/feedback-stats/export', [AdminProgramController::class, 'exportFeedback']);
    Route::get('/feedback-form-templates', [FeedbackFormTemplateController::class, 'index']);
    Route::post('/feedback-form-templates', [FeedbackFormTemplateController::class, 'store']);
    Route::put('/feedback-form-templates/{id}', [FeedbackFormTemplateController::class, 'update']);
    Route::delete('/feedback-form-templates/{id}', [FeedbackFormTemplateController::class, 'destroy']);
    Route::get('/programs/{id}/photos', [AdminProgramController::class, 'photos']);
    Route::post('/programs/{id}/photos', [AdminProgramController::class, 'uploadPhoto']);
    Route::put('/programs/{id}/photos/reorder', [AdminProgramController::class, 'reorderPhotos']);
    Route::put('/programs/{id}/photos/{photoId}', [AdminProgramController::class, 'updatePhoto']);
    Route::delete('/programs/{id}/photos/{photoId}', [AdminProgramController::class, 'deletePhoto']);
    Route::patch('/programs/{id}/visibility', [AdminProgramController::class, 'updateVisibility']);

    Route::get('/applications', [AdminApplicationController::class, 'index']);
    Route::get('/applications/export', [AdminApplicationController::class, 'export']);
    Route::get('/applications/{id}/form-files/{field}', [AdminApplicationController::class, 'downloadFormFile']);
    Route::put('/applications/{id}/status', [AdminApplicationController::class, 'updateStatus']);
    Route::put('/applications/{id}/interview', [AdminApplicationController::class, 'planInterview']);
    Route::post('/applications/{id}/waitlist', [AdminApplicationController::class, 'addToWaitlist']);
    Route::put('/applications/{id}/waitlist-order', [AdminApplicationController::class, 'updateWaitlistOrder']);
    Route::post('/applications/{id}/waitlist-invite', [AdminApplicationController::class, 'inviteFromWaitlist']);
    Route::post('/applications/{id}/waitlist-refresh', [AdminApplicationController::class, 'refreshWaitlistInvitations']);

    Route::post('/credits/adjust', [AdminCreditController::class, 'adjustCredit']);
    Route::post('/badges/award', [AdminCreditController::class, 'awardBadge']);

    Route::get('/volunteer/opportunities', [VolunteerController::class, 'panelIndex']);
    Route::get('/volunteer/opportunities/export', [VolunteerController::class, 'panelExport']);
    Route::post('/volunteer/opportunities', [VolunteerController::class, 'panelStore']);
    Route::put('/volunteer/opportunities/{id}', [VolunteerController::class, 'panelUpdate']);
    Route::put('/volunteer/applications/{id}', [VolunteerController::class, 'panelUpdateApplication']);
    Route::delete('/volunteer/opportunities/{id}', [VolunteerController::class, 'panelDestroy']);

    Route::get('/periods', [PeriodController::class, 'index']);
    Route::get('/periods/export', [PeriodController::class, 'export']);
    Route::post('/periods', [PeriodController::class, 'store']);
    Route::get('/periods/{id}', [PeriodController::class, 'show']);
    Route::get('/periods/{id}/closure-summary', [PeriodController::class, 'closureSummary']);
    Route::get('/periods/{id}/archives', [PeriodController::class, 'archives']);
    Route::get('/periods/{id}/archives/{archiveId}', [PeriodController::class, 'archive']);
    Route::post('/periods/{id}/archives/{archiveId}/verify', [PeriodController::class, 'verifyArchive']);
    Route::post('/periods/{id}/activate', [PeriodController::class, 'activate']);
    Route::post('/periods/{id}/closing/start', [PeriodController::class, 'startClosing']);
    Route::post('/periods/{id}/closing/cancel', [PeriodController::class, 'cancelClosing']);
    Route::post('/periods/{id}/complete', [PeriodController::class, 'complete']);
    Route::post('/periods/{id}/reopen', [PeriodController::class, 'reopen']);
    Route::post('/periods/{id}/cancel', [PeriodController::class, 'cancel']);
    Route::put('/periods/{id}', [PeriodController::class, 'update']);

    Route::get('/projects/manageable', [ProjectContentController::class, 'manageable']);
    Route::get('/projects/export', [ProjectContentController::class, 'exportManageable']);
    Route::get('/project-families/{family}', [ProjectFamilyPanelController::class, 'show']);
    Route::get('/projects/{id}/modules', [ProjectContentController::class, 'modules']);
    Route::get('/projects/{id}/application-settings', [ApplicationIntakeController::class, 'show']);
    Route::patch('/projects/{id}/application-settings', [ApplicationIntakeController::class, 'update']);
    Route::get('/projects/{id}/special-modules', [ProjectSpecialModuleController::class, 'index']);
    Route::post('/projects/{id}/special-modules/internships', [ProjectSpecialModuleController::class, 'storeInternship']);
    Route::put('/projects/{id}/special-modules/internships/{item}', [ProjectSpecialModuleController::class, 'updateInternship']);
    Route::delete('/projects/{id}/special-modules/internships/{item}', [ProjectSpecialModuleController::class, 'destroyInternship']);
    Route::post('/projects/{id}/special-modules/mentors', [ProjectSpecialModuleController::class, 'storeMentor']);
    Route::put('/projects/{id}/special-modules/mentors/{item}', [ProjectSpecialModuleController::class, 'updateMentor']);
    Route::delete('/projects/{id}/special-modules/mentors/{item}', [ProjectSpecialModuleController::class, 'destroyMentor']);
    Route::get('/projects/{id}/special-modules/mentors/{mentorId}/participants', [ProjectSpecialModuleController::class, 'mentorParticipants']);
    Route::post('/projects/{id}/special-modules/mentors/{mentorId}/participants', [ProjectSpecialModuleController::class, 'assignMentorToParticipant']);
    Route::delete('/projects/{id}/special-modules/mentors/{mentorId}/participants/{participantId}', [ProjectSpecialModuleController::class, 'unassignMentorFromParticipant']);
    Route::post('/projects/{id}/special-modules/eurodesk-projects', [ProjectSpecialModuleController::class, 'storeEurodeskProject']);
    Route::put('/projects/{id}/special-modules/eurodesk-projects/{item}', [ProjectSpecialModuleController::class, 'updateEurodeskProject']);
    Route::delete('/projects/{id}/special-modules/eurodesk-projects/{item}', [ProjectSpecialModuleController::class, 'destroyEurodeskProject']);
    Route::post('/projects/{id}/special-modules/eurodesk-projects/{eurodeskProjectId}/partnerships', [ProjectSpecialModuleController::class, 'storeEurodeskPartnership']);
    Route::put('/projects/{id}/special-modules/eurodesk-projects/{eurodeskProjectId}/partnerships/{partnershipId}', [ProjectSpecialModuleController::class, 'updateEurodeskPartnership']);
    Route::delete('/projects/{id}/special-modules/eurodesk-projects/{eurodeskProjectId}/partnerships/{partnershipId}', [ProjectSpecialModuleController::class, 'destroyEurodeskPartnership']);
    Route::post('/projects/{id}/special-modules/reward-tiers', [ProjectSpecialModuleController::class, 'storeRewardTier']);
    Route::put('/projects/{id}/special-modules/reward-tiers/{item}', [ProjectSpecialModuleController::class, 'updateRewardTier']);
    Route::delete('/projects/{id}/special-modules/reward-tiers/{item}', [ProjectSpecialModuleController::class, 'destroyRewardTier']);
    Route::post('/projects/{id}/special-modules/reward-awards', [ProjectSpecialModuleController::class, 'storeRewardAward']);
    Route::patch('/projects/{id}/special-modules/reward-awards/{item}/deliver', [ProjectSpecialModuleController::class, 'markRewardDelivered']);
    Route::delete('/projects/{id}/special-modules/reward-awards/{item}', [ProjectSpecialModuleController::class, 'destroyRewardAward']);
    Route::post('/projects/{id}/special-modules/kademe-modules', [ProjectSpecialModuleController::class, 'storeKademeModule']);
    Route::put('/projects/{id}/special-modules/kademe-modules/{item}', [ProjectSpecialModuleController::class, 'updateKademeModule']);
    Route::delete('/projects/{id}/special-modules/kademe-modules/{item}', [ProjectSpecialModuleController::class, 'destroyKademeModule']);
    Route::put('/projects/{id}/special-modules/kademe-module-enrollments/{enrollment}', [ProjectSpecialModuleController::class, 'updateKademeModuleEnrollment']);
    Route::get('/projects/{id}/content', [ProjectContentController::class, 'show']);
    Route::put('/projects/{id}/content', [ProjectContentController::class, 'update']);
    Route::get('/projects/{id}/application-form', [ProjectContentController::class, 'applicationForm']);
    Route::put('/projects/{id}/application-form', [ProjectContentController::class, 'updateApplicationForm']);

    Route::get('/trainers/export', [TrainerController::class, 'export']);
    Route::get('/trainers', [TrainerController::class, 'index']);
    Route::post('/trainers', [TrainerController::class, 'store']);
    Route::get('/trainers/{id}', [TrainerController::class, 'show']);
    Route::put('/trainers/{id}', [TrainerController::class, 'update']);
    Route::patch('/trainers/{id}/comment', [TrainerController::class, 'updateComment']);
    Route::post('/trainers/{id}/email', [TrainerController::class, 'sendEmail']);
    Route::delete('/trainers/{id}', [TrainerController::class, 'destroy']);
    Route::get('/digital-bohca', [DigitalBohcaController::class, 'panelIndex']);
    Route::get('/digital-bohca/export', [DigitalBohcaController::class, 'panelExport']);
    Route::post('/digital-bohca', [DigitalBohcaController::class, 'panelStore']);
    Route::get('/digital-bohca/{id}/download', [DigitalBohcaController::class, 'panelDownload']);
    Route::delete('/digital-bohca/{id}', [DigitalBohcaController::class, 'panelDestroy']);

    Route::get('/assignments', [AssignmentController::class, 'panelIndex']);
    Route::get('/assignments/export', [AssignmentController::class, 'panelExport']);
    Route::post('/assignments', [AssignmentController::class, 'panelStore']);
    Route::put('/assignments/{id}', [AssignmentController::class, 'panelUpdate']);
    Route::delete('/assignments/{id}', [AssignmentController::class, 'panelDestroy']);
    Route::get('/assignment-submissions/{id}/download', [AssignmentController::class, 'panelDownloadSubmission']);
    Route::get('/assignment-attachments/{id}/download', [AssignmentController::class, 'panelDownloadAttachment']);
    Route::put('/assignment-submissions/{id}/review', [AssignmentController::class, 'panelReviewSubmission']);

    Route::get('/kpd/appointments', [AdminKpdController::class, 'index']);
    Route::post('/kpd/appointments', [AdminKpdController::class, 'store']);
    Route::put('/kpd/appointments/{id}/status', [AdminKpdController::class, 'updateStatus']);
    Route::get('/kpd/options', [AdminKpdController::class, 'options']);
    Route::get('/kpd/reports', [AdminKpdController::class, 'reports']);
    Route::post('/kpd/reports', [AdminKpdController::class, 'storeReport']);
    Route::get('/kpd/reports/{id}/download', [AdminKpdController::class, 'downloadReport']);
    Route::delete('/kpd/reports/{id}', [AdminKpdController::class, 'destroyReport']);

    Route::get('/calendar/overview', [CalendarController::class, 'overview']);
    Route::get('/calendar/assignees', [CalendarController::class, 'assignees']);
    Route::get('/calendar/google/status', [CalendarController::class, 'googleStatus']);
    Route::get('/calendar/google/connect', [CalendarController::class, 'googleConnect']);
    Route::post('/calendar/google/sync', [CalendarController::class, 'googleSync']);
    Route::get('/calendar/export', [CalendarController::class, 'export']);
    Route::post('/calendar/meetings', [CalendarController::class, 'storeMeeting']);
    Route::put('/calendar/meetings/{id}/assignments', [CalendarController::class, 'updateMeetingAssignments']);
    Route::put('/calendar/programs/{id}/assignments', [CalendarController::class, 'updateAssignments']);

    // Dashboard
    Route::get('/dashboard/stats', [AdminDashboardController::class, 'stats']);
    Route::get('/dashboard/activity-logs', [AdminDashboardController::class, 'activityLogs']);
    Route::get('/dashboard/activity-logs/export', [AdminDashboardController::class, 'exportActivityLogs']);
    Route::get('/dashboard/credit-risk/export', [AdminDashboardController::class, 'exportCreditRisk']);

    // Announcements
    Route::post('/announcements/send-sms', [AnnouncementController::class, 'sendSms']);
    Route::post('/announcements/send-email', [AnnouncementController::class, 'sendEmail']);
    Route::get('/announcements/communication-logs', [AnnouncementController::class, 'communicationLogs']);
    Route::get('/announcements/communication-logs/export', [AnnouncementController::class, 'exportCommunicationLogs']);
    Route::get('/announcements/communication-logs/{id}/attachment', [AnnouncementController::class, 'downloadCommunicationAttachment']);
    Route::get('/motivation/lists', [MotivationController::class, 'index']);
    Route::post('/motivation/lists', [MotivationController::class, 'storeList']);
    Route::put('/motivation/lists/{id}', [MotivationController::class, 'updateList']);
    Route::delete('/motivation/lists/{id}', [MotivationController::class, 'destroyList']);
    Route::post('/motivation/lists/{id}/quotes', [MotivationController::class, 'storeQuote']);
    Route::put('/motivation/quotes/{id}', [MotivationController::class, 'updateQuote']);
    Route::delete('/motivation/quotes/{id}', [MotivationController::class, 'destroyQuote']);
    Route::get('/announcements', [AnnouncementController::class, 'index']);
    Route::get('/announcements/export', [AnnouncementController::class, 'export']);
    Route::post('/announcements', [AnnouncementController::class, 'store']);
    Route::get('/announcements/{id}', [AnnouncementController::class, 'show']);
    Route::put('/announcements/{id}', [AnnouncementController::class, 'update']);
    Route::delete('/announcements/{id}', [AnnouncementController::class, 'destroy']);
    Route::get('/inbox/messages', [InboxController::class, 'recipientMessages']);
    Route::put('/inbox/messages/state', [InboxController::class, 'upsertState']);
    Route::get('/forum/posts', [ForumController::class, 'panelIndex']);
    // -- SOSYAL MEDYA PAYLASIM WEBHOOK
    Route::post('/social-sharing/post', [SocialSharingController::class, 'post']);

    Route::get('/alumni-opportunities', [AlumniOpportunityController::class, 'panelIndex']);
    Route::get('/alumni-opportunities/{id}', [AlumniOpportunityController::class, 'panelShow']);
    Route::post('/alumni-opportunities', [AlumniOpportunityController::class, 'panelStore']);
    Route::put('/alumni-opportunities/{id}', [AlumniOpportunityController::class, 'panelUpdate']);
    Route::delete('/alumni-opportunities/{id}', [AlumniOpportunityController::class, 'panelDestroy']);

    // Users & permissions matrix
    Route::get('/users/export', [UserController::class, 'exportUsers']);
    Route::get('/users/create-options', [UserController::class, 'createOptions']);
    Route::post('/users', [UserController::class, 'storeUser']);
    Route::get('/users', [UserController::class, 'index']);
    Route::get('/kvkk/forget-requests', [UserController::class, 'listKvkkForgetRequests']);
    Route::post('/kvkk/forget-requests/{id}/resolve', [UserController::class, 'resolveKvkkForgetRequest']);
    Route::put('/users/{id}/coordinated-projects', [UserController::class, 'syncCoordinatedProjects']);
    Route::get('/users/{id}', [UserController::class, 'showUser']);
    Route::put('/users/{id}', [UserController::class, 'updateUser']);
    Route::get('/permissions-matrix', [PermissionMatrixController::class, 'index']);
    Route::put('/permissions-matrix', [PermissionMatrixController::class, 'update']);
    Route::get('/permissions-matrix/audit', [PermissionMatrixController::class, 'audit']);
    Route::get('/permissions-matrix/users', [PermissionMatrixController::class, 'users']);
    Route::get('/permissions-matrix/users/{id}', [PermissionMatrixController::class, 'showUserOverrides']);
    Route::put('/permissions-matrix/users/{id}', [PermissionMatrixController::class, 'updateUserOverrides']);
    Route::put('/permissions-matrix/users/{id}/roles', [PermissionMatrixController::class, 'assignUserRoles']);
    Route::get('/permissions-matrix/roles', [PermissionMatrixController::class, 'roleCatalog']);
    Route::post('/permissions-matrix/roles', [PermissionMatrixController::class, 'createRole']);
    Route::put('/permissions-matrix/roles/{id}', [PermissionMatrixController::class, 'updateRole']);
    Route::delete('/permissions-matrix/roles/{id}', [PermissionMatrixController::class, 'deleteRole']);

    // Support center
    Route::get('/support/tickets', [SupportTicketController::class, 'index']);
    Route::get('/support/assignable-users', [SupportTicketController::class, 'assignableUsers']);
    Route::get('/support/tickets/export', [SupportTicketController::class, 'export']);
    Route::post('/support/tickets/{id}/reply', [SupportTicketController::class, 'reply']);
    Route::get('/support/tickets/{id}/attachment', [SupportTicketController::class, 'downloadTicketAttachment']);
    Route::get('/support/tickets/replies/{id}/attachment', [SupportTicketController::class, 'downloadReplyAttachment']);
    Route::put('/support/tickets/{id}/assign', [SupportTicketController::class, 'assign']);
    Route::put('/support/tickets/{id}/close', [SupportTicketController::class, 'close']);

    // Requests
    Route::get('/requests', [RequestController::class, 'index']);
    Route::get('/requests/export', [RequestController::class, 'export']);
    Route::post('/requests', [RequestController::class, 'store']);
    Route::put('/requests/{id}/status', [RequestController::class, 'updateStatus']);
    Route::post('/requests/{id}/upload-response', [RequestController::class, 'uploadResponseFile']);
    Route::get('/requests/{id}/response-file', [RequestController::class, 'downloadResponseFile']);

    // Staff management
    Route::get('/staff/export', [StaffController::class, 'export']);
    Route::get('/staff/active', [StaffController::class, 'active']);
    Route::get('/staff/create-options', [StaffController::class, 'createOptions']);
    Route::post('/staff', [StaffController::class, 'store']);
    Route::get('/staff', [StaffController::class, 'index']);
    Route::get('/staff/{id}', [StaffController::class, 'show']);
    Route::put('/staff/{id}', [StaffController::class, 'update']);
    Route::put('/staff/{id}/projects', [StaffController::class, 'syncProjects']);
    Route::post('/staff/{id}/documents', [StaffController::class, 'uploadDocument']);
    Route::get('/leave-requests', [StaffController::class, 'leaveRequests']);
    Route::get('/leave-requests/export', [StaffController::class, 'exportLeaveRequests']);
    Route::put('/leave-requests/{id}/approve', [StaffController::class, 'approveLeave']);
    Route::put('/leave-requests/{id}/reject', [StaffController::class, 'rejectLeave']);

    // Newsletter
    Route::get('/newsletter/subscribers', [NewsletterController::class, 'adminSubscribers']);
    Route::get('/newsletter/subscribers/export', [NewsletterController::class, 'exportSubscribers']);

    // Site settings & media
    Route::get('/site-settings', [SiteSettingsController::class, 'admin']);
    Route::put('/site-settings', [SiteSettingsController::class, 'update']);
    Route::post('/media/upload', [MediaUploadController::class, 'store']);
    Route::post('/chatbot/query', [AdminChatbotController::class, 'query']);
    Route::get('/chatbot/export/{token}', [AdminChatbotController::class, 'export']);

    // Content
    Route::get('/content', [ContentManagementController::class, 'index']);
    Route::get('/content/blogs/export', [ContentManagementController::class, 'exportBlogs']);
    Route::get('/content/faqs/export', [ContentManagementController::class, 'exportFaqs']);
    Route::post('/content/blogs', [ContentManagementController::class, 'storeBlog']);
    Route::put('/content/blogs/{id}', [ContentManagementController::class, 'updateBlog']);
    Route::delete('/content/blogs/{id}', [ContentManagementController::class, 'deleteBlog']);
    Route::post('/content/faqs', [ContentManagementController::class, 'storeFaq']);
    Route::put('/content/faqs/{id}', [ContentManagementController::class, 'updateFaq']);
    Route::delete('/content/faqs/{id}', [ContentManagementController::class, 'deleteFaq']);
    Route::get('/personality-test-templates', [PersonalityTestTemplateController::class, 'index']);
    Route::post('/personality-test-templates', [PersonalityTestTemplateController::class, 'store']);
    Route::put('/personality-test-templates/{id}', [PersonalityTestTemplateController::class, 'update']);
    Route::post('/personality-test-templates/{id}/activate', [PersonalityTestTemplateController::class, 'activate']);
    Route::delete('/personality-test-templates/{id}', [PersonalityTestTemplateController::class, 'destroy']);

    // Certificates
    Route::get('/certificates', [AdminCertificateController::class, 'index']);
    Route::get('/certificates/export', [AdminCertificateController::class, 'export']);
    Route::post('/certificates', [AdminCertificateController::class, 'store']);
    Route::delete('/certificates/{id}', [AdminCertificateController::class, 'destroy']);

    // Financials (admin/coordinator/staff visibility follows controller/middleware rules)
    Route::get('/financials/export', [FinancialTransactionController::class, 'export']);
    Route::get('/financials', [FinancialTransactionController::class, 'index']);
    Route::post('/financials', [FinancialTransactionController::class, 'store']);
    Route::get('/financials/{id}', [FinancialTransactionController::class, 'show']);
    Route::put('/financials/{id}/approve', [FinancialTransactionController::class, 'approve']);
    Route::put('/financials/{id}/reject', [FinancialTransactionController::class, 'reject']);
    Route::put('/financials/{id}/pay', [FinancialTransactionController::class, 'markPaid']);
    Route::delete('/financials/{id}', [FinancialTransactionController::class, 'destroy']);
    Route::get('/financials/{id}/invoice', [FinancialTransactionController::class, 'downloadInvoice']);

    // Unified aliases for role-specific pages under /panel/*
    Route::get('/participants', [CoordinatorParticipantController::class, 'index']);
    Route::get('/participants/export', [CoordinatorParticipantController::class, 'export']);
    Route::get('/participants/{id}/cv', [CoordinatorParticipantController::class, 'cv']);
    Route::get('/participants/{id}/cv/pdf', [CoordinatorParticipantController::class, 'cvPdf']);
    Route::post('/participants/bulk-graduation', [CoordinatorParticipantController::class, 'bulkUpdateGraduationStatus']);
    Route::patch('/participants/{id}/graduation', [CoordinatorParticipantController::class, 'updateGraduationStatus']);
    Route::patch('/participants/{id}/public-visibility', [CoordinatorParticipantController::class, 'updatePublicVisibility']);
    Route::get('/members', [StaffController::class, 'unitMembers']);
    Route::get('/members/export', [StaffController::class, 'exportUnitMembers']);
    Route::get('/my-projects', [StaffController::class, 'myProjects']);
    Route::get('/my-projects/export', [StaffController::class, 'exportMyProjects']);
});

// ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ KOORDÃƒÆ’Ã¢â‚¬ÂÃƒâ€šÃ‚Â°NATÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Å“R ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Å“ZEL (sadece coordinator) ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬
Route::middleware(['auth:sanctum', 'blacklist', 'password.not_pending_setup', 'audit.action'])->group(function () {
    // KoordinatÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¶rÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¼n mali iÃƒÆ’Ã¢â‚¬Â¦Ãƒâ€¦Ã‚Â¸lemleri (kendi projesi)
    Route::get('/coordinator/financials', [FinancialTransactionController::class, 'myFinancials']);
    Route::get('/coordinator/financials/export', [FinancialTransactionController::class, 'exportMyFinancials']);
    Route::post('/coordinator/financials', [FinancialTransactionController::class, 'store']);
    Route::get('/coordinator/participants', [CoordinatorParticipantController::class, 'index']);
    Route::get('/coordinator/participants/export', [CoordinatorParticipantController::class, 'export']);
    Route::get('/coordinator/participants/{id}/cv', [CoordinatorParticipantController::class, 'cv']);
    Route::get('/coordinator/participants/{id}/cv/pdf', [CoordinatorParticipantController::class, 'cvPdf']);
    Route::post('/coordinator/participants/bulk-graduation', [CoordinatorParticipantController::class, 'bulkUpdateGraduationStatus']);
    Route::patch('/coordinator/participants/{id}/graduation', [CoordinatorParticipantController::class, 'updateGraduationStatus']);
    Route::patch('/coordinator/participants/{id}/public-visibility', [CoordinatorParticipantController::class, 'updatePublicVisibility']);
});

// ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ PERSONEL / KOORDÃƒÆ’Ã¢â‚¬ÂÃƒâ€šÃ‚Â°NATÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Å“R (ÃƒÆ’Ã¢â‚¬ÂÃƒâ€šÃ‚Â°zin Talepleri) ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã‚ÂÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬
Route::middleware(['auth:sanctum', 'blacklist', 'password.not_pending_setup', 'audit.action'])->group(function () {
    Route::post('/leave-requests', [StaffController::class, 'storeLeaveRequest']);
    Route::get('/my-leave-requests', [StaffController::class, 'myLeaveRequests']);
});

Route::middleware(['auth:sanctum', 'blacklist', 'password.not_pending_setup', 'audit.action'])->group(function () {
    Route::get('/staff/announcements', [AnnouncementController::class, 'myAnnouncements']);
    Route::get('/staff/announcements/export', [AnnouncementController::class, 'exportMyAnnouncements']);
    Route::get('/staff/applications', [AdminApplicationController::class, 'staffIndex']);
    Route::get('/staff/applications/export', [AdminApplicationController::class, 'staffExport']);
    Route::put('/staff/applications/{id}/status', [AdminApplicationController::class, 'staffUpdateStatus']);
    Route::get('/staff/members', [StaffController::class, 'unitMembers']);
    Route::get('/staff/members/export', [StaffController::class, 'exportUnitMembers']);
    Route::get('/staff/projects', [StaffController::class, 'myProjects']);
    Route::get('/staff/projects/export', [StaffController::class, 'exportMyProjects']);
});

Route::middleware(['auth:sanctum', 'blacklist', 'password.not_pending_setup', 'audit.action'])->prefix('calendar')->group(function () {
    Route::get('/overview', [CalendarController::class, 'overview']);
    Route::get('/assignees', [CalendarController::class, 'assignees']);
    Route::get('/google/status', [CalendarController::class, 'googleStatus']);
    Route::get('/google/connect', [CalendarController::class, 'googleConnect']);
    Route::post('/google/sync', [CalendarController::class, 'googleSync']);
    Route::post('/meetings', [CalendarController::class, 'storeMeeting']);
    Route::put('/meetings/{id}/assignments', [CalendarController::class, 'updateMeetingAssignments']);
    Route::put('/programs/{id}/assignments', [CalendarController::class, 'updateAssignments']);
});
