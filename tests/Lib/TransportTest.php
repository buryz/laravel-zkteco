<?php

use MehediJaman\LaravelZkteco\LaravelZkteco;
use MehediJaman\LaravelZkteco\Lib\Util;

function withFakeTerminal(array $responses, Closure $test, float $timeout = 0.15, ?float $operationTimeout = null): void
{
    $server = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    socket_bind($server, '127.0.0.1', 0);
    socket_getsockname($server, $address, $port);
    socket_set_option($server, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 1, 'usec' => 0]);
    $pid = pcntl_fork();
    if ($pid === 0) {
        foreach ($responses as $response) {
            $peer = '';
            $peerPort = 0;
            $request = '';
            if (@socket_recvfrom($server, $request, 65535, 0, $peer, $peerPort) === false) {
                exit(1);
            }
            $reply = unpack('v', substr($request, 6, 2))[1];
            foreach ($response($reply, $request) as $packet) {
                if (is_array($packet)) {
                    usleep($packet['delay']);
                    $packet = $packet['packet'];
                }
                socket_sendto($server, $packet, strlen($packet), 0, $peer, $peerPort);
            }
        }
        socket_close($server);
        exit(0);
    }
    socket_close($server);
    $reader = new LaravelZkteco('127.0.0.1', $port, $timeout, $operationTimeout);
    try {
        $test($reader);
    } finally {
        $reader->close();
        pcntl_waitpid($pid, $status);
    }
    expect(pcntl_wexitstatus($status))->toBe(0);
}

function terminalReply(int $command, int $reply, int $session = 1, string $payload = ''): string
{
    return pack('vvvv', $command, 0, $session, $reply).$payload;
}

function successfulConnection(): Closure
{
    return fn (int $reply): array => [terminalReply(Util::CMD_ACK_OK, $reply)];
}

it('rejects unauthorized and malformed connection responses', function (Closure $response, int $code) {
    withFakeTerminal([$response], function (LaravelZkteco $reader) use ($code) {
        try {
            $reader->connect();
            test()->fail('Expected connection failure.');
        } catch (RuntimeException $exception) {
            expect($exception->getCode())->toBe($code);
            expect($reader->_session_id)->toBe(0);
        }
    });
})->with([
    'unauthorized' => [fn (int $reply): array => [terminalReply(Util::CMD_ACK_UNAUTH, $reply)], LaravelZkteco::ERROR_UNAUTHORIZED],
    'truncated' => [fn (): array => ["\x01\x00"], LaravelZkteco::ERROR_PROTOCOL],
    'wrong reply' => [fn (int $reply): array => [terminalReply(Util::CMD_ACK_OK, $reply + 1)], LaravelZkteco::ERROR_PROTOCOL],
    'zero session' => [fn (int $reply): array => [terminalReply(Util::CMD_ACK_OK, $reply, 0)], LaravelZkteco::ERROR_PROTOCOL],
]);

it('rejects wrong-session and error command responses without reusing stale data', function (Closure $response, int $code) {
    withFakeTerminal([successfulConnection(), $response], function (LaravelZkteco $reader) use ($code) {
        $reader->connect();
        try {
            $reader->serialNumber();
            test()->fail('Expected command failure.');
        } catch (RuntimeException $exception) {
            expect($exception->getCode())->toBe($code);
            expect($reader->_data_recv)->toBe('');
        }
    });
})->with([
    'wrong session' => [fn (int $reply): array => [terminalReply(Util::CMD_ACK_OK, $reply, 2)], LaravelZkteco::ERROR_PROTOCOL],
    'rejected' => [fn (int $reply): array => [terminalReply(Util::CMD_ACK_ERROR, $reply)], LaravelZkteco::ERROR_REJECTED],
    'unexpected acknowledgement' => [fn (int $reply): array => [terminalReply(Util::CMD_DATA, $reply)], LaravelZkteco::ERROR_PROTOCOL],
]);

it('distinguishes a timeout from an earlier successful response', function () {
    withFakeTerminal([successfulConnection(), fn (): array => []], function (LaravelZkteco $reader) {
        $reader->connect();
        try {
            $reader->serialNumber();
            test()->fail('Expected timeout.');
        } catch (RuntimeException $exception) {
            expect($exception->getCode())->toBe(LaravelZkteco::ERROR_TIMEOUT);
            expect($reader->_data_recv)->toBe('');
        }
    });
});

