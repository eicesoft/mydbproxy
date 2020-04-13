<?php


namespace Vary\Proxy;


use Co\Channel;
use Swoole\Exception;
use Vary\Base;

/**
 * MySQL Pool
 * @package Vary\Proxy
 */
class MySQLPool extends Base
{
    private $clients;
    private $size = 0;
    private $max = 0;
    private $min = 0;
    private $use = 0;
    private $server;
    private $key;

    public function __construct($key='write')
    {
        $cfg = PROXY_CONFIG['options']['pool'];
        $this->key = $key;
        $this->max = $cfg['max'] ?? 20;
        $this->min = $cfg['min'] ?? 10;
        $this->clients = [];
    }

    public function init($server)
    {
        $this->server = $server;
        $this->fill();
    }

    /**
     * create new connection
     * @return MySQLProxy
     */
    private function create()
    {
        $this->size++;

        $chan = new Channel(1);
        $client = new MySQLProxy($this->server, $chan);
        $client->setPool($this);
        $cfg = PROXY_CONFIG['hosts'][$this->key];
        $client->setAccount([
            'user' => $cfg['user'],
            'password' => $cfg['password'],
        ]);
        $client->connect($cfg['host'], $cfg['port'], $cfg['database']);

        return self::coPop($chan, 1 * 3);
    }

    /**
     * init pool
     */
    private function fill()
    {
        self::go(function() {
            for ($i = 0; $i < $this->min; $i++) {
                array_push($this->clients, $this->create());
            }
        });
    }

    /**
     * pool is full
     * @return bool
     */
    public function is_full()
    {
        return $this->use >= $this->max;
    }

    /**
     * get a connection
     * @return MySQLProxy
     * @throws Exception
     */
    public function gain()
    {
        if ($this->is_full()) {
            throw new Exception('pool is full');
        }

        if ($this->size == 0) {
            echo 'Pool create connect' . PHP_EOL;
            $conn = $this->create();
        } else {
            echo 'Pool get connect' . PHP_EOL;
            $conn = array_pop($this->clients);
        }

        $this->use++;

        return $conn;
    }

    /**
     * free connection
     * @param MySQLProxy $conn
     */
    public function free($conn)
    {
        $conn->close();
        $this->size--;
        $this->use--;
    }

    /**
     * release a connection
     * @param MySQLProxy $conn
     */
    public function release($conn)
    {
        $this->use--;
        array_push($this->clients, $conn);
    }
}