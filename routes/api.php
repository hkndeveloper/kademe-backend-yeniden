<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;

// Genel Test Endpoint'i
Route::get('/ping', function () {
    return response()->json(['message' => 'KADEME API is running!']);
});

// --- GENEL Ä°Ã‡ERÄ°K (PUBLIC) --- //
Route::get('/blogs', [\App\Http\Controllers\Api\Public\PublicContentController::class, 'blogs']);
Route::get('/blogs/{slug}', [\App\Http\Controllers\Api\Public\PublicContentController::class, 'blogDetail']);
Route::get('/faqs', [\App\Http\Controllers\Api\Public\PublicContentController::class, 'faqs']);
Route::get('/activities', [\App\Http\Controllers\Api\Public\PublicContentController::class, 'activities']);
Route::get('/activities/{id}', [\App\Http\Controllers\Api\Public\PublicContentController::class, 'activityDetail']);
Route::get('/certificates/verify/{verificationCode}', [\App\Http\Controllers\Api\CertificateController::class, 'verify']);
Route::get('/site-config', [\App\Http\Controllers\Api\SiteSettingsController::class, 'public']);
Route::post('/contact', [\App\Http\Controllers\Api\SupportTicketController::class, 'storePublic'])
    ->middleware('throttle:10,1');
Route::post('/newsletter/subscribe', [\App\Http\Controllers\Api\NewsletterController::class, 'subscribe'])
    ->middleware('throttle:20,1');

// --- AUTH ROUTLARI --- //
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);

    // Oturum gerektiren Auth iÅŸlemleri
    Route::middleware(['auth:sanctum', 'blacklist'])->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});

// --- KULLANICI & PROFÄ°L --- //
// KVKK onay endpointi haricindekilere 'kvkk' kÄ±sÄ±tlamasÄ± getiriyoruz
Route::middleware(['auth:sanctum', 'blacklist'])->prefix('user')->group(function () {
    Route::post('/consent-kvkk', [\App\Http\Controllers\Api\UserController::class, 'consentKvkk']);
    
    Route::middleware('kvkk')->group(function () {
        Route::get('/profile', [\App\Http\Controllers\Api\UserController::class, 'getProfile']);
        Route::put('/profile', [\App\Http\Controllers\Api\UserController::class, 'updateProfile']);
        Route::post('/change-password', [\App\Http\Controllers\Api\UserController::class, 'changePassword']);
        Route::get('/personality-test', [\App\Http\Controllers\Api\PersonalityTestController::class, 'show']);
        Route::post('/personality-test', [\App\Http\Controllers\Api\PersonalityTestController::class, 'submit']);
    });
});

// --- PROJELER & BAÅVURULAR --- //
Route::prefix('projects')->group(function () {
    // Herkese aÃ§Ä±k (ZiyaretÃ§iler dahil) projeleri listeleme
    Route::get('/', [\App\Http\Controllers\Api\ProjectController::class, 'index']);
    Route::get('/{slug}', [\App\Http\Controllers\Api\ProjectController::class, 'show']);
});

// BaÅŸvuru iÅŸlemleri (Oturum gerektirir)
Route::middleware(['auth:sanctum', 'blacklist', 'kvkk'])->prefix('applications')->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\ApplicationController::class, 'myApplications']);
    Route::get('/{id}', [\App\Http\Controllers\Api\ApplicationController::class, 'show']);
    Route::post('/', [\App\Http\Controllers\Api\ApplicationController::class, 'store']);
});

// Public project application endpoint (guest users can apply).
Route::post('/applications/public', [\App\Http\Controllers\Api\ApplicationController::class, 'storePublic'])
    ->middleware('throttle:10,1');

