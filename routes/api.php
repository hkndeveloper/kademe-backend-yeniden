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
use App\Http\Controllers\Api\AssignmentController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CalendarController;
use App\Http\Controllers\Api\CertificateController;
use App\Http\Controllers\Api\ContentManagementController;
use App\Http\Controllers\Api\CoordinatorParticipantController;
use App\Http\Controllers\Api\DigitalBohcaController;
use App\Http\Controllers\Api\FeedbackController;
use App\Http\Controllers\Api\FinancialTransactionController;
use App\Http\Controllers\Api\ForumController;
use App\Http\Controllers\Api\InboxController;
use App\Http\Controllers\Api\MediaUploadController;
use App\Http\Controllers\Api\NewsletterController;
use App\Http\Controllers\Api\PeriodController;
use App\Http\Controllers\Api\PermissionMatrixController;
use App\Http\Controllers\Api\PersonalityTestController;
use App\Http\Controllers\Api\ProgramController;
use App\Http\Controllers\Api\ProjectContentController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\ProjectSpecialModuleController;
use App\Http\Controllers\Api\Public\PublicContentController;
use App\Http\Controllers\Api\RequestController;
use App\Http\Controllers\Api\SiteSettingsController;
use App\Http\Controllers\Api\StaffController;
use App\Http\Controllers\Api\StudentDashboardController;
use App\Http\Controllers\Api\StudentKpdController;
use App\Http\Controllers\Api\SupportTicketController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\VolunteerController;
use Illuminate\Support\Facades\Route;

// Genel Test Endpoint'i
Route::get('/ping', function () {
    return response()->json(['message' => 'KADEME API is running!']);
});

// --- GENEL Ä°Ã‡ERÄ°K (PUBLIC) --- //
Route::get('/blogs', [PublicContentController::class, 'blogs']);
Route::get('/blogs/{slug}', [PublicContentController::class, 'blogDetail']);
Route::get('/faqs', [PublicContentController::class, 'faqs']);
Route::get('/activities', [PublicContentController::class, 'activities']);
Route::get('/activities/{id}', [PublicContentController::class, 'activityDetail']);
Route::get('/certificates/verify/{verificationCode}', [CertificateController::class, 'verify']);
Route::get('/certificates/{verificationCode}/download', [CertificateController::class, 'download']);
Route::get('/site-config', [SiteSettingsController::class, 'public']);
Route::post('/contact', [SupportTicketController::class, 'storePublic'])
    ->middleware('throttle:10,1');
Route::post('/newsletter/subscribe', [NewsletterController::class, 'subscribe'])
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

// --- KULLANICI & PROFÄ°L --- //
// KVKK onay endpointi haricindekilere 'kvkk' kÄ±sÄ±tlamasÄ± getiriyoruz
Route::middleware(['auth:sanctum', 'blacklist', 'password.not_pending_setup', 'audit.action'])->prefix('user')->group(function () {
    Route::post('/consent-kvkk', [UserController::class, 'consentKvkk']);
    Route::post('/kvkk/forget-request', [UserController::class, 'requestKvkkForget']);

    Route::middleware('kvkk')->group(function () {
        Route::get('/profile', [UserController::class, 'getProfile']);
        Route::put('/profile', [UserController::class, 'updateProfile']);
        Route::post('/change-password', [UserController::class, 'changePassword']);
        Route::get('/personality-test', [PersonalityTestController::class, 'show']);
        Route::post('/personality-test', [PersonalityTestController::class, 'submit']);
    });
});

// --- PROJELER & BAÅVURULAR --- //
Route::prefix('projects')->group(function () {
    // Herkese aÃ§Ä±k (ZiyaretÃ§iler dahil) projeleri listeleme
    Route::get('/', [ProjectController::class, 'index']);
    Route::get('/{slug}', [ProjectController::class, 'show']);
});

// BaÅŸvuru iÅŸlemleri (Oturum gerektirir)
Route::middleware(['auth:sanctum', 'blacklist', 'password.not_pending_setup', 'kvkk', 'audit.action'])->prefix('applications')->group(function () {
    Route::get('/', [ApplicationController::class, 'myApplications']);
    Route::get('/{id}', [ApplicationController::class, 'show']);
    Route::post('/{id}/waitlist-response', [ApplicationController::class, 'respondWaitlistInvitation']);
    Route::post('/', [ApplicationController::class, 'store']);
});

