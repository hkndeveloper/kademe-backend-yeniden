<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\NotificationService;
use Tests\TestCase;

class ApplicationPasswordEmailContentTest extends TestCase
{
    public function test_initial_setup_and_password_reset_explain_different_actions(): void
    {
        config(['services.frontend.url' => 'https://kademe.example']);
        $messages = [];
        $this->mock(NotificationService::class)
            ->shouldReceive('sendTemplatedEmail')
            ->twice()
            ->andReturnUsing(function ($recipients, $subject, $view, $data) use (&$messages) {
                $messages[] = compact('recipients', 'subject', 'view', 'data');

                return 1;
            });

        $user = new User([
            'name' => 'Ayşe',
            'surname' => 'Yılmaz',
            'email' => 'ayse@example.com',
            'must_change_password' => true,
        ]);
        $user->sendPasswordResetNotification('ilk-token');
        $user->must_change_password = false;
        $user->sendPasswordResetNotification('yenile-token');

        $this->assertSame('Şifremi belirle', $messages[0]['data']['action_text']);
        $this->assertSame('Şifremi yenile', $messages[1]['data']['action_text']);
        $this->assertStringContainsString('ilk-token', $messages[0]['data']['action_url']);
        $this->assertStringContainsString('yenile-token', $messages[1]['data']['action_url']);
        $this->assertStringNotContainsString('yönetici tarafından açıldı', $messages[1]['data']['plain_text']);
        $this->assertSame('emails.application-status', $messages[0]['view']);
    }
}