// --- PROGRAM (ETKÄ°NLÄ°K) & YOKLAMA --- //
Route::middleware(['auth:sanctum', 'blacklist', 'kvkk'])->group(function () {
    
    // Ã–ÄŸrencinin kendi programlarÄ±nÄ± listelemesi
    Route::get('/programs', [\App\Http\Controllers\Api\ProgramController::class, 'myPrograms']);
    Route::get('/programs/{id}', [\App\Http\Controllers\Api\ProgramController::class, 'show']);
    
    // Ã–ÄŸrencinin QR Kod ile yoklama vermesi
    Route::post('/attendances/qr', [\App\Http\Controllers\Api\AttendanceController::class, 'markQrAttendance']);
    
    // --- Ã–ÄRENCÄ° PANELÄ° (KREDÄ°, BOHÃ‡A, Ã–DEV) --- //
    Route::get('/dashboard/summary', [\App\Http\Controllers\Api\StudentDashboardController::class, 'summary']);
    
    Route::get('/digital-bohca', [\App\Http\Controllers\Api\DigitalBohcaController::class, 'index']);
    Route::get('/certificates', [\App\Http\Controllers\Api\CertificateController::class, 'index']);
    Route::get('/feedbacks', [\App\Http\Controllers\Api\FeedbackController::class, 'index']);
    Route::post('/feedbacks', [\App\Http\Controllers\Api\FeedbackController::class, 'store']);
    Route::get('/requests', [\App\Http\Controllers\Api\RequestController::class, 'index']);
    Route::get('/requests/export', [\App\Http\Controllers\Api\RequestController::class, 'export']);
    Route::post('/requests', [\App\Http\Controllers\Api\RequestController::class, 'store']);
    Route::put('/requests/{id}/status', [\App\Http\Controllers\Api\RequestController::class, 'updateStatus']);
    Route::post('/requests/{id}/upload-response', [\App\Http\Controllers\Api\RequestController::class, 'uploadResponseFile']);
    Route::get('/kpd/appointments', [\App\Http\Controllers\Api\StudentKpdController::class, 'index']);
    Route::post('/kpd/appointments', [\App\Http\Controllers\Api\StudentKpdController::class, 'store']);
    Route::post('/kpd/appointments/{id}/cancel', [\App\Http\Controllers\Api\StudentKpdController::class, 'cancel']);
    Route::get('/volunteer/opportunities', [\App\Http\Controllers\Api\VolunteerController::class, 'index']);
    Route::post('/volunteer/opportunities/{id}/apply', [\App\Http\Controllers\Api\VolunteerController::class, 'apply']);
    
    Route::get('/assignments', [\App\Http\Controllers\Api\AssignmentController::class, 'index']);
    Route::post('/assignments/{id}/submit', [\App\Http\Controllers\Api\AssignmentController::class, 'submit']);
    
    // Destek Talepleri (Ã–ÄŸrenci TarafÄ±)
    Route::get('/tickets', [\App\Http\Controllers\Api\SupportTicketController::class, 'myTickets']);
    Route::get('/tickets/export', [\App\Http\Controllers\Api\SupportTicketController::class, 'exportMyTickets']);
    Route::post('/tickets', [\App\Http\Controllers\Api\SupportTicketController::class, 'store']);
    Route::post('/tickets/{id}/reply', [\App\Http\Controllers\Api\SupportTicketController::class, 'reply']);
});