// Public project application endpoint (guest users can apply).
Route::post('/applications/public', [ApplicationController::class, 'storePublic'])
    ->middleware('throttle:10,1');

// --- PROGRAM (ETKÄ°NLÄ°K) & YOKLAMA --- //
Route::middleware(['auth:sanctum', 'blacklist', 'password.not_pending_setup', 'kvkk', 'role:student|alumni', 'audit.action'])->group(function () {

    // Ã–ÄŸrencinin kendi programlarÄ±nÄ± listelemesi
    Route::get('/programs', [ProgramController::class, 'myPrograms']);
    Route::get('/programs/{id}', [ProgramController::class, 'show']);

    // Ã–ÄŸrencinin QR Kod ile yoklama vermesi
    Route::post('/attendances/qr', [AttendanceController::class, 'markQrAttendance']);

    // --- Ã–ÄRENCÄ° PANELÄ° (KREDÄ°, BOHÃ‡A, Ã–DEV) --- //
    Route::get('/dashboard/summary', [StudentDashboardController::class, 'summary']);
    Route::get('/dashboard/projects', [StudentDashboardController::class, 'projects']);
    Route::get('/dashboard/digital-cv', [StudentDashboardController::class, 'digitalCv']);
    Route::get('/dashboard/project-specials', [StudentDashboardController::class, 'projectSpecials']);
    Route::post('/dashboard/projects/{projectId}/kademe-modules/{moduleId}/enroll', [StudentDashboardController::class, 'enrollKademeModule']);
    Route::get('/dashboard/projects/{projectId}/badge-leaderboard', [StudentDashboardController::class, 'badgeLeaderboard']);
    Route::get('/announcements', [AnnouncementController::class, 'recipientAnnouncements']);
    Route::get('/alumni-opportunities', [AlumniOpportunityController::class, 'recipientIndex']);
    Route::get('/forum/posts', [ForumController::class, 'index']);
    Route::post('/forum/posts', [ForumController::class, 'store']);
    Route::post('/forum/posts/{postId}/replies', [ForumController::class, 'reply']);
    Route::get('/inbox/messages', [InboxController::class, 'recipientMessages']);
    Route::put('/inbox/messages/state', [InboxController::class, 'upsertState']);

    Route::get('/digital-bohca', [DigitalBohcaController::class, 'index']);
    Route::get('/digital-bohca/{id}/download', [DigitalBohcaController::class, 'download']);
    Route::get('/certificates', [CertificateController::class, 'index']);
    Route::get('/feedbacks', [FeedbackController::class, 'index']);
    Route::post('/feedbacks', [FeedbackController::class, 'store']);
    Route::get('/requests', [RequestController::class, 'index']);
    Route::get('/requests/export', [RequestController::class, 'export']);
    Route::post('/requests', [RequestController::class, 'store']);
    Route::put('/requests/{id}/status', [RequestController::class, 'updateStatus']);
    Route::post('/requests/{id}/upload-response', [RequestController::class, 'uploadResponseFile']);
    Route::get('/requests/{id}/response-file', [RequestController::class, 'downloadResponseFile']);
    Route::get('/kpd/appointments', [StudentKpdController::class, 'index']);
    Route::post('/kpd/appointments', [StudentKpdController::class, 'store']);
    Route::post('/kpd/appointments/{id}/cancel', [StudentKpdController::class, 'cancel']);
    Route::get('/kpd/reports/{id}/download', [StudentKpdController::class, 'downloadReport']);
    Route::get('/volunteer/opportunities', [VolunteerController::class, 'index']);
    Route::post('/volunteer/opportunities/{id}/apply', [VolunteerController::class, 'apply']);

    Route::get('/assignments', [AssignmentController::class, 'index']);
    Route::post('/assignments/{id}/submit', [AssignmentController::class, 'submit']);
    Route::get('/assignment-submissions/{id}/download', [AssignmentController::class, 'downloadSubmission']);

    // Destek Talepleri (Ã–ÄŸrenci TarafÄ±)
    Route::get('/tickets', [SupportTicketController::class, 'myTickets']);
    Route::get('/tickets/export', [SupportTicketController::class, 'exportMyTickets']);
    Route::post('/tickets', [SupportTicketController::class, 'store']);
    Route::post('/tickets/{id}/reply', [SupportTicketController::class, 'reply']);
    Route::get('/tickets/replies/{id}/attachment', [SupportTicketController::class, 'downloadReplyAttachment']);
});

