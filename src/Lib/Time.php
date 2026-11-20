<?php

namespace MehediJaman\LaravelZkteco\Lib;

use MehediJaman\LaravelZkteco\LaravelZkteco;
use RuntimeException;

class Time
{
    /**
     * @param  string  $t  Format: "Y-m-d H:i:s"
     * @return bool|mixed
     */
    public static function set(LaravelZkteco $self, $t)
    {
        $self->_section = __METHOD__;

        $command = Util::CMD_SET_TIME;
        $command_string = pack('V', Util::encodeTime($t));

        $self->_command($command, $command_string);
        if (! Util::checkValid($self->_data_recv)) {
            throw new RuntimeException('Terminal did not acknowledge the time update.', LaravelZkteco::ERROR_PROTOCOL);
        }

        return true;
    }

    /**
     * @return bool|mixed
     */
    public static function get(LaravelZkteco $self)
    {
        $self->_section = __METHOD__;

        $command = Util::CMD_GET_TIME;
        $command_string = '';

        $ret = $self->_command($command, $command_string);

        if (strlen($ret) !== 4) {
            throw new RuntimeException('Invalid terminal time response.', LaravelZkteco::ERROR_PROTOCOL);
        }

        return Util::decodeTime(unpack('V', $ret)[1]);
    }
}