// --- ADMIN / KOORDÄ°NATÃ–R PANELÄ° --- //
Route::middleware(['auth:sanctum', 'blacklist', 'role:super_admin|coordinator|staff', 'audit.action'])->prefix('admin')->group(function () {

    // Dashboard Ä°statistikleri
    Route::get('/dashboard/stats', [\App\Http\Controllers\Api\AdminDashboardController::class, 'stats']);
    Route::get('/dashboard/activity-logs', [\App\Http\Controllers\Api\AdminDashboardController::class, 'activityLogs']);
    Route::get('/dashboard/activity-logs/export', [\App\Http\Controllers\Api\AdminDashboardController::class, 'exportActivityLogs']);

    // BaÅŸvurularÄ± YÃ¶net
    Route::get('/applications', [\App\Http\Controllers\Api\AdminApplicationController::class, 'index']);
    Route::get('/applications/export', [\App\Http\Controllers\Api\AdminApplicationController::class, 'export']);
    Route::put('/applications/{id}/status', [\App\Http\Controllers\Api\AdminApplicationController::class, 'updateStatus']);
    Route::put('/applications/{id}/interview', [\App\Http\Controllers\Api\AdminApplicationController::class, 'planInterview']);
    Route::post('/applications/{id}/waitlist', [\App\Http\Controllers\Api\AdminApplicationController::class, 'addToWaitlist']);

    // Etkinlik (Program) ve QR YÃ¶netimi
    Route::get('/programs', [\App\Http\Controllers\Api\AdminProgramController::class, 'index']);
    Route::get('/programs/export', [\App\Http\Controllers\Api\AdminProgramController::class, 'export']);
    Route::post('/programs', [\App\Http\Controllers\Api\AdminProgramController::class, 'store']);
    Route::put('/programs/{id}', [\App\Http\Controllers\Api\AdminProgramController::class, 'update']);
    Route::post('/programs/{id}/generate-qr', [\App\Http\Controllers\Api\AdminProgramController::class, 'generateQr']);
    Route::post('/programs/{id}/complete', [\App\Http\Controllers\Api\AdminProgramController::class, 'complete']);
    Route::get('/programs/{id}/attendances', [\App\Http\Controllers\Api\AdminProgramController::class, 'attendanceDetails']);
    Route::get('/programs/{id}/attendances/export', [\App\Http\Controllers\Api\AdminProgramController::class, 'exportAttendanceDetails']);

    // Kredi (Puan) ve Rozet YÃ¶netimi
    Route::post('/credits/adjust', [\App\Http\Controllers\Api\AdminCreditController::class, 'adjustCredit']);
    Route::post('/badges/award', [\App\Http\Controllers\Api\AdminCreditController::class, 'awardBadge']);
    // Sertifika Yönetimi
    Route::get('/certificates', [\App\Http\Controllers\Api\AdminCertificateController::class, 'index']);
    Route::get('/certificates/export', [\App\Http\Controllers\Api\AdminCertificateController::class, 'export']);
    Route::post('/certificates', [\App\Http\Controllers\Api\AdminCertificateController::class, 'store']);
    Route::delete('/certificates/{id}', [\App\Http\Controllers\Api\AdminCertificateController::class, 'destroy']);
    Route::get('/projects/manageable', [\App\Http\Controllers\Api\ProjectContentController::class, 'manageable']);
    Route::get('/projects/export', [\App\Http\Controllers\Api\ProjectContentController::class, 'exportManageable']);
    Route::get('/projects/{id}/content', [\App\Http\Controllers\Api\ProjectContentController::class, 'show']);
    Route::put('/projects/{id}/content', [\App\Http\Controllers\Api\ProjectContentController::class, 'update']);
    Route::get('/projects/{id}/application-form', [\App\Http\Controllers\Api\ProjectContentController::class, 'applicationForm']);
    Route::put('/projects/{id}/application-form', [\App\Http\Controllers\Api\ProjectContentController::class, 'updateApplicationForm']);
    Route::get('/periods', [\App\Http\Controllers\Api\PeriodController::class, 'index']);
    Route::get('/periods/export', [\App\Http\Controllers\Api\PeriodController::class, 'export']);
    Route::post('/periods', [\App\Http\Controllers\Api\PeriodController::class, 'store']);
    Route::put('/periods/{id}', [\App\Http\Controllers\Api\PeriodController::class, 'update']);

    // KPD YÃ¶netimi
    Route::get('/kpd/appointments', [\App\Http\Controllers\Api\AdminKpdController::class, 'index']);
    Route::post('/kpd/appointments', [\App\Http\Controllers\Api\AdminKpdController::class, 'store']);

    // Site AyarlarÄ± & Ä°Ã§erik
    Route::get('/site-settings', [\App\Http\Controllers\Api\SiteSettingsController::class, 'admin']);
    Route::put('/site-settings', [\App\Http\Controllers\Api\SiteSettingsController::class, 'update']);
    Route::post('/media/upload', [\App\Http\Controllers\Api\MediaUploadController::class, 'store']);
    Route::post('/chatbot/query', [\App\Http\Controllers\Api\AdminChatbotController::class, 'query']);
    Route::get('/chatbot/export/{token}', [\App\Http\Controllers\Api\AdminChatbotController::class, 'export']);

    Route::get('/content', [\App\Http\Controllers\Api\ContentManagementController::class, 'index']);
    Route::get('/content/blogs/export', [\App\Http\Controllers\Api\ContentManagementController::class, 'exportBlogs']);
    Route::get('/content/faqs/export', [\App\Http\Controllers\Api\ContentManagementController::class, 'exportFaqs']);
    Route::post('/content/blogs', [\App\Http\Controllers\Api\ContentManagementController::class, 'storeBlog']);
    Route::put('/content/blogs/{id}', [\App\Http\Controllers\Api\ContentManagementController::class, 'updateBlog']);
    Route::delete('/content/blogs/{id}', [\App\Http\Controllers\Api\ContentManagementController::class, 'deleteBlog']);
    Route::post('/content/faqs', [\App\Http\Controllers\Api\ContentManagementController::class, 'storeFaq']);
    Route::put('/content/faqs/{id}', [\App\Http\Controllers\Api\ContentManagementController::class, 'updateFaq']);
    Route::delete('/content/faqs/{id}', [\App\Http\Controllers\Api\ContentManagementController::class, 'deleteFaq']);

    Route::get('/newsletter/subscribers', [\App\Http\Controllers\Api\NewsletterController::class, 'adminSubscribers']);

    // â”€â”€ MALÄ° Ä°ÅLEMLER â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    Route::get('/financials/export', [\App\Http\Controllers\Api\FinancialTransactionController::class, 'export']);
    Route::get('/financials', [\App\Http\Controllers\Api\FinancialTransactionController::class, 'index']);
    Route::post('/financials', [\App\Http\Controllers\Api\FinancialTransactionController::class, 'store']);
    Route::get('/financials/{id}', [\App\Http\Controllers\Api\FinancialTransactionController::class, 'show']);
    Route::put('/financials/{id}/approve', [\App\Http\Controllers\Api\FinancialTransactionController::class, 'approve']);
    Route::put('/financials/{id}/reject', [\App\Http\Controllers\Api\FinancialTransactionController::class, 'reject']);
    Route::put('/financials/{id}/pay', [\App\Http\Controllers\Api\FinancialTransactionController::class, 'markPaid']);
    Route::delete('/financials/{id}', [\App\Http\Controllers\Api\FinancialTransactionController::class, 'destroy']);
    Route::get('/financials/{id}/invoice', [\App\Http\Controllers\Api\FinancialTransactionController::class, 'downloadInvoice']);

    // â”€â”€ PERSONEL YÃ–NETÄ°MÄ° â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    Route::get('/staff/export', [\App\Http\Controllers\Api\StaffController::class, 'export']);
    Route::get('/staff/active', [\App\Http\Controllers\Api\StaffController::class, 'active']);
    Route::get('/staff', [\App\Http\Controllers\Api\StaffController::class, 'index']);
    Route::get('/staff/{id}', [\App\Http\Controllers\Api\StaffController::class, 'show']);
    Route::put('/staff/{id}', [\App\Http\Controllers\Api\StaffController::class, 'update']);
    Route::post('/staff/{id}/documents', [\App\Http\Controllers\Api\StaffController::class, 'uploadDocument']);

    // â”€â”€ Ä°ZÄ°N TALEPLERÄ° (Admin gÃ¶rÃ¼nÃ¼mÃ¼) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    // â”€â”€ DUYURULAR â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    Route::post('/announcements/send-sms', [\App\Http\Controllers\Api\AnnouncementController::class, 'sendSms']);
    Route::post('/announcements/send-email', [\App\Http\Controllers\Api\AnnouncementController::class, 'sendEmail']);
    Route::get('/announcements', [\App\Http\Controllers\Api\AnnouncementController::class, 'index']);
    Route::get('/announcements/export', [\App\Http\Controllers\Api\AnnouncementController::class, 'export']);
    Route::post('/announcements', [\App\Http\Controllers\Api\AnnouncementController::class, 'store']);
    Route::get('/announcements/{id}', [\App\Http\Controllers\Api\AnnouncementController::class, 'show']);
    Route::put('/announcements/{id}', [\App\Http\Controllers\Api\AnnouncementController::class, 'update']);
    Route::delete('/announcements/{id}', [\App\Http\Controllers\Api\AnnouncementController::class, 'destroy']);

    // â”€â”€ KULLANICI YÃ–NETÄ°MÄ° â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    Route::get('/users/export', [\App\Http\Controllers\Api\UserController::class, 'exportUsers']);
    Route::get('/users', [\App\Http\Controllers\Api\UserController::class, 'index']);
    Route::get('/users/{id}', [\App\Http\Controllers\Api\UserController::class, 'showUser']);
    Route::put('/users/{id}', [\App\Http\Controllers\Api\UserController::class, 'updateUser']);
    Route::get('/permissions-matrix', [\App\Http\Controllers\Api\PermissionMatrixController::class, 'index']);
    Route::put('/permissions-matrix', [\App\Http\Controllers\Api\PermissionMatrixController::class, 'update']);
    Route::get('/permissions-matrix/audit', [\App\Http\Controllers\Api\PermissionMatrixController::class, 'audit']);
    Route::get('/permissions-matrix/users', [\App\Http\Controllers\Api\PermissionMatrixController::class, 'users']);
    Route::get('/permissions-matrix/users/{id}', [\App\Http\Controllers\Api\PermissionMatrixController::class, 'showUserOverrides']);
    Route::put('/permissions-matrix/users/{id}', [\App\Http\Controllers\Api\PermissionMatrixController::class, 'updateUserOverrides']);
    Route::put('/permissions-matrix/users/{id}/roles', [\App\Http\Controllers\Api\PermissionMatrixController::class, 'assignUserRoles']);
    Route::get('/permissions-matrix/roles', [\App\Http\Controllers\Api\PermissionMatrixController::class, 'roleCatalog']);
    Route::post('/permissions-matrix/roles', [\App\Http\Controllers\Api\PermissionMatrixController::class, 'createRole']);
    Route::put('/permissions-matrix/roles/{id}', [\App\Http\Controllers\Api\PermissionMatrixController::class, 'updateRole']);
    Route::delete('/permissions-matrix/roles/{id}', [\App\Http\Controllers\Api\PermissionMatrixController::class, 'deleteRole']);

    // â”€â”€ DESTEK MERKEZÄ° (Admin) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    Route::get('/support/tickets', [\App\Http\Controllers\Api\SupportTicketController::class, 'index']);
    Route::get('/support/assignable-users', [\App\Http\Controllers\Api\SupportTicketController::class, 'assignableUsers']);
    Route::get('/support/tickets/export', [\App\Http\Controllers\Api\SupportTicketController::class, 'export']);
    Route::put('/support/tickets/{id}/assign', [\App\Http\Controllers\Api\SupportTicketController::class, 'assign']);
    Route::put('/support/tickets/{id}/close', [\App\Http\Controllers\Api\SupportTicketController::class, 'close']);
});