// --- ADMIN / KOORDÄ°NATÃ–R PANELÄ° --- //
Route::middleware(['auth:sanctum', 'blacklist', 'password.not_pending_setup', 'role:super_admin|coordinator|staff', 'audit.action'])->prefix('admin')->group(function () {

    // Dashboard Ä°statistikleri
    Route::get('/dashboard/stats', [AdminDashboardController::class, 'stats']);
    Route::get('/dashboard/activity-logs', [AdminDashboardController::class, 'activityLogs']);
    Route::get('/dashboard/activity-logs/export', [AdminDashboardController::class, 'exportActivityLogs']);

    // BaÅŸvurularÄ± YÃ¶net
    Route::get('/applications', [AdminApplicationController::class, 'index']);
    Route::get('/applications/export', [AdminApplicationController::class, 'export']);
    Route::get('/applications/{id}/form-files/{field}', [AdminApplicationController::class, 'downloadFormFile']);
    Route::put('/applications/{id}/status', [AdminApplicationController::class, 'updateStatus']);
    Route::put('/applications/{id}/interview', [AdminApplicationController::class, 'planInterview']);
    Route::post('/applications/{id}/waitlist', [AdminApplicationController::class, 'addToWaitlist']);
    Route::put('/applications/{id}/waitlist-order', [AdminApplicationController::class, 'updateWaitlistOrder']);
    Route::post('/applications/{id}/waitlist-invite', [AdminApplicationController::class, 'inviteFromWaitlist']);
    Route::post('/applications/{id}/waitlist-refresh', [AdminApplicationController::class, 'refreshWaitlistInvitations']);

    // Etkinlik (Program) ve QR YÃ¶netimi
    Route::get('/programs', [AdminProgramController::class, 'index']);
    Route::get('/programs/export', [AdminProgramController::class, 'export']);
    Route::post('/programs', [AdminProgramController::class, 'store']);
    Route::put('/programs/{id}', [AdminProgramController::class, 'update']);
    Route::post('/programs/{id}/generate-qr', [AdminProgramController::class, 'generateQr']);
    Route::post('/programs/{id}/complete', [AdminProgramController::class, 'complete']);
    Route::get('/programs/{id}/attendances', [AdminProgramController::class, 'attendanceDetails']);
    Route::put('/programs/{id}/attendances/{participantId}', [AdminProgramController::class, 'markManualAttendance']);
    Route::get('/programs/{id}/attendances/export', [AdminProgramController::class, 'exportAttendanceDetails']);

    // Kredi (Puan) ve Rozet YÃ¶netimi
    Route::post('/credits/adjust', [AdminCreditController::class, 'adjustCredit']);
    Route::post('/badges/award', [AdminCreditController::class, 'awardBadge']);
    // Sertifika Yönetimi
    Route::get('/certificates', [AdminCertificateController::class, 'index']);
    Route::get('/certificates/export', [AdminCertificateController::class, 'export']);
    Route::post('/certificates', [AdminCertificateController::class, 'store']);
    Route::delete('/certificates/{id}', [AdminCertificateController::class, 'destroy']);
    Route::get('/projects/manageable', [ProjectContentController::class, 'manageable']);
    Route::get('/projects/export', [ProjectContentController::class, 'exportManageable']);
    Route::get('/projects/{id}/modules', [ProjectContentController::class, 'modules']);
    Route::get('/projects/{id}/special-modules', [ProjectSpecialModuleController::class, 'index']);
    Route::post('/projects/{id}/special-modules/internships', [ProjectSpecialModuleController::class, 'storeInternship']);
    Route::put('/projects/{id}/special-modules/internships/{item}', [ProjectSpecialModuleController::class, 'updateInternship']);
    Route::delete('/projects/{id}/special-modules/internships/{item}', [ProjectSpecialModuleController::class, 'destroyInternship']);
    Route::post('/projects/{id}/special-modules/mentors', [ProjectSpecialModuleController::class, 'storeMentor']);
    Route::put('/projects/{id}/special-modules/mentors/{item}', [ProjectSpecialModuleController::class, 'updateMentor']);
    Route::delete('/projects/{id}/special-modules/mentors/{item}', [ProjectSpecialModuleController::class, 'destroyMentor']);
    Route::post('/projects/{id}/special-modules/eurodesk-projects', [ProjectSpecialModuleController::class, 'storeEurodeskProject']);
    Route::put('/projects/{id}/special-modules/eurodesk-projects/{item}', [ProjectSpecialModuleController::class, 'updateEurodeskProject']);
    Route::delete('/projects/{id}/special-modules/eurodesk-projects/{item}', [ProjectSpecialModuleController::class, 'destroyEurodeskProject']);
    Route::post('/projects/{id}/special-modules/reward-tiers', [ProjectSpecialModuleController::class, 'storeRewardTier']);
    Route::put('/projects/{id}/special-modules/reward-tiers/{item}', [ProjectSpecialModuleController::class, 'updateRewardTier']);
    Route::delete('/projects/{id}/special-modules/reward-tiers/{item}', [ProjectSpecialModuleController::class, 'destroyRewardTier']);
    Route::post('/projects/{id}/special-modules/reward-awards', [ProjectSpecialModuleController::class, 'storeRewardAward']);
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
    Route::put('/periods/{id}', [PeriodController::class, 'update']);

    // KPD YÃ¶netimi
    Route::get('/kpd/appointments', [AdminKpdController::class, 'index']);
    Route::post('/kpd/appointments', [AdminKpdController::class, 'store']);
    Route::put('/kpd/appointments/{id}/status', [AdminKpdController::class, 'updateStatus']);
    Route::get('/kpd/options', [AdminKpdController::class, 'options']);
    Route::get('/kpd/reports', [AdminKpdController::class, 'reports']);
    Route::post('/kpd/reports', [AdminKpdController::class, 'storeReport']);
    Route::get('/kpd/reports/{id}/download', [AdminKpdController::class, 'downloadReport']);
    Route::delete('/kpd/reports/{id}', [AdminKpdController::class, 'destroyReport']);

    // Site AyarlarÄ± & Ä°Ã§erik
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

    Route::get('/newsletter/subscribers', [NewsletterController::class, 'adminSubscribers']);
    Route::get('/newsletter/subscribers/export', [NewsletterController::class, 'exportSubscribers']);

    // â”€â”€ MALÄ° Ä°ÅLEMLER â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    Route::get('/financials/export', [FinancialTransactionController::class, 'export']);
    Route::get('/financials', [FinancialTransactionController::class, 'index']);
    Route::post('/financials', [FinancialTransactionController::class, 'store']);
    Route::get('/financials/{id}', [FinancialTransactionController::class, 'show']);
    Route::put('/financials/{id}/approve', [FinancialTransactionController::class, 'approve']);
    Route::put('/financials/{id}/reject', [FinancialTransactionController::class, 'reject']);
    Route::put('/financials/{id}/pay', [FinancialTransactionController::class, 'markPaid']);
    Route::delete('/financials/{id}', [FinancialTransactionController::class, 'destroy']);
    Route::get('/financials/{id}/invoice', [FinancialTransactionController::class, 'downloadInvoice']);

    // â”€â”€ PERSONEL YÃ–NETÄ°MÄ° â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    Route::get('/staff/export', [StaffController::class, 'export']);
    Route::get('/staff/active', [StaffController::class, 'active']);
    Route::get('/staff/create-options', [StaffController::class, 'createOptions']);
    Route::post('/staff', [StaffController::class, 'store']);
    Route::get('/staff', [StaffController::class, 'index']);
    Route::get('/staff/{id}', [StaffController::class, 'show']);
    Route::put('/staff/{id}', [StaffController::class, 'update']);
    Route::put('/staff/{id}/projects', [StaffController::class, 'syncProjects']);
    Route::post('/staff/{id}/documents', [StaffController::class, 'uploadDocument']);

    // â”€â”€ Ä°ZÄ°N TALEPLERÄ° (Admin gÃ¶rÃ¼nÃ¼mÃ¼) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    // â”€â”€ DUYURULAR â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    Route::post('/announcements/send-sms', [AnnouncementController::class, 'sendSms']);
    Route::post('/announcements/send-email', [AnnouncementController::class, 'sendEmail']);
    Route::get('/announcements/communication-logs', [AnnouncementController::class, 'communicationLogs']);
    Route::get('/announcements/communication-logs/export', [AnnouncementController::class, 'exportCommunicationLogs']);
    Route::get('/announcements/communication-logs/{id}/attachment', [AnnouncementController::class, 'downloadCommunicationAttachment']);
    Route::get('/announcements', [AnnouncementController::class, 'index']);
    Route::get('/announcements/export', [AnnouncementController::class, 'export']);
    Route::post('/announcements', [AnnouncementController::class, 'store']);
    Route::get('/announcements/{id}', [AnnouncementController::class, 'show']);
    Route::put('/announcements/{id}', [AnnouncementController::class, 'update']);
    Route::delete('/announcements/{id}', [AnnouncementController::class, 'destroy']);
    Route::get('/inbox/messages', [InboxController::class, 'recipientMessages']);
    Route::put('/inbox/messages/state', [InboxController::class, 'upsertState']);

    // â”€â”€ KULLANICI YÃ–NETÄ°MÄ° â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
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

    // â”€â”€ DESTEK MERKEZÄ° (Admin) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    Route::get('/support/tickets', [SupportTicketController::class, 'index']);
    Route::get('/support/assignable-users', [SupportTicketController::class, 'assignableUsers']);
    Route::get('/support/tickets/export', [SupportTicketController::class, 'export']);
    Route::put('/support/tickets/{id}/assign', [SupportTicketController::class, 'assign']);
    Route::put('/support/tickets/{id}/close', [SupportTicketController::class, 'close']);
    Route::get('/tickets/replies/{id}/attachment', [SupportTicketController::class, 'downloadReplyAttachment']);
});

