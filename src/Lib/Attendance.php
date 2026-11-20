<?php

namespace MehediJaman\LaravelZkteco\Lib;

use MehediJaman\LaravelZkteco\LaravelZkteco;
use RuntimeException;

class Attendance
{
    /**
     * @return array [uid, id, state, timestamp]
     */
    public static function get(LaravelZkteco $self)
    {
        $self->_section = __METHOD__;

        $command = Util::CMD_ATT_LOG_RRQ;
        $command_string = '';

        $session = $self->_command($command, $command_string, Util::COMMAND_TYPE_DATA);
        if ($session === false) {
            throw new RuntimeException('Terminal attendance request failed.', LaravelZkteco::ERROR_PROTOCOL);
        }

        $legacy = strlen($self->_data_recv) === 16;
        $attData = Util::recData($self);

        $attendance = [];
        if (! empty($attData)) {
            $prefix = $legacy ? 12 : 10;
            if (strlen($attData) < $prefix || (strlen($attData) - $prefix) % 40 !== 0
                || ($legacy && unpack('V', substr($attData, 8, 4))[1] !== strlen($attData) - $prefix)) {
                throw new RuntimeException('Incomplete terminal attendance records.', LaravelZkteco::ERROR_PROTOCOL);
            }
            $attData = substr($attData, $prefix);
            $recordOffset = $legacy ? 0 : 2;

            for ($offset = 0, $length = strlen($attData); $offset < $length; $offset += 40) {
                $self->assertOperationActive();
                $record = substr($attData, $offset, 40);
                $uid = unpack('v', substr($record, $recordOffset, 2))[1];
                $id = substr($record, $recordOffset + 2, 9);
                $id = str_replace(chr(0), '', $id);
                $state = ord($record[$recordOffset + 26]);
                $timestamp = Util::decodeTime(unpack('V', substr($record, $recordOffset + 27, 4))[1]);
                $type = ord($record[$recordOffset + 31]);

                $attendance[] = [
                    'uid' => $uid,
                    'id' => $id,
                    'state' => $state,
                    'timestamp' => $timestamp,
                    'type' => $type,
                ];

            }

        }

        $self->assertOperationActive();

        return $attendance;
    }

    /**
     * @return bool|mixed
     */
    public static function clear(LaravelZkteco $self)
    {
        $self->_section = __METHOD__;

        $command = Util::CMD_CLEAR_ATT_LOG;
        $command_string = '';

        return $self->_command($command, $command_string);
    }
}
