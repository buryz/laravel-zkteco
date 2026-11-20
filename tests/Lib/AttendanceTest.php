<?php

use MehediJaman\LaravelZkteco\LaravelZkteco;
use MehediJaman\LaravelZkteco\Lib\Util;

it('returns the event when the terminal sends exactly one complete record', function () {
    $record = pack('vv', 0, 42).str_pad('00123', 24, "\0")
        .pack('CVC', 1, 864142506, 0).str_repeat("\0", 6);
    $payload = pack('vvvv', Util::CMD_DATA, 0, 1, 0).str_repeat("\0", 2).$record;
    $reader = new class('127.0.0.1') extends LaravelZkteco
    {
        public function _command($command, $command_string, $type = Util::COMMAND_TYPE_GENERAL): int
        {
            return 1;
        }
    };
    $reader->_data_recv = pack('vvvvV', Util::CMD_PREPARE_DATA, 0, 1, 0, strlen($payload) - 8);
    $reader->_session_id = 1;
    socket_bind($reader->_zkclient, '127.0.0.1', 0);
    socket_getsockname($reader->_zkclient, $address, $port);
    socket_set_option($reader->_zkclient, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 1, 'usec' => 0]);
    $sender = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    socket_bind($sender, '127.0.0.1', 0);
    socket_getsockname($sender, $senderAddress, $reader->_port);

    try {
        socket_sendto($sender, $payload, strlen($payload), 0, $address, $port);
        socket_sendto($sender, pack('vvvv', Util::CMD_ACK_OK, 0, 1, 0), 8, 0, $address, $port);

        $events = $reader->getAttendance();
    } finally {
        socket_close($sender);
        socket_close($reader->_zkclient);
    }

    expect($events)->toBe([[
        'uid' => 42,
        'id' => '00123',
        'state' => 1,
        'timestamp' => '2026-11-20 15:35:06',
        'type' => 0,
    ]]);
});
