<?php

namespace MehediJaman\LaravelZkteco;

use MehediJaman\LaravelZkteco\Lib\Attendance;
use MehediJaman\LaravelZkteco\Lib\Connect;
use MehediJaman\LaravelZkteco\Lib\Device;
use MehediJaman\LaravelZkteco\Lib\Face;
use MehediJaman\LaravelZkteco\Lib\Fingerprint;
use MehediJaman\LaravelZkteco\Lib\Os;
use MehediJaman\LaravelZkteco\Lib\Pin;
use MehediJaman\LaravelZkteco\Lib\Platform;
use MehediJaman\LaravelZkteco\Lib\SerialNumber;
use MehediJaman\LaravelZkteco\Lib\Ssr;
use MehediJaman\LaravelZkteco\Lib\Time;
use MehediJaman\LaravelZkteco\Lib\User;
use MehediJaman\LaravelZkteco\Lib\Util;
use MehediJaman\LaravelZkteco\Lib\Version;
use MehediJaman\LaravelZkteco\Lib\WorkCode;
use RuntimeException;

class LaravelZkteco
{
    public const ERROR_TIMEOUT = 1;

    public const ERROR_UNAUTHORIZED = 2;

    public const ERROR_PROTOCOL = 3;

    public const ERROR_REJECTED = 4;

    public const ERROR_SOCKET = 5;

    public const ERROR_INCOMPLETE_TRANSFER = 6;

    private float $timeoutSeconds;

    private ?float $operationDeadline;

    private int $replyId = Util::USHRT_MAX - 1;

    public $_ip;

    public $_port;

    public $_zkclient;

    public $_data_recv = '';

    public $_session_id = 0;

    public $_section = '';

    /**
     * ZKLib constructor.
     *
     * @param  string  $ip  Device IP
     * @param  int  $port  Default: 4370
     * @param  float  $timeoutSeconds  Maximum wait per response
     * @param  ?float  $operationTimeoutSeconds  Total deadline from construction, or null
     */
    public function __construct($ip, $port = 4370, $timeoutSeconds = 60.5, $operationTimeoutSeconds = null)
    {
        if (! is_numeric($timeoutSeconds) || ! is_finite((float) $timeoutSeconds) || $timeoutSeconds <= 0) {
            throw new \InvalidArgumentException('Timeout must be a positive finite number of seconds.');
        }
        if ($operationTimeoutSeconds !== null && (! is_numeric($operationTimeoutSeconds) || ! is_finite((float) $operationTimeoutSeconds) || $operationTimeoutSeconds <= 0)) {
            throw new \InvalidArgumentException('Operation timeout must be a positive finite number of seconds.');
        }

        $this->_ip = $ip;
        $this->_port = $port;
        $this->timeoutSeconds = (float) $timeoutSeconds;
        $this->operationDeadline = $operationTimeoutSeconds === null ? null : hrtime(true) / 1e9 + (float) $operationTimeoutSeconds;

        $this->_zkclient = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($this->_zkclient === false) {
            throw new RuntimeException('Unable to create terminal socket.', self::ERROR_SOCKET);
        }
    }

    /**
     * Create and send command to device
     *
     * @param  string  $command
     * @param  string  $command_string
     * @param  string  $type
     * @return bool|mixed
     */
    public function _command($command, $command_string, $type = Util::COMMAND_TYPE_GENERAL)
    {
        $session_id = $this->_session_id;
        if ($command !== Util::CMD_CONNECT && $session_id === 0) {
            throw new RuntimeException('Terminal session is not connected.', self::ERROR_PROTOCOL);
        }

        $buf = Util::createHeader($command, 0, $session_id, $this->replyId, $command_string);
        $expectedReply = unpack('v', substr($buf, 6, 2))[1];
        $this->replyId = $expectedReply;
        $this->_data_recv = '';

        if (! $this->_zkclient instanceof \Socket) {
            throw new RuntimeException('Terminal socket is closed.', self::ERROR_SOCKET);
        }
        $deadline = $this->deadline();
        if (@socket_sendto($this->_zkclient, $buf, strlen($buf), 0, $this->_ip, $this->_port) !== strlen($buf)) {
            throw new RuntimeException('Unable to send terminal command.', self::ERROR_SOCKET);
        }

        $packet = $this->receivePacket($deadline);
        $header = $this->validatePacket($packet, $command === Util::CMD_CONNECT ? null : $session_id);
        if ($header['reply'] !== $expectedReply) {
            throw new RuntimeException('Unexpected terminal reply identifier.', self::ERROR_PROTOCOL);
        }
        if ($header['command'] === Util::CMD_ACK_UNAUTH) {
            throw new RuntimeException('Terminal access is unauthorized.', self::ERROR_UNAUTHORIZED);
        }
        if ($header['command'] === Util::CMD_ACK_ERROR) {
            throw new RuntimeException('Terminal rejected the command.', self::ERROR_REJECTED);
        }
        $allowed = $type === Util::COMMAND_TYPE_DATA
            ? [Util::CMD_PREPARE_DATA, Util::CMD_ACK_OK]
            : [Util::CMD_ACK_OK, Util::CMD_ACK_DATA];
        if (! in_array($header['command'], $allowed, true)) {
            throw new RuntimeException('Unexpected terminal acknowledgement.', self::ERROR_PROTOCOL);
        }
        if ($command === Util::CMD_CONNECT && ($header['command'] !== Util::CMD_ACK_OK || $header['session'] === 0)) {
            throw new RuntimeException('Invalid terminal connection response.', self::ERROR_PROTOCOL);
        }
        if ($header['command'] === Util::CMD_PREPARE_DATA && strlen($packet) !== 12) {
            throw new RuntimeException('Invalid terminal transfer size response.', self::ERROR_PROTOCOL);
        }

        $this->_data_recv = $packet;
        $this->assertOperationActive();

        return $type === Util::COMMAND_TYPE_DATA ? $header['session'] : substr($packet, 8);
    }

