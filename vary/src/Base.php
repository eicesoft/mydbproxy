<?php
namespace Vary;

use Closure;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Vary\Package\Error;
use Vary\Proxy\MySQLException;
use function Vary\Helper\arrayIconv;


class Base
{
    protected static $pool = [];

    public static function get(string $key)
    {
        $cid = Coroutine::getuid();
        if ($cid < 0) {
            return null;
        }
        if (isset(self::$pool[$cid][$key])) {
            return self::$pool[$cid][$key];
        }

        return null;
    }

    public static function put(string $key, $item)
    {
        $cid = Coroutine::getuid();
        if ($cid > 0) {
            self::$pool[$cid][$key] = $item;
        }
    }

    public static function delete(string $key = null)
    {
        $cid = Coroutine::getuid();
        if ($cid > 0) {
            if ($key) {
                unset(self::$pool[$cid][$key]);
            } else {
                unset(self::$pool[$cid]);
            }
        }
    }

    /**
     * 协程执行处理异常.
     *
     * @param $function
     */
    protected static function go(Closure $function)
    {
        if (-1 !== Coroutine::getuid()) {
            $pool = self::$pool[Coroutine::getuid()] ?? false;
        } else {
            $pool = false;
        }
        go(function () use ($function, $pool) {
            try {
                if ($pool) {
                    self::$pool[Coroutine::getuid()] = $pool;
                }
                $function();
                if ($pool) {
                    unset(self::$pool[Coroutine::getuid()]);
                }
            } catch (VaryException $ex) {
                Console::error($ex->getMessage());
            } catch (MySQLException $ex) {
                Console::error($ex->getMessage());
            }
        });
    }

    /**
     * 协程 pop
     *
     * @param $chan
     * @param int $timeout
     *
     * @return mixed
     */
    protected static function coPop(Channel $chan, int $timeout = 0)
    {
        if (version_compare(swoole_version(), '4.0.3', '>=')) {
            return $chan->pop($timeout);
        } else {
            if (0 == $timeout) {
                return $chan->pop();
            } else {
                $writes = [];
                $reads = [$chan];
                $result = $chan->select($reads, $writes, $timeout);
                if (false === $result || empty($reads)) {
                    return false;
                }
                $readChannel = $reads[0];
                return $readChannel->pop();
            }
        }
    }


    protected static function writeErrMessage(int $id, string $msg, int $errno = 0, $sqlState = 'HY000')
    {
        $err = new Error();
        $err->packetId = $id;
        if ($errno) {
            $err->errno = $errno;
        }
        $err->sqlState = $sqlState;
        $err->message  = arrayIconv($msg);

        return $err->write();
    }

}