// Unified panel icin rol-prefix bagimsiz generic alias endpointleri.
// /admin/* endpointleri geriye donuk uyumluluk icin oldugu gibi korunur.
Route::middleware(['auth:sanctum', 'blacklist', 'role:super_admin|coordinator|staff', 'audit.action'])->prefix('panel')->group(function () {
    Route::get('/programs', [\App\Http\Controllers\Api\AdminProgramController::class, 'index']);
    Route::get('/programs/export', [\App\Http\Controllers\Api\AdminProgramController::class, 'export']);
    Route::post('/programs', [\App\Http\Controllers\Api\AdminProgramController::class, 'store']);
    Route::put('/programs/{id}', [\App\Http\Controllers\Api\AdminProgramController::class, 'update']);
    Route::post('/programs/{id}/complete', [\App\Http\Controllers\Api\AdminProgramController::class, 'complete']);
    Route::post('/programs/{id}/generate-qr', [\App\Http\Controllers\Api\AdminProgramController::class, 'generateQr']);
    Route::get('/programs/{id}/attendances', [\App\Http\Controllers\Api\AdminProgramController::class, 'attendanceDetails']);
    Route::get('/programs/{id}/attendances/export', [\App\Http\Controllers\Api\AdminProgramController::class, 'exportAttendanceDetails']);

    Route::get('/applications', [\App\Http\Controllers\Api\AdminApplicationController::class, 'index']);
    Route::get('/applications/export', [\App\Http\Controllers\Api\AdminApplicationController::class, 'export']);
    Route::put('/applications/{id}/status', [\App\Http\Controllers\Api\AdminApplicationController::class, 'updateStatus']);
    Route::put('/applications/{id}/interview', [\App\Http\Controllers\Api\AdminApplicationController::class, 'planInterview']);
    Route::post('/applications/{id}/waitlist', [\App\Http\Controllers\Api\AdminApplicationController::class, 'addToWaitlist']);

    Route::get('/periods', [\App\Http\Controllers\Api\PeriodController::class, 'index']);
    Route::get('/periods/export', [\App\Http\Controllers\Api\PeriodController::class, 'export']);
    Route::post('/periods', [\App\Http\Controllers\Api\PeriodController::class, 'store']);
    Route::put('/periods/{id}', [\App\Http\Controllers\Api\PeriodController::class, 'update']);

    Route::get('/projects/manageable', [\App\Http\Controllers\Api\ProjectContentController::class, 'manageable']);
    Route::get('/projects/export', [\App\Http\Controllers\Api\ProjectContentController::class, 'exportManageable']);
    Route::get('/projects/{id}/content', [\App\Http\Controllers\Api\ProjectContentController::class, 'show']);
    Route::put('/projects/{id}/content', [\App\Http\Controllers\Api\ProjectContentController::class, 'update']);
    Route::get('/projects/{id}/application-form', [\App\Http\Controllers\Api\ProjectContentController::class, 'applicationForm']);
    Route::put('/projects/{id}/application-form', [\App\Http\Controllers\Api\ProjectContentController::class, 'updateApplicationForm']);

    Route::get('/calendar/overview', [\App\Http\Controllers\Api\CalendarController::class, 'overview']);
    Route::get('/calendar/assignees', [\App\Http\Controllers\Api\CalendarController::class, 'assignees']);
    Route::get('/calendar/google/status', [\App\Http\Controllers\Api\CalendarController::class, 'googleStatus']);
    Route::get('/calendar/google/connect', [\App\Http\Controllers\Api\CalendarController::class, 'googleConnect']);
    Route::post('/calendar/google/sync', [\App\Http\Controllers\Api\CalendarController::class, 'googleSync']);
    Route::put('/calendar/programs/{id}/assignments', [\App\Http\Controllers\Api\CalendarController::class, 'updateAssignments']);

    // Dashboard
    Route::get('/dashboard/stats', [\App\Http\Controllers\Api\AdminDashboardController::class, 'stats']);
    Route::get('/dashboard/activity-logs', [\App\Http\Controllers\Api\AdminDashboardController::class, 'activityLogs']);
    Route::get('/dashboard/activity-logs/export', [\App\Http\Controllers\Api\AdminDashboardController::class, 'exportActivityLogs']);

    // Announcements
    Route::post('/announcements/send-sms', [\App\Http\Controllers\Api\AnnouncementController::class, 'sendSms']);
    Route::post('/announcements/send-email', [\App\Http\Controllers\Api\AnnouncementController::class, 'sendEmail']);
    Route::get('/announcements', [\App\Http\Controllers\Api\AnnouncementController::class, 'index']);
    Route::get('/announcements/export', [\App\Http\Controllers\Api\AnnouncementController::class, 'export']);
    Route::post('/announcements', [\App\Http\Controllers\Api\AnnouncementController::class, 'store']);
    Route::get('/announcements/{id}', [\App\Http\Controllers\Api\AnnouncementController::class, 'show']);
    Route::put('/announcements/{id}', [\App\Http\Controllers\Api\AnnouncementController::class, 'update']);
    Route::delete('/announcements/{id}', [\App\Http\Controllers\Api\AnnouncementController::class, 'destroy']);

    // Users & permissions matrix
    Route::get('/users/export', [\App\Http\Controllers\Api\UserController::class, 'exportUsers']);
    Route::get('/users', [\App\Http\Controllers\Api\UserController::class, 'index']);
    Route::get('/users/{id}', [\App\Http\Controllers\Api\UserController::class, 'showUser']);
    Route::put('/users/{id}', [\App\Http\Controllers\Api\UserController::class, 'updateUser']);
    Route::get('/permissions-matrix', [\App\Http\Controllers\Api\PermissionMatrixController::class, 'index']);
    Route::put('/permissions-matrix', [\App\Http\Controllers\Api\PermissionMatrixController::class, 'update']);
    Route::get('/permissions-matrix/audit', [\App\Http\Controllers\Api\PermissionMatrixController::class, 'audit']);
    Route::get('/permissions-matrix/users', [\App\Http\Controllers\Api\PermissionMatrixController::class, 'users']);
    Route::get('/permissions-matrix/users/{id}', [\App\Http\Controllers\Api\PermissionMatrixController::class, 'showUserOverrides']);
    Route::put('/permissions-matrix/users/{id}', [\App\Http\Controllers\Api\PermissionMatrixController::class, 'updateUserOverrides']);
    Route::put('/permissions-matrix/users/{id}/roles', [\App\Http\Controllers\Api\PermissionMatrixController::class, 'assignUserRoles']);
    Route::get('/permissions-matrix/roles', [\App\Http\Controllers\Api\PermissionMatrixController::class, 'roleCatalog']);
    Route::post('/permissions-matrix/roles', [\App\Http\Controllers\Api\PermissionMatrixController::class, 'createRole']);
    Route::put('/permissions-matrix/roles/{id}', [\App\Http\Controllers\Api\PermissionMatrixController::class, 'updateRole']);
    Route::delete('/permissions-matrix/roles/{id}', [\App\Http\Controllers\Api\PermissionMatrixController::class, 'deleteRole']);

    // Support center
    Route::get('/support/tickets', [\App\Http\Controllers\Api\SupportTicketController::class, 'index']);
    Route::get('/support/assignable-users', [\App\Http\Controllers\Api\SupportTicketController::class, 'assignableUsers']);
    Route::get('/support/tickets/export', [\App\Http\Controllers\Api\SupportTicketController::class, 'export']);
    Route::put('/support/tickets/{id}/assign', [\App\Http\Controllers\Api\SupportTicketController::class, 'assign']);
    Route::put('/support/tickets/{id}/close', [\App\Http\Controllers\Api\SupportTicketController::class, 'close']);

    // Staff management
    Route::get('/staff/export', [\App\Http\Controllers\Api\StaffController::class, 'export']);
    Route::get('/staff/active', [\App\Http\Controllers\Api\StaffController::class, 'active']);
    Route::get('/staff', [\App\Http\Controllers\Api\StaffController::class, 'index']);
    Route::get('/staff/{id}', [\App\Http\Controllers\Api\StaffController::class, 'show']);
    Route::put('/staff/{id}', [\App\Http\Controllers\Api\StaffController::class, 'update']);
    Route::post('/staff/{id}/documents', [\App\Http\Controllers\Api\StaffController::class, 'uploadDocument']);
    Route::get('/leave-requests', [\App\Http\Controllers\Api\StaffController::class, 'leaveRequests']);
    Route::get('/leave-requests/export', [\App\Http\Controllers\Api\StaffController::class, 'exportLeaveRequests']);
    Route::put('/leave-requests/{id}/approve', [\App\Http\Controllers\Api\StaffController::class, 'approveLeave']);
    Route::put('/leave-requests/{id}/reject', [\App\Http\Controllers\Api\StaffController::class, 'rejectLeave']);
    Route::get('/leave-requests', [\App\Http\Controllers\Api\StaffController::class, 'leaveRequests']);
    Route::get('/leave-requests/export', [\App\Http\Controllers\Api\StaffController::class, 'exportLeaveRequests']);
    Route::put('/leave-requests/{id}/approve', [\App\Http\Controllers\Api\StaffController::class, 'approveLeave']);
    Route::put('/leave-requests/{id}/reject', [\App\Http\Controllers\Api\StaffController::class, 'rejectLeave']);

    // Newsletter
    Route::get('/newsletter/subscribers', [\App\Http\Controllers\Api\NewsletterController::class, 'adminSubscribers']);

    // Site settings & media
    Route::get('/site-settings', [\App\Http\Controllers\Api\SiteSettingsController::class, 'admin']);
    Route::put('/site-settings', [\App\Http\Controllers\Api\SiteSettingsController::class, 'update']);
    Route::post('/media/upload', [\App\Http\Controllers\Api\MediaUploadController::class, 'store']);

    // Content
    Route::get('/content', [\App\Http\Controllers\Api\ContentManagementController::class, 'index']);
    Route::get('/content/blogs/export', [\App\Http\Controllers\Api\ContentManagementController::class, 'exportBlogs']);
    Route::get('/content/faqs/export', [\App\Http\Controllers\Api\ContentManagementController::class, 'exportFaqs']);
    Route::post('/content/blogs', [\App\Http\Controllers\Api\ContentManagementController::class, 'storeBlog']);
    Route::put('/content/blogs/{id}', [\App\Http\Controllers\Api\ContentManagementController::class, 'updateBlog']);
    Route::delete('/content/blogs/{id}', [\App\Http\Controllers\Api\ContentManagementController::class, 'deleteBlog']);
    Route::post('/content/faqs', [\App\Http\Controllers\Api\ContentManagementController::class, 'storeFaq']);
    Route::put('/content/faqs/{id}', [\App\Http\Controllers\Api\ContentManagementController::class, 'updateFaq']);
    Route::delete('/content/faqs/{id}', [\App\Http\Controllers\Api\ContentManagementController::class, 'deleteFaq']);

    // Certificates
    Route::get('/certificates', [\App\Http\Controllers\Api\AdminCertificateController::class, 'index']);
    Route::get('/certificates/export', [\App\Http\Controllers\Api\AdminCertificateController::class, 'export']);
    Route::post('/certificates', [\App\Http\Controllers\Api\AdminCertificateController::class, 'store']);
    Route::delete('/certificates/{id}', [\App\Http\Controllers\Api\AdminCertificateController::class, 'destroy']);

    // Financials (admin/coordinator/staff visibility follows controller/middleware rules)
    Route::get('/financials/export', [\App\Http\Controllers\Api\FinancialTransactionController::class, 'export']);
    Route::get('/financials', [\App\Http\Controllers\Api\FinancialTransactionController::class, 'index']);
    Route::post('/financials', [\App\Http\Controllers\Api\FinancialTransactionController::class, 'store']);
    Route::get('/financials/{id}', [\App\Http\Controllers\Api\FinancialTransactionController::class, 'show']);
    Route::put('/financials/{id}/approve', [\App\Http\Controllers\Api\FinancialTransactionController::class, 'approve']);
    Route::put('/financials/{id}/reject', [\App\Http\Controllers\Api\FinancialTransactionController::class, 'reject']);
    Route::put('/financials/{id}/pay', [\App\Http\Controllers\Api\FinancialTransactionController::class, 'markPaid']);
    Route::delete('/financials/{id}', [\App\Http\Controllers\Api\FinancialTransactionController::class, 'destroy']);
    Route::get('/financials/{id}/invoice', [\App\Http\Controllers\Api\FinancialTransactionController::class, 'downloadInvoice']);

    // Unified aliases for role-specific pages under /panel/*
    Route::get('/participants', [\App\Http\Controllers\Api\CoordinatorParticipantController::class, 'index']);
    Route::get('/participants/export', [\App\Http\Controllers\Api\CoordinatorParticipantController::class, 'export']);
    Route::get('/members', [\App\Http\Controllers\Api\StaffController::class, 'unitMembers']);
    Route::get('/members/export', [\App\Http\Controllers\Api\StaffController::class, 'exportUnitMembers']);
});

