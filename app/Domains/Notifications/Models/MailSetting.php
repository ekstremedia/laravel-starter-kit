<?php

namespace App\Domains\Notifications\Models;

use Illuminate\Database\Eloquent\Model;

class MailSetting extends Model
{
    protected $fillable = [
        'mailer', 'host', 'port', 'encryption', 'username', 'password',
        'from_address', 'from_name', 'enabled',
    ];

    protected $casts = [
        'password' => 'encrypted',
        'enabled' => 'boolean',
        'port' => 'integer',
    ];

    public static function current(): self
    {
        return static::query()->firstOrCreate([], [
            'mailer' => config('mail.default', 'smtp'),
            'host' => config('mail.mailers.smtp.host'),
            'port' => config('mail.mailers.smtp.port'),
            'encryption' => config('mail.mailers.smtp.encryption'),
            'username' => config('mail.mailers.smtp.username'),
            'from_address' => config('mail.from.address'),
            'from_name' => config('mail.from.name'),
            'enabled' => true,
        ]);
    }

    public function applyToConfig(): void
    {
        config([
            'mail.default' => $this->mailer,
            'mail.mailers.smtp.host' => $this->host,
            'mail.mailers.smtp.port' => $this->port,
            'mail.mailers.smtp.encryption' => $this->encryption,
            'mail.mailers.smtp.username' => $this->username,
            'mail.mailers.smtp.password' => $this->password,
            'mail.from.address' => $this->from_address,
            'mail.from.name' => $this->from_name,
        ]);
    }
}
