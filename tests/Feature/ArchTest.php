<?php

arch('debugging statements are not committed')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r'])
    ->not->toBeUsed();

arch('admin controllers extend the base controller')
    ->expect('App\Http\Controllers\Admin')
    ->toExtend('App\Http\Controllers\Controller');

arch('admin form requests extend FormRequest')
    ->expect('App\Http\Requests\Admin')
    ->toExtend('Illuminate\Foundation\Http\FormRequest');

arch('models live under App\Models')
    ->expect('App\Models')
    ->toExtend('Illuminate\Database\Eloquent\Model')
    ->ignoring('App\Domains\Users\Models\User')
    ->ignoring('App\Models\Concerns');

arch('jobs implement ShouldQueue')
    ->expect('App\Jobs')
    ->toImplement('Illuminate\Contracts\Queue\ShouldQueue');

arch('events live in App\Events namespace')
    ->expect('App\Events')
    ->toBeClasses();

arch('mail classes extend Mailable')
    ->expect('App\Mail')
    ->toExtend('Illuminate\Mail\Mailable');
