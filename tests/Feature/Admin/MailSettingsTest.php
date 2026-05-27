<?php

use App\Domains\Notifications\Mail\TestMail;
use App\Domains\Notifications\Models\MailSetting;
use App\Domains\Users\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->forceFill(['is_super_admin' => true])->save();
});

it('saves mail settings', function () {
    $this->actingAs($this->admin)->patch('/admin/mail', [
        'mailer' => 'smtp',
        'host' => 'mailpit',
        'port' => 1025,
        'encryption' => null,
        'username' => null,
        'from_address' => 'team@example.test',
        'from_name' => 'Team',
        'enabled' => true,
    ])->assertRedirect();

    $settings = MailSetting::first();
    expect($settings->from_address)->toBe('team@example.test')
        ->and($settings->host)->toBe('mailpit');
});

it('queues a test mail to the admin', function () {
    Mail::fake();

    $this->actingAs($this->admin)
        ->post('/admin/mail/test')
        ->assertRedirect();

    Mail::assertQueued(TestMail::class, function (TestMail $mail) {
        return $mail->hasTo($this->admin->email);
    });
});