it('enforces the operation deadline across successive commands', function () {
    withFakeTerminal([
        fn (int $reply): array => [['delay' => 80000, 'packet' => terminalReply(Util::CMD_ACK_OK, $reply)]],
        fn (int $reply): array => [['delay' => 80000, 'packet' => terminalReply(Util::CMD_ACK_OK, $reply, 1, 'serial')]],
    ], function (LaravelZkteco $reader) {
        $reader->connect();
        try {
            $reader->serialNumber();
            test()->fail('Expected operation timeout.');
        } catch (RuntimeException $exception) {
            expect($exception->getCode())->toBe(LaravelZkteco::ERROR_TIMEOUT);
        }
    }, 0.3, 0.12);
});

it('does not accept a late response from an earlier command', function () {
    withFakeTerminal([
        successfulConnection(),
        fn (): array => [],
        fn (int $reply): array => [terminalReply(Util::CMD_ACK_OK, $reply - 1, 1, 'stale')],
    ], function (LaravelZkteco $reader) {
        $reader->connect();
        expect(fn () => $reader->serialNumber())->toThrow(RuntimeException::class, 'timed out');
        expect(fn () => $reader->version())->toThrow(RuntimeException::class, 'reply identifier');
    });
});

it('accepts an empty successful time acknowledgement but rejects a data acknowledgement', function (int $ack, bool $success) {
    withFakeTerminal([successfulConnection(), fn (int $reply): array => [terminalReply($ack, $reply)]], function (LaravelZkteco $reader) use ($success) {
        $reader->connect();
        if ($success) {
            expect($reader->setTime('2026-03-29 02:30:00'))->toBeTrue();
        } else {
            expect(fn () => $reader->setTime('2026-03-29 02:30:00'))->toThrow(RuntimeException::class);
        }
    });
})->with([
    'ok' => [Util::CMD_ACK_OK, true],
    'data is not an acknowledgement' => [Util::CMD_ACK_DATA, false],
]);

it('rejects a truncated time payload', function () {
    withFakeTerminal([successfulConnection(), fn (int $reply): array => [terminalReply(Util::CMD_ACK_OK, $reply, 1, "\x01")]], function (LaravelZkteco $reader) {
        $reader->connect();
        expect(fn () => $reader->getTime())->toThrow(RuntimeException::class);
    });
});

it('returns an empty attendance list only for a confirmed empty response', function (Closure $response) {
    withFakeTerminal([successfulConnection(), $response], function (LaravelZkteco $reader) {
        $reader->connect();
        expect($reader->getAttendance())->toBe([]);
    });
})->with([
    'empty acknowledgement' => [fn (int $reply): array => [terminalReply(Util::CMD_ACK_OK, $reply)]],
    'zero length transfer' => [fn (int $reply): array => [terminalReply(Util::CMD_PREPARE_DATA, $reply, 1, pack('V', 0))]],
]);

it('receives all attendance records across multiple packets', function () {
    $record = pack('vv', 0, 42).str_pad('00123', 24, "\0")
        .pack('CVC', 1, 864142506, 0).str_repeat("\0", 6);
    $stream = str_repeat("\0", 2).$record.$record;
    $size = strlen($stream);
    $response = fn (int $reply): array => [
        terminalReply(Util::CMD_PREPARE_DATA, $reply, 1, pack('V', $size)),
        terminalReply(Util::CMD_DATA, $reply, 1, substr($stream, 0, 25)),
        terminalReply(Util::CMD_DATA, $reply, 1, substr($stream, 25)),
        terminalReply(Util::CMD_ACK_OK, $reply),
    ];
    withFakeTerminal([successfulConnection(), $response], function (LaravelZkteco $reader) {
        $reader->connect();
        $records = $reader->getAttendance();
        expect($records)->toHaveCount(2);
        expect($records[0]['id'])->toBe('00123');
        expect($records[1]['timestamp'])->toBe('2026-11-20 15:35:06');
    });
});

