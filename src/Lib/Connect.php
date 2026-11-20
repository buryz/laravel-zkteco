<?php

namespace MehediJaman\LaravelZkteco\Lib;

use MehediJaman\LaravelZkteco\LaravelZkteco;

class Connect
{
    /**
     * @param  ZKTeco  $self
     * @return bool
     */
    public static function connect(LaravelZkteco $self)
    {
        $self->_section = __METHOD__;

        $self->_session_id = 0;
        $self->_data_recv = '';
        $self->_command(Util::CMD_CONNECT, '');
        $self->_session_id = unpack('v', substr($self->_data_recv, 4, 2))[1];

        return true;
    }

    /**
     * @param  ZKTeco  $self
     * @return bool
     */
    public static function disconnect(LaravelZkteco $self)
    {
        $self->_section = __METHOD__;

        try {
            $self->_command(Util::CMD_EXIT, '');

            return true;
        } finally {
            $self->_session_id = 0;
        }
    }
}
