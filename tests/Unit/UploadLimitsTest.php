<?php

declare(strict_types=1);

use App\Domains\Files\Support\UploadLimits;

it('parses ini shorthand sizes into bytes', function () {
    expect(UploadLimits::parseIniSize('500M'))->toBe(524288000)
        ->and(UploadLimits::parseIniSize('2G'))->toBe(2147483648)
        ->and(UploadLimits::parseIniSize('51200K'))->toBe(52428800)
        ->and(UploadLimits::parseIniSize('1048576'))->toBe(1048576);
});

it('treats 0 and empty ini values as unlimited', function () {
    expect(UploadLimits::parseIniSize('0'))->toBe(PHP_INT_MAX)
        ->and(UploadLimits::parseIniSize(''))->toBe(PHP_INT_MAX);
});

it('lowercases the unit suffix', function () {
    expect(UploadLimits::parseIniSize('10m'))->toBe(10 * 1024 * 1024);
});