// Unified panel icin rol-prefix bagimsiz generic alias endpointleri.
// /admin/* endpointleri geriye donuk uyumluluk icin oldugu gibi korunur.
Route::middleware(['auth:sanctum', 'blacklist', 'password.not_pending_setup', 'audit.action'])->prefix('panel')->group(function () {
    Route::get('/programs', [AdminProgramController::class, 'index']);
    Route::get('/programs/export', [AdminProgramController::class, 'export']);
    Route::post('/programs', [AdminProgramController::class, 'store']);
    Route::put('/programs/{id}', [AdminProgramController::class, 'update']);
    Route::post('/programs/{id}/complete', [AdminProgramController::class, 'complete']);
    Route::post('/programs/{id}/generate-qr', [AdminProgramController::class, 'generateQr']);
    Route::get('/programs/{id}/attendances', [AdminProgramController::class, 'attendanceDetails']);
    Route::put('/programs/{id}/attendances/{participantId}', [AdminProgramController::class, 'markManualAttendance']);
    Route::get('/programs/{id}/attendances/export', [AdminProgramController::class, 'exportAttendanceDetails']);

    Route::get('/applications', [AdminApplicationController::class, 'index']);
    Route::get('/applications/export', [AdminApplicationController::class, 'export']);
    Route::get('/applications/{id}/form-files/{field}', [AdminApplicationController::class, 'downloadFormFile']);
    Route::put('/applications/{id}/status', [AdminApplicationController::class, 'updateStatus']);
    Route::put('/applications/{id}/interview', [AdminApplicationController::class, 'planInterview']);
    Route::post('/applications/{id}/waitlist', [AdminApplicationController::class, 'addToWaitlist']);
    Route::put('/applications/{id}/waitlist-order', [AdminApplicationController::class, 'updateWaitlistOrder']);
    Route::post('/applications/{id}/waitlist-invite', [AdminApplicationController::class, 'inviteFromWaitlist']);
    Route::post('/applications/{id}/waitlist-refresh', [AdminApplicationController::class, 'refreshWaitlistInvitations']);

    Route::get('/volunteer/opportunities', [VolunteerController::class, 'panelIndex']);
    Route::get('/volunteer/opportunities/export', [VolunteerController::class, 'panelExport']);
    Route::post('/volunteer/opportunities', [VolunteerController::class, 'panelStore']);
    Route::put('/volunteer/applications/{id}', [VolunteerController::class, 'panelUpdateApplication']);
    Route::delete('/volunteer/opportunities/{id}', [VolunteerController::class, 'panelDestroy']);

    Route::get('/periods', [PeriodController::class, 'index']);
    Route::get('/periods/export', [PeriodController::class, 'export']);
    Route::post('/periods', [PeriodController::class, 'store']);
    Route::put('/periods/{id}', [PeriodController::class, 'update']);

    Route::get('/projects/manageable', [ProjectContentController::class, 'manageable']);
    Route::get('/projects/export', [ProjectContentController::class, 'exportManageable']);
    Route::get('/projects/{id}/modules', [ProjectContentController::class, 'modules']);
    Route::get('/projects/{id}/special-modules', [ProjectSpecialModuleController::class, 'index']);
    Route::post('/projects/{id}/special-modules/internships', [ProjectSpecialModuleController::class, 'storeInternship']);
    Route::put('/projects/{id}/special-modules/internships/{item}', [ProjectSpecialModuleController::class, 'updateInternship']);
    Route::delete('/projects/{id}/special-modules/internships/{item}', [ProjectSpecialModuleController::class, 'destroyInternship']);
    Route::post('/projects/{id}/special-modules/mentors', [ProjectSpecialModuleController::class, 'storeMentor']);
    Route::put('/projects/{id}/special-modules/mentors/{item}', [ProjectSpecialModuleController::class, 'updateMentor']);
    Route::delete('/projects/{id}/special-modules/mentors/{item}', [ProjectSpecialModuleController::class, 'destroyMentor']);
    Route::post('/projects/{id}/special-modules/eurodesk-projects', [ProjectSpecialModuleController::class, 'storeEurodeskProject']);
    Route::put('/projects/{id}/special-modules/eurodesk-projects/{item}', [ProjectSpecialModuleController::class, 'updateEurodeskProject']);
    Route::delete('/projects/{id}/special-modules/eurodesk-projects/{item}', [ProjectSpecialModuleController::class, 'destroyEurodeskProject']);
    Route::post('/projects/{id}/special-modules/reward-tiers', [ProjectSpecialModuleController::class, 'storeRewardTier']);
    Route::put('/projects/{id}/special-modules/reward-tiers/{item}', [ProjectSpecialModuleController::class, 'updateRewardTier']);
    Route::delete('/projects/{id}/special-modules/reward-tiers/{item}', [ProjectSpecialModuleController::class, 'destroyRewardTier']);
    Route::post('/projects/{id}/special-modules/reward-awards', [ProjectSpecialModuleController::class, 'storeRewardAward']);
    Route::delete('/projects/{id}/special-modules/reward-awards/{item}', [ProjectSpecialModuleController::class, 'destroyRewardAward']);
    Route::post('/projects/{id}/special-modules/kademe-modules', [ProjectSpecialModuleController::class, 'storeKademeModule']);
    Route::put('/projects/{id}/special-modules/kademe-modules/{item}', [ProjectSpecialModuleController::class, 'updateKademeModule']);
    Route::delete('/projects/{id}/special-modules/kademe-modules/{item}', [ProjectSpecialModuleController::class, 'destroyKademeModule']);
    Route::put('/projects/{id}/special-modules/kademe-module-enrollments/{enrollment}', [ProjectSpecialModuleController::class, 'updateKademeModuleEnrollment']);
    Route::get('/projects/{id}/content', [ProjectContentController::class, 'show']);
    Route::put('/projects/{id}/content', [ProjectContentController::class, 'update']);
    Route::get('/projects/{id}/application-form', [ProjectContentController::class, 'applicationForm']);
    Route::put('/projects/{id}/application-form', [ProjectContentController::class, 'updateApplicationForm']);

    Route::get('/digital-bohca', [DigitalBohcaController::class, 'panelIndex']);
    Route::get('/digital-bohca/export', [DigitalBohcaController::class, 'panelExport']);
    Route::post('/digital-bohca', [DigitalBohcaController::class, 'panelStore']);
    Route::get('/digital-bohca/{id}/download', [DigitalBohcaController::class, 'panelDownload']);
    Route::delete('/digital-bohca/{id}', [DigitalBohcaController::class, 'panelDestroy']);

    Route::get('/assignments', [AssignmentController::class, 'panelIndex']);
    Route::get('/assignments/export', [AssignmentController::class, 'panelExport']);
    Route::post('/assignments', [AssignmentController::class, 'panelStore']);
    Route::delete('/assignments/{id}', [AssignmentController::class, 'panelDestroy']);
    Route::get('/assignment-submissions/{id}/download', [AssignmentController::class, 'panelDownloadSubmission']);
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
    Route::put('/calendar/programs/{id}/assignments', [CalendarController::class, 'updateAssignments']);

    // Dashboard
    Route::get('/dashboard/stats', [AdminDashboardController::class, 'stats']);
    Route::get('/dashboard/activity-logs', [AdminDashboardController::class, 'activityLogs']);
    Route::get('/dashboard/activity-logs/export', [AdminDashboardController::class, 'exportActivityLogs']);

    // Announcements
    Route::post('/announcements/send-sms', [AnnouncementController::class, 'sendSms']);
    Route::post('/announcements/send-email', [AnnouncementController::class, 'sendEmail']);
    Route::get('/announcements/communication-logs', [AnnouncementController::class, 'communicationLogs']);
    Route::get('/announcements/communication-logs/export', [AnnouncementController::class, 'exportCommunicationLogs']);
    Route::get('/announcements/communication-logs/{id}/attachment', [AnnouncementController::class, 'downloadCommunicationAttachment']);
    Route::get('/announcements', [AnnouncementController::class, 'index']);
    Route::get('/announcements/export', [AnnouncementController::class, 'export']);
    Route::post('/announcements', [AnnouncementController::class, 'store']);
    Route::get('/announcements/{id}', [AnnouncementController::class, 'show']);
    Route::put('/announcements/{id}', [AnnouncementController::class, 'update']);
    Route::delete('/announcements/{id}', [AnnouncementController::class, 'destroy']);
    Route::get('/inbox/messages', [InboxController::class, 'recipientMessages']);
    Route::put('/inbox/messages/state', [InboxController::class, 'upsertState']);

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
    Route::post('/participants/bulk-graduation', [CoordinatorParticipantController::class, 'bulkUpdateGraduationStatus']);
    Route::patch('/participants/{id}/graduation', [CoordinatorParticipantController::class, 'updateGraduationStatus']);
    Route::get('/members', [StaffController::class, 'unitMembers']);
    Route::get('/members/export', [StaffController::class, 'exportUnitMembers']);
    Route::get('/my-projects', [StaffController::class, 'myProjects']);
    Route::get('/my-projects/export', [StaffController::class, 'exportMyProjects']);
});

