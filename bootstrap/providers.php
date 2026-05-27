<?php

use App\Domains\Auth\Providers\FortifyServiceProvider;
use App\Domains\Notifications\Providers\MailSettingsServiceProvider;
use App\Domains\Operations\Providers\HorizonServiceProvider;
use App\Domains\Tenancy\Providers\TenancyServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    HorizonServiceProvider::class,
    MailSettingsServiceProvider::class,
    TenancyServiceProvider::class,
];
