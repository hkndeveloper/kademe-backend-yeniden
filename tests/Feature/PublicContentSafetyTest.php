<?php

namespace Tests\Feature;

use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\Participant;
use App\Models\Period;
use App\Models\Program;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicContentSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_blogs_search_uses_excerpt_and_returns_normalized_fields(): void
    {
        $category = BlogCategory::query()->create([
            'name' => 'Genel',
            'slug' => 'genel',
        ]);
        $author = User::factory()->create([
            'surname' => 'BlogYazari',
            'role' => 'staff',
        ]);

        BlogPost::query()->create([
            'title' => 'Yayinlanan Blog',
            'slug' => 'yayinlanan-blog',
            'content' => 'Icerik',
            'excerpt' => 'Ozet metni',
            'cover_image_path' => 'blogs/cover.png',
            'author_id' => $author->id,
            'category_id' => $category->id,
            'status' => 'published',
            'published_at' => now()->subDay(),
        ]);

        $response = $this->getJson('/api/blogs?search=Ozet');
        $response->assertOk();
        $response->assertJsonCount(1, 'blogs.data');
        $response->assertJsonPath('blogs.data.0.summary', 'Ozet metni');
        $response->assertJsonPath('blogs.data.0.excerpt', 'Ozet metni');
        $response->assertJsonPath('blogs.data.0.cover_image_path', 'blogs/cover.png');
        $this->assertNotNull(data_get($response->json(), 'blogs.data.0.cover_image'));
    }

    public function test_public_activities_response_hides_sensitive_fields(): void
    {
        $project = Project::query()->create([
            'name' => 'Public Program Proje',
            'slug' => 'public-program-proje',
            'type' => 'kademe_plus',
            'status' => 'active',
            'application_open' => true,
        ]);

        $program = Program::query()->create([
            'project_id' => $project->id,
            'title' => 'Acik Etkinlik',
            'description' => 'Genel aciklama',
            'location' => 'Istanbul',
            'latitude' => 40.12,
            'longitude' => 29.12,
            'radius_meters' => 150,
            'guest_info' => ['speaker' => 'Test'],
            'start_at' => now()->addDay(),
            'end_at' => now()->addDays(2),
            'credit_deduction' => 25,
            'qr_token' => 'SECRET_QR',
            'qr_expires_at' => now()->addHours(2),
            'status' => 'scheduled',
        ]);

        $list = $this->getJson('/api/activities');
        $list->assertOk();
        $rows = data_get($list->json(), 'programs.data');
        $this->assertIsArray($rows);
        $programPayload = collect($rows)->firstWhere('id', $program->id);
        $this->assertNotNull($programPayload);
        $this->assertArrayNotHasKey('qr_token', $programPayload);
        $this->assertArrayNotHasKey('latitude', $programPayload);
        $this->assertArrayNotHasKey('longitude', $programPayload);
        $this->assertArrayNotHasKey('radius_meters', $programPayload);
        $this->assertArrayNotHasKey('credit_deduction', $programPayload);

        $detail = $this->getJson('/api/activities/'.$program->id);
        $detail->assertOk();
        $detail->assertJsonPath('program.id', $program->id);
        $detail->assertJsonMissingPath('program.qr_token');
        $detail->assertJsonMissingPath('program.latitude');
    }

    public function test_cancelled_activity_detail_is_not_publicly_accessible(): void
    {
        $project = Project::query()->create([
            'name' => 'Iptal Proje',
            'slug' => 'iptal-proje',
            'type' => 'other',
            'status' => 'active',
            'application_open' => true,
        ]);

        $cancelled = Program::query()->create([
            'project_id' => $project->id,
            'title' => 'Iptal Etkinlik',
            'start_at' => now()->addDay(),
            'end_at' => now()->addDays(2),
            'status' => 'cancelled',
        ]);

        $this->getJson('/api/activities/'.$cancelled->id)->assertNotFound();
    }

    public function test_public_project_detail_does_not_expose_sensitive_participant_fields(): void
    {
        $project = Project::query()->create([
            'name' => 'PII Proje',
            'slug' => 'pii-proje',
            'type' => 'kademe_plus',
            'status' => 'active',
            'application_open' => true,
        ]);
        $period = Period::query()->create([
            'project_id' => $project->id,
            'name' => '2026',
            'start_date' => now()->subMonth(),
            'end_date' => now()->addMonth(),
            'status' => 'active',
        ]);

        $user = User::factory()->create([
            'name' => 'Ali',
            'surname' => 'Veli',
            'email' => 'ali.veli@test.local',
            'phone' => '5551112233',
            'university' => 'Test Uni',
            'department' => 'Bilgisayar',
            'public_profile_visible' => true,
        ]);

        Participant::query()->create([
            'user_id' => $user->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'status' => 'active',
            'enrolled_at' => now()->startOfYear(),
        ]);

        $alumni = User::factory()->create([
            'name' => 'Ayse',
            'surname' => 'Mezun',
            'email' => 'ayse.mezun@test.local',
            'phone' => '5554443322',
            'university' => 'Mezun Uni',
            'department' => 'Sosyoloji',
            'public_alumni_visible' => true,
        ]);

        Participant::query()->create([
            'user_id' => $alumni->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'status' => 'graduated',
            'graduation_status' => 'graduated',
            'graduated_at' => now()->setYear(2025),
            'graduation_note' => 'Sivil toplum uzmani',
        ]);

        $response = $this->getJson('/api/projects/'.$project->slug);
        $response->assertOk();
        $response->assertJsonPath('project.active_students.0.name', 'Ali Veli');
        $response->assertJsonPath('project.active_students.0.university', 'Test Uni');
        $response->assertJsonPath('project.active_students.0.department', 'Bilgisayar');
        $response->assertJsonPath('project.active_students.0.period.name', '2026');
        $response->assertJsonPath('project.active_students.0.period_name', '2026');
        $response->assertJsonPath('project.active_student_groups.0.year', '2026');
        $response->assertJsonPath('project.active_student_groups.0.students.0.name', 'Ali Veli');
        $response->assertJsonPath('project.alumni_groups.0.year', '2025');
        $response->assertJsonPath('project.alumni_groups.0.students.0.name', 'Ayse Mezun');
        $response->assertJsonPath('project.alumni_groups.0.students.0.period_name', '2026');
        $response->assertJsonPath('project.alumni_groups.0.students.0.job', 'Sivil toplum uzmani');
        $response->assertJsonMissingPath('project.active_students.0.email');
        $response->assertJsonMissingPath('project.active_students.0.phone');
        $response->assertJsonMissingPath('project.active_student_groups.0.students.0.email');
        $response->assertJsonMissingPath('project.active_student_groups.0.students.0.phone');
        $response->assertJsonMissingPath('project.alumni_groups.0.students.0.email');
        $response->assertJsonMissingPath('project.alumni_groups.0.students.0.phone');
    }

    public function test_public_homepage_endpoint_returns_aggregated_safe_payload(): void
    {
        $category = BlogCategory::query()->create([
            'name' => 'Duyuru',
            'slug' => 'duyuru',
        ]);
        $author = User::factory()->create([
            'surname' => 'HomepageAuthor',
            'role' => 'staff',
        ]);

        BlogPost::query()->create([
            'title' => 'Homepage Blog',
            'slug' => 'homepage-blog',
            'content' => 'Blog icerigi',
            'excerpt' => 'Blog ozeti',
            'author_id' => $author->id,
            'category_id' => $category->id,
            'status' => 'published',
            'published_at' => now()->subDay(),
        ]);

        $project = Project::query()->create([
            'name' => 'Homepage Proje',
            'slug' => 'homepage-proje',
            'type' => 'kademe_plus',
            'status' => 'active',
            'application_open' => true,
        ]);

        Program::query()->create([
            'project_id' => $project->id,
            'title' => 'Homepage Etkinlik',
            'location' => 'Istanbul',
            'latitude' => 40.12,
            'longitude' => 29.12,
            'radius_meters' => 100,
            'credit_deduction' => 10,
            'qr_token' => 'SECRET_HOMEPAGE_QR',
            'start_at' => now()->addDay(),
            'end_at' => now()->addDays(2),
            'status' => 'scheduled',
            'is_public' => true,
        ]);

        $response = $this->getJson('/api/homepage');

        $response->assertOk();
        $response->assertJsonPath('settings.general.site_name', 'KADEME');
        $response->assertJsonPath('projects.0.name', 'Homepage Proje');
        $response->assertJsonPath('blogs.0.title', 'Homepage Blog');
        $response->assertJsonPath('programs.0.title', 'Homepage Etkinlik');
        $response->assertJsonMissingPath('programs.0.qr_token');
        $response->assertJsonMissingPath('programs.0.latitude');
        $response->assertJsonMissingPath('programs.0.longitude');
        $response->assertJsonMissingPath('settings.google_calendar');
    }

    public function test_public_project_detail_exposes_safe_calendar_months(): void
    {
        $project = Project::query()->create([
            'name' => 'Takvim Proje',
            'slug' => 'takvim-proje',
            'type' => 'kademe_plus',
            'status' => 'active',
            'application_open' => true,
        ]);
        $period = Period::query()->create([
            'project_id' => $project->id,
            'name' => '2026',
            'start_date' => now()->startOfYear(),
            'end_date' => now()->endOfYear(),
            'status' => 'active',
        ]);

        Program::query()->create([
            'project_id' => $project->id,
            'period_id' => $period->id,
            'title' => 'Mayis Oturumu',
            'location' => 'Istanbul',
            'latitude' => 40.12,
            'longitude' => 29.12,
            'radius_meters' => 100,
            'credit_deduction' => 10,
            'qr_token' => 'SECRET_ONE',
            'start_at' => now()->setDate(2026, 5, 20)->setTime(10, 0),
            'end_at' => now()->setDate(2026, 5, 20)->setTime(12, 0),
            'status' => 'scheduled',
        ]);
        Program::query()->create([
            'project_id' => $project->id,
            'period_id' => $period->id,
            'title' => 'Haziran Oturumu',
            'start_at' => now()->setDate(2026, 6, 10)->setTime(14, 0),
            'end_at' => now()->setDate(2026, 6, 10)->setTime(16, 0),
            'status' => 'completed',
        ]);

        $response = $this->getJson('/api/projects/'.$project->slug);

        $response->assertOk();
        $response->assertJsonCount(2, 'programs.calendar');
        $response->assertJsonCount(2, 'programs.calendar_months');
        $response->assertJsonPath('programs.calendar_months.0.key', '2026-05');
        $response->assertJsonPath('programs.calendar.0.title', 'Mayis Oturumu');
        $response->assertJsonMissingPath('programs.calendar.0.qr_token');
        $response->assertJsonMissingPath('programs.calendar.0.latitude');
        $response->assertJsonMissingPath('programs.calendar.0.longitude');
        $response->assertJsonMissingPath('programs.calendar.0.credit_deduction');
    }
}