// â”€â”€ KOORDÄ°NATÃ–R Ã–ZEL (sadece coordinator) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
Route::middleware(['auth:sanctum', 'blacklist', 'role:coordinator'])->group(function () {
    // KoordinatÃ¶rÃ¼n mali iÅŸlemleri (kendi projesi)
    Route::get('/coordinator/financials', [\App\Http\Controllers\Api\FinancialTransactionController::class, 'myFinancials']);
    Route::get('/coordinator/financials/export', [\App\Http\Controllers\Api\FinancialTransactionController::class, 'exportMyFinancials']);
    Route::post('/coordinator/financials', [\App\Http\Controllers\Api\FinancialTransactionController::class, 'store']);
    Route::get('/coordinator/participants', [\App\Http\Controllers\Api\CoordinatorParticipantController::class, 'index']);
    Route::get('/coordinator/participants/export', [\App\Http\Controllers\Api\CoordinatorParticipantController::class, 'export']);
});

Route::middleware(['auth:sanctum', 'blacklist', 'role:super_admin|coordinator|staff'])->prefix('panel/coordinator')->group(function () {
    Route::get('/financials', [\App\Http\Controllers\Api\FinancialTransactionController::class, 'myFinancials']);
    Route::get('/financials/export', [\App\Http\Controllers\Api\FinancialTransactionController::class, 'exportMyFinancials']);
    Route::post('/financials', [\App\Http\Controllers\Api\FinancialTransactionController::class, 'store']);
    Route::get('/participants', [\App\Http\Controllers\Api\CoordinatorParticipantController::class, 'index']);
    Route::get('/participants/export', [\App\Http\Controllers\Api\CoordinatorParticipantController::class, 'export']);
});