    public function deadline(): float
    {
        $now = hrtime(true) / 1e9;
        $this->assertOperationActive();

        return min($now + $this->timeoutSeconds, $this->operationDeadline ?? INF);
    }

    public function assertOperationActive(): void
    {
        $now = hrtime(true) / 1e9;
        if ($this->operationDeadline !== null && $now >= $this->operationDeadline) {
            throw new RuntimeException('Terminal operation timed out.', self::ERROR_TIMEOUT);
        }
    }

    public function transferDeadline(): float
    {
        return $this->operationDeadline ?? $this->deadline();
    }

    public function rememberReply(int $replyId): void
    {
        $this->replyId = $replyId;
    }

    public function receivePacket(float $deadline): string
    {
        $deadline = min($deadline, $this->deadline());
        while (true) {
            $remaining = $deadline - hrtime(true) / 1e9;
            if ($remaining <= 0) {
                throw new RuntimeException('Terminal response timed out.', self::ERROR_TIMEOUT);
            }
            $read = [$this->_zkclient];
            $write = null;
            $except = null;
            $seconds = (int) $remaining;
            $microseconds = (int) (($remaining - $seconds) * 1_000_000);
            $ready = @socket_select($read, $write, $except, $seconds, $microseconds);
            if ($ready === false) {
                throw new RuntimeException('Unable to wait for terminal response.', self::ERROR_SOCKET);
            }
            if ($ready === 0) {
                continue;
            }
            $address = '';
            $port = 0;
            $packet = '';
            if (@socket_recvfrom($this->_zkclient, $packet, 65535, 0, $address, $port) === false) {
                throw new RuntimeException('Unable to receive terminal response.', self::ERROR_SOCKET);
            }
            if ($address === $this->_ip && $port === (int) $this->_port) {
                if (hrtime(true) / 1e9 >= $deadline) {
                    throw new RuntimeException('Terminal response timed out.', self::ERROR_TIMEOUT);
                }
                $this->assertOperationActive();

                return $packet;
            }
        }
    }

    /** @return array{command: int, checksum: int, session: int, reply: int} */
    public function validatePacket(string $packet, ?int $session): array
    {
        if (strlen($packet) < 8) {
            throw new RuntimeException('Truncated terminal packet.', self::ERROR_PROTOCOL);
        }
        $header = unpack('vcommand/vchecksum/vsession/vreply', $packet);
        if ($session !== null && $header['session'] !== $session) {
            throw new RuntimeException('Unexpected terminal session.', self::ERROR_PROTOCOL);
        }

        return $header;
    }

    public function close(): void
    {
        if ($this->_zkclient instanceof \Socket) {
            socket_close($this->_zkclient);
            $this->_zkclient = null;
        }
        $this->_session_id = 0;
        $this->_data_recv = '';
    }

    /**
     * Connect to device
     *
     * @return bool
     */
    public function connect()
    {
        return Connect::connect($this);
    }

    /**
     * Disconnect from device
     *
     * @return bool
     */
    public function disconnect()
    {
        return Connect::disconnect($this);
    }

    /**
     * Get device version
     *
     * @return bool|mixed
     */
    public function version()
    {
        return Version::get($this);
    }

    /**
     * Get OS version
     *
     * @return bool|mixed
     */
    public function osVersion()
    {
        return Os::get($this);
    }

    /**
     * Get platform
     *
     * @return bool|mixed
     */
    public function platform()
    {
        return Platform::get($this);
    }

    /**
     * Get firmware version
     *
     * @return bool|mixed
     */
    public function fmVersion()
    {
        return Platform::getVersion($this);
    }

    /**
     * Get work code
     *
     * @return bool|mixed
     */
    public function workCode()
    {
        return WorkCode::get($this);
    }

    /**
     * Get SSR
     *
     * @return bool|mixed
     */
    public function ssr()
    {
        return Ssr::get($this);
    }