// â”€â”€ KOORDÄ°NATÃ–R Ã–ZEL (sadece coordinator) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
Route::middleware(['auth:sanctum', 'blacklist', 'password.not_pending_setup', 'audit.action'])->group(function () {
    // KoordinatÃ¶rÃ¼n mali iÅŸlemleri (kendi projesi)
    Route::get('/coordinator/financials', [FinancialTransactionController::class, 'myFinancials']);
    Route::get('/coordinator/financials/export', [FinancialTransactionController::class, 'exportMyFinancials']);
    Route::post('/coordinator/financials', [FinancialTransactionController::class, 'store']);
    Route::get('/coordinator/participants', [CoordinatorParticipantController::class, 'index']);
    Route::get('/coordinator/participants/export', [CoordinatorParticipantController::class, 'export']);
    Route::post('/coordinator/participants/bulk-graduation', [CoordinatorParticipantController::class, 'bulkUpdateGraduationStatus']);
    Route::patch('/coordinator/participants/{id}/graduation', [CoordinatorParticipantController::class, 'updateGraduationStatus']);
});

// â”€â”€ PERSONEL / KOORDÄ°NATÃ–R (Ä°zin Talepleri) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
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
});

Route::middleware(['auth:sanctum', 'blacklist', 'password.not_pending_setup', 'audit.action'])->prefix('calendar')->group(function () {
    Route::put('/programs/{id}/assignments', [CalendarController::class, 'updateAssignments']);
});