it('rejects an incomplete, malformed or unacknowledged attendance transfer', function (Closure $response, int $code) {
    withFakeTerminal([successfulConnection(), $response], function (LaravelZkteco $reader) use ($code) {
        $reader->connect();
        try {
            $reader->getAttendance();
            test()->fail('Expected transfer failure.');
        } catch (RuntimeException $exception) {
            expect($exception->getCode())->toBe($code);
        }
    });
})->with([
    'incomplete transfer' => [fn (int $reply): array => [
        terminalReply(Util::CMD_PREPARE_DATA, $reply, 1, pack('V', 50)),
        terminalReply(Util::CMD_DATA, $reply, 1, 'partial'),
    ], LaravelZkteco::ERROR_INCOMPLETE_TRANSFER],
    'truncated record' => [fn (int $reply): array => [
        terminalReply(Util::CMD_PREPARE_DATA, $reply, 1, pack('V', 3)),
        terminalReply(Util::CMD_DATA, $reply, 1, 'abc'),
        terminalReply(Util::CMD_ACK_OK, $reply),
    ], LaravelZkteco::ERROR_PROTOCOL],
    'unsupported record framing' => [fn (int $reply): array => [
        terminalReply(Util::CMD_PREPARE_DATA, $reply, 1, pack('V', 4)),
        terminalReply(Util::CMD_DATA, $reply, 1, pack('V', 40)),
        terminalReply(Util::CMD_ACK_OK, $reply),
    ], LaravelZkteco::ERROR_PROTOCOL],
    'missing completion' => [fn (int $reply): array => [
        terminalReply(Util::CMD_PREPARE_DATA, $reply, 1, pack('V', 2)),
        terminalReply(Util::CMD_DATA, $reply, 1, "\0\0"),
    ], LaravelZkteco::ERROR_INCOMPLETE_TRANSFER],
    'wrong packet session' => [fn (int $reply): array => [
        terminalReply(Util::CMD_PREPARE_DATA, $reply, 1, pack('V', 2)),
        terminalReply(Util::CMD_DATA, $reply, 2, "\0\0"),
    ], LaravelZkteco::ERROR_PROTOCOL],
    'unauthorized completion' => [fn (int $reply): array => [
        terminalReply(Util::CMD_PREPARE_DATA, $reply, 1, pack('V', 2)),
        terminalReply(Util::CMD_DATA, $reply, 1, "\0\0"),
        terminalReply(Util::CMD_ACK_UNAUTH, $reply),
    ], LaravelZkteco::ERROR_UNAUTHORIZED],
]);

it('bounds the entire transfer even when packets continue to arrive', function () {
    $response = fn (int $reply): array => [
        terminalReply(Util::CMD_PREPARE_DATA, $reply, 1, pack('V', 100)),
        ['delay' => 70000, 'packet' => terminalReply(Util::CMD_DATA, $reply, 1, 'a')],
        ['delay' => 70000, 'packet' => terminalReply(Util::CMD_DATA, $reply, 1, 'b')],
        ['delay' => 70000, 'packet' => terminalReply(Util::CMD_DATA, $reply, 1, 'c')],
    ];
    withFakeTerminal([successfulConnection(), $response], function (LaravelZkteco $reader) {
        $reader->connect();
        $start = hrtime(true);
        try {
            $reader->getAttendance();
            test()->fail('Expected transfer timeout.');
        } catch (RuntimeException $exception) {
            expect($exception->getCode())->toBe(LaravelZkteco::ERROR_INCOMPLETE_TRANSFER);
            expect((hrtime(true) - $start) / 1e9)->toBeLessThan(0.5);
        }
    }, 0.3, 0.15);
});

it('rejects buffered transfer data after the operation deadline', function () {
    $sender = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    socket_bind($sender, '127.0.0.1', 0);
    socket_getsockname($sender, $senderAddress, $senderPort);
    $reader = new LaravelZkteco('127.0.0.1', $senderPort, 1, 0.03);
    socket_bind($reader->_zkclient, '127.0.0.1', 0);
    socket_getsockname($reader->_zkclient, $address, $port);
    $reader->_session_id = 1;
    $reader->_data_recv = terminalReply(Util::CMD_PREPARE_DATA, 0, 1, pack('V', 2));
    $packet = terminalReply(Util::CMD_DATA, 0, 1, "\0\0");
    socket_sendto($sender, $packet, strlen($packet), 0, $address, $port);
    usleep(60000);

    try {
        expect(fn () => Util::recData($reader))->toThrow(RuntimeException::class, 'timed out');
    } finally {
        socket_close($sender);
        $reader->close();
    }
});