    /**
     * Get pin width
     *
     * @return bool|mixed
     */
    public function pinWidth()
    {
        return Pin::width($this);
    }

    /**
     * @return bool|mixed
     */
    public function faceFunctionOn()
    {
        return Face::on($this);
    }

    /**
     * Get device serial number
     *
     * @return bool|mixed
     */
    public function serialNumber()
    {
        return SerialNumber::get($this);
    }

    /**
     * Get device name
     *
     * @return bool|mixed
     */
    public function deviceName()
    {
        return Device::name($this);
    }

    /**
     * Disable device
     *
     * @return bool|mixed
     */
    public function disableDevice()
    {
        return Device::disable($this);
    }

    /**
     * Enable device
     *
     * @return bool|mixed
     */
    public function enableDevice()
    {
        return Device::enable($this);
    }

    /**
     * Get users data
     *
     * @return array [userid, name, cardno, uid, role, password]
     */
    public function getUser()
    {
        return User::get($this);
    }

    /**
     * Set user data
     *
     * @param  int  $uid  Unique ID (max 65535)
     * @param  int|string  $userid  ID in DB (same like $uid, max length = 9, only numbers - depends device setting)
     * @param  string  $name  (max length = 24)
     * @param  int|string  $password  (max length = 8, only numbers - depends device setting)
     * @param  int  $role  Default Util::LEVEL_USER
     * @param  int  $cardno  Default 0 (max length = 10, only numbers)
     * @return bool|mixed
     */
    public function setUser($uid, $userid, $name, $password, $role = Util::LEVEL_USER, $cardno = 0)
    {
        return User::set($this, $uid, $userid, $name, $password, $role, $cardno);
    }

    /**
     * Remove All users
     *
     * @return bool|mixed
     */
    public function clearUsers()
    {
        return User::clear($this);
    }

    /**
     * Remove admin
     *
     * @return bool|mixed
     */
    public function clearAdmin()
    {
        return User::clearAdmin($this);
    }

    /**
     * Remove user by UID
     *
     * @param  int  $uid
     * @return bool|mixed
     */
    public function removeUser($uid)
    {
        return User::remove($this, $uid);
    }

    /**
     * Get fingerprint data array by UID
     * TODO: Can get data, but don't know how to parse the data. Need more documentation about it...
     *
     * @param  int  $uid  Unique ID (max 65535)
     * @return array Binary fingerprint data array (where key is finger ID (0-9))
     */
    public function getFingerprint($uid)
    {
        return Fingerprint::get($this, $uid);
    }

    /**
     * Set fingerprint data array
     * TODO: Still can not set fingerprint. Need more documentation about it...
     *
     * @param  int  $uid  Unique ID (max 65535)
     * @param  array  $data  Binary fingerprint data array (where key is finger ID (0-9) same like returned array from 'getFingerprint' method)
     * @return int Count of added fingerprints
     */
    public function setFingerprint($uid, array $data)
    {
        return Fingerprint::set($this, $uid, $data);
    }

    /**
     * Remove fingerprint by UID and fingers ID array
     *
     * @param  int  $uid  Unique ID (max 65535)
     * @param  array  $data  Fingers ID array (0-9)
     * @return int Count of deleted fingerprints
     */
    public function removeFingerprint($uid, array $data)
    {
        return Fingerprint::remove($this, $uid, $data);
    }

    /**
     * Get attendance log
     *
     * @return array [uid, id, state, timestamp]
     */
    public function getAttendance()
    {
        return Attendance::get($this);
    }

    /**
     * Clear attendance log
     *
     * @return bool|mixed
     */
    public function clearAttendance()
    {
        return Attendance::clear($this);
    }

    /**
     * Set device time
     *
     * @param  string  $t  Format: "Y-m-d H:i:s"
     * @return bool|mixed
     */
    public function setTime($t)
    {
        return Time::set($this, $t);
    }

    /**
     * Get device time
     *
     * @return bool|mixed Format: "Y-m-d H:i:s"
     */
    public function getTime()
    {
        return Time::get($this);
    }

    /**
     * turn off the device
     *
     * @return bool|mixed
     */
    public function shutdown()
    {
        return Device::powerOff($this);
    }

    /**
     * restart the device
     *
     * @return bool|mixed
     */
    public function restart()
    {
        return Device::restart($this);
    }

    /**
     * make sleep mood the device
     *
     * @return bool|mixed
     */
    public function sleep()
    {
        return Device::sleep($this);
    }

    /**
     * resume the device from sleep
     *
     * @return bool|mixed
     */
    public function resume()
    {
        return Device::resume($this);
    }

    /**
     * voice test Sound will "Thank you"
     *
     * @return bool|mixed
     */
    public function testVoice()
    {
        return Device::testVoice($this);
    }

    public function clearLCD()
    {
        return Device::clearLCD($this);
    }

    public function writeLCD()
    {
        return Device::writeLCD($this, 2, 'Laravel ZkTeco');
    }
}
