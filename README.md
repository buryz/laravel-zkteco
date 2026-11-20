# Laravel ZKTeco Integration

[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/buryz/laravel-zkteco/run-tests.yml?branch=laravel-13-compat&label=tests&style=flat-square)](https://github.com/buryz/laravel-zkteco/actions)

Laravel 13 compatibility fork of [mehedijaman/laravel-zkteco](https://github.com/mehedijaman/laravel-zkteco).
The original author attribution and MIT license are retained. The Composer name
remains `mehedijaman/laravel-zkteco` so existing imports and dependencies work;
install this fork through the VCS repository below, not the upstream Packagist release.

## Overview
The Laravel ZKTeco package provides an easy way to integrate ZKTeco biometric devices with your Laravel application. This package simplifies the process of connecting to ZKTeco devices, fetching attendance logs, and managing user data.

## Laravel compatibility

This fork is configured for Laravel 13 while retaining the declared Laravel 10, 11, and 12 compatibility. The test workflow targets Laravel 13 on PHP 8.4. Device and firmware compatibility still needs to be verified against the specific ZKTeco terminal.

Attendance downloads also support the legacy UDP format observed on firmware
`Ver 6.70 Aug 27 2014`: a 16-byte preparation response, numbered 1024-byte
data packets, a byte-count completion acknowledgement, and length-prefixed
40-byte records. Packet order, declared lengths, and transfer completion are
validated; an interrupted download is not returned as an empty attendance list.

## Features
-   **Easy Integration:** Seamlessly connect your Laravel application with ZKTeco biometric devices.
-   **Attendance Management:** Fetch and manage attendance logs from connected devices.
-   **User Management:** Add, update, and remove users on the biometric device directly from your application.
-   **Real-time Data Sync:** Ensure that your application always has the latest attendance data.

## Installation

Install the fork from its Laravel 13 branch:

```bash
composer config repositories.zkteco vcs https://github.com/buryz/laravel-zkteco
composer require mehedijaman/laravel-zkteco:dev-laravel-13-compat
```
Commit the application's `composer.lock` and use `composer install` for deployment
to reproduce the tested commit. No Packagist publication is required.
The package is designed to automatically register itself upon installation.

Please ensure that the PHP sockets extension is enabled on your server. If it is not enabled, you will need to activate it.

## Activate PHP Socket
Ensure that the PHP sockets extension is enabled on your server. If it is not enabled, follow these steps to activate it:

Locate the php.ini File:
The php.ini file's location depends on your PHP installation. Common locations include:

-   /etc/php/8.x/cli/php.ini (for CLI)
-   /etc/php/8.x/apache2/php.ini (for Apache)
-   /etc/php/8.x/fpm/php.ini (for PHP-FPM)

Edit the php.ini File:
Open the php.ini file in a text editor with superuser privileges:

```
sudo nano /etc/php/7.x/apache2/php.ini
```
Uncomment the Sockets Extension:
Find the following line:

```
;extension=sockets
```

Remove the semicolon (;) to uncomment the line:
```
extension=sockets
```

Save and Exit:
Save the changes and exit the editor (Ctrl + X, Y, Enter).

Restart the Web Server:
Restart the web server to apply the changes:

```
sudo systemctl restart apache2
```

## Usage

Instantiate the LaravelZkteco Object.
```php
use MehediJaman\LaravelZkteco\LaravelZkteco;
$zk = new LaravelZkteco('ipaddress', 'port');
```

For bounded reader operations, pass the receive timeout and the total operation
timeout (seconds) as the third and fourth arguments:

```php
$zk = new LaravelZkteco('192.0.2.10', 4370, 3, 40);
try {
    $zk->connect();
    $serial = $zk->serialNumber();
    $firmware = $zk->version();
    $time = $zk->getTime();
    $events = $zk->getAttendance();
    // $zk->setTime('2026-11-20 15:35:06');
} finally {
    try {
        if ($zk->_session_id !== 0) {
            $zk->disconnect();
        }
    } finally {
        $zk->close();
    }
}
```

The total deadline starts at construction and covers subsequent commands and
attendance processing; it is not renewed per command. Without a fourth
argument, each command and each attendance transfer is separately bounded by
the receive timeout (default 60.5 seconds). Closing the socket does not send a
disconnect command. Disconnect can itself fail or time out, including when the
operation deadline is exhausted, so always close the socket in `finally`.

Transport and protocol failures throw `RuntimeException`. Its code is one of
`LaravelZkteco::ERROR_TIMEOUT`, `ERROR_UNAUTHORIZED`, `ERROR_PROTOCOL`,
`ERROR_REJECTED`, `ERROR_SOCKET`, or `ERROR_INCOMPLETE_TRANSFER`. A successful
`getAttendance()` returns an array; `[]` means an acknowledged empty transfer,
not a timeout or partial transfer. `setTime()` returns `true` only for an OK
acknowledgement; an empty payload is valid. An acknowledgement does not prove
the clock was set: read it back. A timeout after sending a write leaves its
effect unknown; do not retry blindly.

Times are timezone-free terminal wall-clock strings in `Y-m-d H:i:s` format,
not UTC instants. The caller must handle timezone and DST ambiguity. The
attendance reader supports the existing 40-byte record layout and rejects
incomplete or unsupported framing. The transfer framing and compatibility with
individual terminal firmware still require validation on a physical reader.


Call ZKTeco methods

* __Connect__ 
```php
//    connect device
//    this return bool
    $zk->connect();   
```

* __Disconnect__ 
```php
//    disconnect device
//    this return bool

    $zk->disconnect();   
```

* __Enable Device__ 
```php
//    enable devices
//    this return bool/mixed

    $zk->enableDevice();   
```
> **NOTE**: You have to call after read/write any info of Device.

* __Disable Device__ 
```php
//    disable  device
//    this return bool/mixed

    $zk->disableDevice(); 
```
> **NOTE**: You have to call before read/write any info of Device. 


* __Device Version__ 
```php
//    get device version 
//    this return bool/mixed

    $zk->version(); 
```


* __Device Os Version__ 
```php
//    get device os version 
//    this return bool/mixed

    $zk->osVersion(); 
```

* __Power Off__ 
```php
//    turn off the device 
//    this return bool/mixed

    $zk->shutdown(); 
```

* __Restart__ 
```php
//    restart the device 
//    this return bool/mixed

    $zk->restart(); 
```

* __Sleep__ 
```php
//    sleep the device 
//    this return bool/mixed

    $zk->sleep(); 
```

* __Resume__ 
```php
//    resume the device from sleep 
//    this return bool/mixed

    $zk->resume(); 
```

* __Voice Test__ 
```php
//    voice test of the device "Thank you" 
//    this return bool/mixed

    $zk->testVoice(); 
```

* __Platform__ 
```php
//    get platform 
//    this return bool/mixed

    $zk->platform(); 
```

* __Firmware Version__ 
```php
//    get firmware version
//    this return bool/mixed

    $zk->fmVersion(); 
```

* __Work Code__ 
```php
//    get work code
//    this return bool/mixed

    $zk->workCode(); 
```

* __SSR__ 
```php
//    get SSR
//    this return bool/mixed

    $zk->ssr(); 
```

* __Pin Width__ 
```php
//    get  Pin Width
//    this return bool/mixed

    $zk->pinWidth(); 
```

* __Serial Number__ 
```php
//    get device serial number
//    this return bool/mixed

    $zk->serialNumber(); 
```

* __Device Name__ 
```php
//    get device name
//    this return bool/mixed

    $zk->deviceName(); 
```

* __Get Device Time__ 
```php
//    get device time

//    return bool/mixed bool|mixed Format: "Y-m-d H:i:s"

    $zk->getTime(); 
```

* __Set Device Time__ 
```php
//    set device time
//    parameter string $t Format: "Y-m-d H:i:s"
//    returns true on acknowledged success; throws on transport/protocol failure

    $zk->setTime('2026-11-20 15:35:06');
```

* __Get Users__ 
```php
//    get User
//    this return array[]

    $zk->getUser(); 
```

* __Set Users__ 
```php
//    set user

//    1 s't parameter int $uid Unique ID (max 65535)
//    2 nd parameter int|string $userid ID in DB (same like $uid, max length = 9, only numbers - depends device setting)
//    3 rd parameter string $name (max length = 24)
//    4 th parameter int|string $password (max length = 8, only numbers - depends device setting)
//    5 th parameter int $role Default Util::LEVEL_USER
//    6 th parameter int $cardno Default 0 (max length = 10, only numbers

//    return bool|mixed

    $zk->setUser(); 
```

* __Clear All Admin__ 
```php
//    remove all admin
//    return bool|mixed

    $zk->clearAdmin(); 
```

* __Clear All Users__ 
```php
//    remove all users
//    return bool|mixed

    $zk->clearAdmin(); 
```

* __Remove A User__ 
```php
//    remove a user by $uid
//    parameter integer $uid
//    return bool|mixed

    $zk->removeUser(); 
```

* __Get Attendance Log__ 
```php
//    get attendance log

//    return array[]

//    like as 0 => array:5 [▼
//              "uid" => 1      /* serial number of the attendance */
//              "id" => "1"     /* user id of the application */
//              "state" => 1    /* the authentication type, 1 for Fingerprint, 4 for RF Card etc */
//              "timestamp" => "2020-05-27 21:21:06" /* time of attendance */
//              "type" => 255   /* attendance type, like check-in, check-out, overtime-in, overtime-out, break-in & break-out etc. if attendance type is none of them, it gives  255. */
//              ]

    $zk->getAttendance(); 
```

* __Clear Attendance Log__ 
```php
//    clear attendance log

//    return bool/mixed

    $zk->clearAttendance(); 
```


## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Mehedi Jaman](https://github.com/mehedijaman)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
