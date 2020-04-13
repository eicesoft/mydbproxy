<?php

namespace Vary\Proxy;


use swoole_server;
use Vary\Base;
use Vary\Console;
use Vary\VaryException;

class PoolManager extends Base
{
    private static $pools;

    /**
     * @param swoole_server $server
     */
    public static function init($server)
    {
        $configs = PROXY_CONFIG['hosts'];
        foreach ($configs as $key => $cfg) {
            Console::debug("Init {$key} db pool...");
            $pool = new MySQLPool($key);
            $pool->init($server);
            self::$pools[$key] = $pool;
        }
    }

    /**
     * @param string $key
     * @return mixed|null
     * @throws VaryException
     */
    public static function get($key)
    {
        if (!isset(self::$pools[$key])) {
            throw new VaryException("Pool {$key} is not exists");
        }

        return self::$pools[$key];
    }
}