<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ApplicationForm;
use App\Models\Period;
use App\Models\Participant;
use App\Models\Project;
use App\Models\User;
use App\Services\NotificationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApplicationScreeningRulesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->mock(NotificationService::class)->shouldReceive('sendTemplatedEmail')->andReturn(1);
    }

    private function project(): array
    {
        $project = Project::query()->create([
            'name' => 'Eleme Projesi', 'slug' => 'eleme-projesi', 'type' => 'other',
            'status' => 'active', 'is_public' => true, 'application_open' => true,
        ]);
        $period = Period::query()->create([
            'project_id' => $project->id, 'name' => 'Aktif Dönem',
            'start_date' => now()->toDateString(), 'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);
        $project->update(['current_period_id' => $period->id]);

        return [$project, $period];
    }

    private function actAs(string $role): User
    {
        $user = User::factory()->create(['surname' => 'Aday', 'role' => $role, 'status' => 'active', 'kvkk_consent_at' => now()]);
        $user->assignRole($role);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_invalid_rule_does_not_replace_active_form_and_preview_requires_permission(): void
    {
        [$project, $period] = $this->project();
        $this->actAs('super_admin');
        $url = '/api/panel/projects/'.$project->id.'/application-form';
        $payload = [
            'period_id' => $period->id,
            'fields' => [['id' => 'department', 'type' => 'select', 'label' => 'Bölüm', 'required' => true, 'options' => ['Hukuk', 'Tıp']]],
            'auto_reject_rules' => [['field_id' => 'department', 'operator' => 'equals', 'value' => 'Tıp', 'reason' => 'Uygun değil']],
        ];
        $this->putJson($url, $payload)->assertOk();
        $first = ApplicationForm::query()->sole();

        $this->putJson($url, array_replace($payload, [
            'auto_reject_rules' => [['field_id' => 'department', 'operator' => 'equals', 'value' => 'Mühendislik']],
        ]))->assertUnprocessable()->assertJsonValidationErrors('auto_reject_rules.0.value');
        $this->putJson($url, array_replace($payload, [
            'auto_reject_rules' => [['field_id' => 'deleted', 'operator' => 'equals', 'value' => 'Tıp']],
        ]))->assertUnprocessable()->assertJsonValidationErrors('auto_reject_rules.0.field_id');
        $this->putJson($url, array_replace($payload, [
            'fields' => [['id' => 'department', 'type' => 'checkbox', 'label' => 'Bölüm', 'required' => true, 'options' => ['Hukuk', 'Tıp']]],
        ]))->assertUnprocessable()->assertJsonValidationErrors('auto_reject_rules.0.operator');
        $this->assertDatabaseCount('application_forms', 1);
        $this->assertTrue($first->fresh()->is_active);

        $preview = [
            'field' => $payload['fields'][0], 'rule' => $payload['auto_reject_rules'][0], 'sample_answer' => 'Tıp',
        ];
        $this->postJson($url.'/screening-preview', $preview)
            ->assertOk()->assertJsonPath('matched', true)->assertJsonPath('result', 'rejected');
        $this->postJson($url.'/screening-preview', array_replace($preview, ['sample_answer' => 'Hukuk']))
            ->assertOk()->assertJsonPath('matched', false);
        $this->postJson($url.'/screening-preview', array_replace($preview, ['sample_answer' => 'Yanlış']))
            ->assertUnprocessable()->assertJsonValidationErrors('sample_answer');
        $this->assertDatabaseCount('application_forms', 1);

        $this->actAs('student');
        $this->postJson($url.'/screening-preview', $preview)->assertForbidden();
    }

    public function test_checkbox_rejects_unknown_options_and_numeric_answer_must_be_numeric(): void
    {
        [$project, $period] = $this->project();
        $form = ApplicationForm::query()->create([
            'project_id' => $project->id, 'period_id' => $period->id, 'is_active' => true,
            'fields' => [
                ['id' => 'topics', 'type' => 'checkbox', 'label' => 'Konular', 'required' => true, 'options' => ['A', 'B']],
                ['id' => 'score', 'type' => 'text', 'label' => 'Puan', 'required' => true],
            ],
            'auto_reject_rules' => [['field_id' => 'score', 'operator' => 'lt', 'value' => '50', 'reason' => 'Puan yetersiz']],
        ]);
        $this->actAs('student');
        $url = '/api/applications';
        $payload = ['project_id' => $project->id, 'application_form_id' => $form->id, 'consent_accepted' => true];
        $this->postJson($url, $payload + ['form_data' => ['topics' => ['A', 'D'], 'score' => '40']])
            ->assertUnprocessable()->assertJsonValidationErrors('topics');
        $this->postJson($url, $payload + ['form_data' => ['topics' => ['A'], 'score' => 'kırk']])
            ->assertUnprocessable()->assertJsonValidationErrors('score');
        $this->assertDatabaseCount('applications', 0);
        $this->postJson($url, $payload + ['form_data' => ['topics' => ['A'], 'score' => '40']])
            ->assertCreated()->assertJsonPath('application.status', 'rejected');
    }

    public function test_rule_change_does_not_rewrite_earlier_rejection_or_reason(): void
    {
        [$project, $period] = $this->project();
        $this->actAs('super_admin');
        $url = '/api/panel/projects/'.$project->id.'/application-form';
        $fields = [['id' => 'department', 'type' => 'text', 'label' => 'Bölüm', 'required' => true]];
        $reason = str_repeat('A', 300);
        $this->putJson($url, [
            'period_id' => $period->id, 'fields' => $fields,
            'auto_reject_rules' => [['field_id' => 'department', 'operator' => 'equals', 'value' => 'Tıp', 'reason' => $reason]],
        ])->assertOk();
        $form = ApplicationForm::query()->sole();

        $student = $this->actAs('student');
        $this->postJson('/api/applications', [
            'project_id' => $project->id, 'application_form_id' => $form->id,
            'consent_accepted' => true, 'form_data' => ['department' => 'Tıp'],
        ])->assertCreated()->assertJsonPath('application.status', 'rejected');
        $application = Application::query()->sole();
        $this->assertSame($reason, $application->auto_rejection_reason);

        $this->actAs('super_admin');
        $this->putJson($url, ['period_id' => $period->id, 'fields' => $fields, 'auto_reject_rules' => []])->assertOk();
        $this->assertDatabaseCount('application_forms', 2);
        $this->assertSame($form->id, $application->fresh()->application_form_id);
        $this->assertSame('rejected', $application->status);
        $this->assertSame($reason, $application->auto_rejection_reason);
    }

    public function test_review_rule_keeps_application_pending_and_reason_inside_panel(): void
    {
        [$project, $period] = $this->project();
        $this->actAs('super_admin');
        $url = '/api/panel/projects/'.$project->id.'/application-form';
        $field = ['id' => 'department', 'type' => 'select', 'label' => 'Bölüm', 'required' => true, 'options' => ['Hukuk', 'Tıp']];
        $rule = ['field_id' => 'department', 'operator' => 'equals', 'value' => 'Tıp', 'reason' => 'Koordinatör bölüm bilgisini kontrol etmeli', 'mode' => 'review'];
        $this->putJson($url, ['period_id' => $period->id, 'fields' => [$field], 'auto_reject_rules' => [$rule]])
            ->assertOk()->assertJsonPath('application_form.auto_reject_rules.0.mode', 'review');
        $form = ApplicationForm::query()->sole();
        $this->getJson('/api/projects/'.$project->slug.'/application-form')->assertOk()
            ->assertJsonPath('application_form.id', $form->id)
            ->assertJsonMissingPath('application_form.auto_reject_rules');
        $this->getJson('/api/projects/'.$project->slug)->assertOk()
            ->assertJsonMissingPath('application_form.auto_reject_rules');
        $this->postJson($url.'/screening-preview', [
            'field' => $field, 'rule' => $rule, 'sample_answer' => 'Tıp',
        ])->assertOk()->assertJsonPath('result', 'review');

        $project->update(['quota' => 1]);
        $occupant = User::factory()->create(['surname' => 'Mevcut']);
        Participant::query()->create([
            'user_id' => $occupant->id, 'project_id' => $project->id, 'period_id' => $period->id,
            'status' => 'active', 'credit' => 100, 'enrolled_at' => now(),
        ]);

        $student = $this->actAs('student');
        $this->postJson('/api/applications', [
            'project_id' => $project->id, 'application_form_id' => $form->id,
            'consent_accepted' => true, 'form_data' => ['department' => 'Tıp'],
        ])->assertCreated()->assertJsonPath('application.status', 'pending')
            ->assertJsonMissingPath('application.screening_review_reason');
        $application = Application::query()->sole();
        $this->assertFalse($application->auto_rejected);
        $this->assertSame($rule['reason'], $application->screening_review_reason);
        $this->assertNull($application->rejection_reason);
        $this->getJson('/api/applications')->assertOk()
            ->assertJsonPath('applications.0.status', 'pending')
            ->assertJsonMissing(['screening_review_reason' => $rule['reason']]);

        $this->actAs('super_admin');
        $this->getJson('/api/panel/applications?project_id='.$project->id.'&status=pending')
            ->assertOk()->assertJsonPath('applications.data.0.screening_review_reason', $rule['reason']);
        $this->putJson('/api/panel/applications/'.$application->id.'/status', ['status' => 'accepted'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertSame('pending', $application->fresh()->status);
        $this->assertDatabaseCount('participants', 1);

        // A matching automatic rejection takes precedence over a review rule, regardless of order.
        $match = app(\App\Services\ApplicationScreeningService::class)->firstMatch(
            [$rule, [...$rule, 'mode' => 'reject', 'reason' => 'Kesin uyumsuzluk']],
            ['department' => 'Tıp'],
        );
        $this->assertSame('reject', $match['mode']);
        $this->assertSame('Kesin uyumsuzluk', $match['reason']);
        $this->assertSame($student->id, $application->user_id);
    }

    public function test_erroneous_automatic_rejection_can_be_reopened_once_with_actor_and_original_reason_preserved(): void
    {
        [$project, $period] = $this->project();
        $form = ApplicationForm::query()->create([
            'project_id' => $project->id, 'period_id' => $period->id, 'is_active' => true,
            'fields' => [['id' => 'department', 'type' => 'text', 'label' => 'Bölüm', 'required' => true]],
            'auto_reject_rules' => [['field_id' => 'department', 'operator' => 'equals', 'value' => 'Tıp', 'reason' => 'İlk otomatik ret']],
        ]);
        $student = $this->actAs('student');
        $this->postJson('/api/applications', [
            'project_id' => $project->id, 'application_form_id' => $form->id,
            'consent_accepted' => true, 'form_data' => ['department' => 'Tıp'],
        ])->assertCreated()->assertJsonPath('application.status', 'rejected');
        $application = Application::query()->sole();
        $url = '/api/panel/applications/'.$application->id.'/reopen-auto-rejection';

        $this->postJson($url, ['reason' => 'Yanlış değerlendirme'])->assertForbidden();
        $admin = $this->actAs('super_admin');
        $this->postJson($url, ['reason' => 'Kısa'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $period->update(['status' => 'completed']);
        $this->postJson($url, ['reason' => 'Dönem sonunda düzeltme denemesi'])->assertStatus(423);
        $this->assertSame('rejected', $application->fresh()->status);
        $period->update(['status' => 'active']);
        $this->postJson($url, ['reason' => 'Bölüm bilgisi yeniden doğrulandı; ilk kural hatalıydı.'])
            ->assertOk()->assertJsonPath('application.status', 'pending')
            ->assertJsonPath('follow_up.status_email_sent', true);

        $application->refresh();
        $this->assertTrue($application->auto_rejected);
        $this->assertSame('İlk otomatik ret', $application->auto_rejection_reason);
        $this->assertNull($application->rejection_reason);
        $this->assertNotNull($application->auto_rejection_corrected_at);
        $this->assertSame($admin->id, $application->auto_rejection_corrected_by);
        $this->assertSame('Bölüm bilgisi yeniden doğrulandı; ilk kural hatalıydı.', $application->auto_rejection_correction_reason);
        $this->assertDatabaseCount('applications', 1);
        $this->assertDatabaseCount('participants', 0);
        $this->postJson($url, ['reason' => 'Tekrar düzeltme deneniyor'])->assertUnprocessable();

        $this->getJson('/api/panel/applications?project_id='.$project->id.'&status=pending')
            ->assertOk()->assertJsonPath('applications.data.0.auto_rejection_reason', 'İlk otomatik ret')
            ->assertJsonPath('applications.data.0.auto_rejection_corrected_by_name', trim($admin->name.' '.$admin->surname));
        Sanctum::actingAs($student);
        $this->getJson('/api/applications')->assertOk()
            ->assertJsonPath('applications.0.status', 'pending')
            ->assertJsonPath('applications.0.auto_rejection_reason', null);
    }

    public function test_reopen_notification_failure_does_not_repeat_decision_and_can_be_retried(): void
    {
        [$project, $period] = $this->project();
        $student = $this->actAs('student');
        $application = Application::query()->create([
            'user_id' => $student->id, 'project_id' => $project->id, 'period_id' => $period->id,
            'status' => 'rejected', 'auto_rejected' => true,
            'auto_rejection_reason' => 'İlk ret', 'rejection_reason' => 'İlk ret',
        ]);
        $this->mock(NotificationService::class)->shouldReceive('sendTemplatedEmail')->andReturn(0);
        $this->actAs('super_admin');
        $url = '/api/panel/applications/'.$application->id;
        $this->postJson($url.'/reopen-auto-rejection', ['reason' => 'Kural yanlış yapılandırılmıştı.'])
            ->assertOk()->assertJsonPath('follow_up.status_email_sent', false);
        $this->assertSame('pending', $application->fresh()->status);
        $this->postJson($url.'/reopen-auto-rejection', ['reason' => 'İkinci kez deneme yapılıyor.'])->assertUnprocessable();

        $this->mock(NotificationService::class)->shouldReceive('sendTemplatedEmail')->andReturn(1);
        $this->postJson($url.'/notification-retry', ['type' => 'status'])
            ->assertOk()->assertJsonPath('sent', true);
        $this->assertDatabaseCount('applications', 1);
        $this->assertSame('pending', $application->fresh()->status);
    }
}
