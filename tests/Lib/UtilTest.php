<?php

use MehediJaman\LaravelZkteco\Lib\Util;

it('decodes an attendance timestamp with nonzero seconds without deprecations', function () {
    set_error_handler(static function (int $severity, string $message): never {
        throw new ErrorException($message, 0, $severity);
    }, E_DEPRECATED);

    try {
        $timestamp = Util::decodeTime(864142506);
    } finally {
        restore_error_handler();
    }

    expect($timestamp)->toBe('2026-11-20 15:35:06');
});

it('preserves terminal wall clock values across default timezones and DST gaps', function () {
    $original = date_default_timezone_get();
    try {
        date_default_timezone_set('Europe/Warsaw');
        $encoded = Util::encodeTime('2026-03-29 02:30:00');
        expect(Util::decodeTime($encoded))->toBe('2026-03-29 02:30:00');
        date_default_timezone_set('Pacific/Auckland');
        expect(Util::encodeTime('2026-03-29 02:30:00'))->toBe($encoded);
        expect(Util::decodeTime($encoded))->toBe('2026-03-29 02:30:00');
        expect(Util::decodeTime(Util::encodeTime('2026-10-25 02:30:00')))->toBe('2026-10-25 02:30:00');
    } finally {
        date_default_timezone_set($original);
    }
});

it('rejects invalid wall clock dates', function () {
    expect(fn () => Util::encodeTime('2026-02-30 12:00:00'))->toThrow(InvalidArgumentException::class);
    expect(fn () => Util::decodeTime(-1))->toThrow(RuntimeException::class);
});
