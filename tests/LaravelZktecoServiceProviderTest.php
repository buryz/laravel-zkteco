<?php

it('loads the default zkteco configuration', function () {
    expect(config('zkteco.route'))->toBe('/zkteco');
    expect(config('zkteco.middleware'))->toBe(['web', 'auth']);
});