// â”€â”€ PERSONEL / KOORDÄ°NATÃ–R (Ä°zin Talepleri) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
Route::middleware(['auth:sanctum', 'blacklist'])->group(function () {
    Route::post('/leave-requests', [\App\Http\Controllers\Api\StaffController::class, 'storeLeaveRequest']);
    Route::get('/my-leave-requests', [\App\Http\Controllers\Api\StaffController::class, 'myLeaveRequests']);
});

Route::middleware(['auth:sanctum', 'blacklist', 'role:staff'])->group(function () {
    Route::get('/staff/announcements', [\App\Http\Controllers\Api\AnnouncementController::class, 'myAnnouncements']);
    Route::get('/staff/announcements/export', [\App\Http\Controllers\Api\AnnouncementController::class, 'exportMyAnnouncements']);
    Route::get('/staff/applications', [\App\Http\Controllers\Api\AdminApplicationController::class, 'staffIndex']);
    Route::get('/staff/applications/export', [\App\Http\Controllers\Api\AdminApplicationController::class, 'staffExport']);
    Route::put('/staff/applications/{id}/status', [\App\Http\Controllers\Api\AdminApplicationController::class, 'staffUpdateStatus']);
    Route::get('/staff/members', [\App\Http\Controllers\Api\StaffController::class, 'unitMembers']);
    Route::get('/staff/members/export', [\App\Http\Controllers\Api\StaffController::class, 'exportUnitMembers']);
    Route::get('/staff/projects', [\App\Http\Controllers\Api\StaffController::class, 'myProjects']);
    Route::get('/staff/projects/export', [\App\Http\Controllers\Api\StaffController::class, 'exportMyProjects']);
});

