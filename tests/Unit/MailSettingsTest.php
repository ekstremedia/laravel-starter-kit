<?php

use App\Domains\Notifications\Models\MailSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a default singleton via current()', function () {
    $settings = MailSetting::current();

    expect($settings)->toBeInstanceOf(MailSetting::class)
        ->and(MailSetting::count())->toBe(1);

    // Calling current() again returns the same row
    MailSetting::current();
    expect(MailSetting::count())->toBe(1);
});

it('encrypts the password attribute', function () {
    $settings = MailSetting::current();
    $settings->password = 'secret-pw';
    $settings->save();

    $raw = DB::table('mail_settings')->where('id', $settings->id)->value('password');
    expect($raw)->not->toBe('secret-pw');

    expect($settings->fresh()->password)->toBe('secret-pw');
});

it('applies stored values to runtime mail config', function () {
    $settings = MailSetting::current();
    $settings->fill([
        'mailer' => 'smtp',
        'host' => 'relay.example.test',
        'port' => 2525,
        'encryption' => 'tls',
        'username' => 'alice',
        'password' => 'pw',
        'from_address' => 'no-reply@example.test',
        'from_name' => 'Team',
    ])->save();

    $settings->applyToConfig();

    expect(config('mail.default'))->toBe('smtp')
        ->and(config('mail.mailers.smtp.host'))->toBe('relay.example.test')
        ->and(config('mail.mailers.smtp.port'))->toBe(2525)
        ->and(config('mail.mailers.smtp.encryption'))->toBe('tls')
        ->and(config('mail.mailers.smtp.username'))->toBe('alice')
        ->and(config('mail.mailers.smtp.password'))->toBe('pw')
        ->and(config('mail.from.address'))->toBe('no-reply@example.test')
        ->and(config('mail.from.name'))->toBe('Team');
});