Route::middleware(['auth:sanctum', 'blacklist', 'role:super_admin|coordinator|staff'])->prefix('panel/staff')->group(function () {
    Route::get('/announcements', [\App\Http\Controllers\Api\AnnouncementController::class, 'myAnnouncements']);
    Route::get('/announcements/export', [\App\Http\Controllers\Api\AnnouncementController::class, 'exportMyAnnouncements']);
    Route::get('/applications', [\App\Http\Controllers\Api\AdminApplicationController::class, 'staffIndex']);
    Route::get('/applications/export', [\App\Http\Controllers\Api\AdminApplicationController::class, 'staffExport']);
    Route::put('/applications/{id}/status', [\App\Http\Controllers\Api\AdminApplicationController::class, 'staffUpdateStatus']);
    Route::get('/members', [\App\Http\Controllers\Api\StaffController::class, 'unitMembers']);
    Route::get('/members/export', [\App\Http\Controllers\Api\StaffController::class, 'exportUnitMembers']);
    Route::get('/projects', [\App\Http\Controllers\Api\StaffController::class, 'myProjects']);
    Route::get('/projects/export', [\App\Http\Controllers\Api\StaffController::class, 'exportMyProjects']);
});

Route::middleware(['auth:sanctum', 'blacklist', 'role:super_admin|coordinator|staff'])->prefix('calendar')->group(function () {
    Route::get('/overview', [\App\Http\Controllers\Api\CalendarController::class, 'overview']);
    Route::get('/assignees', [\App\Http\Controllers\Api\CalendarController::class, 'assignees']);
    Route::get('/google/status', [\App\Http\Controllers\Api\CalendarController::class, 'googleStatus']);
    Route::get('/google/connect', [\App\Http\Controllers\Api\CalendarController::class, 'googleConnect']);
    Route::post('/google/sync', [\App\Http\Controllers\Api\CalendarController::class, 'googleSync']);
});

Route::middleware(['auth:sanctum', 'blacklist', 'role:super_admin|coordinator'])->prefix('calendar')->group(function () {
    Route::put('/programs/{id}/assignments', [\App\Http\Controllers\Api\CalendarController::class, 'updateAssignments']);
});
