<?php

namespace Vary;

use Exception;
use Swoole\Coroutine;
use swoole_server;
use Vary\Package\Auth;
use Vary\Package\Authenticator;
use Vary\Package\Binary;
use Vary\Package\MySQLDecoder;
use Vary\Package\Ok;
use Vary\Package\Package;
use Vary\Parser\QueryParser;
use Vary\Proxy\MySQLException;
use Vary\Proxy\MySQLPool;
use Vary\Proxy\MySQLProxy;
use Vary\Proxy\PoolManager;
use Vary\Util\ErrorCode;
use Vary\Util\Random;
use function Vary\Helper\array_copy;
use function Vary\Helper\getBytes;
use function Vary\Helper\getMysqlPackSize;
use function Vary\Helper\getString;

class Server extends Base
{
    /**
     * @var string
     */
    protected $host;

    /**
     * @var array
     */
    protected $params;

    /**
     * @var int
     */
    protected $port;

    /**
     * @var \Swoole\Server
     */
    protected $server;

    private $source = [];

    public $halfPack;

    public $clients = [];

    private $mysql_pool;

    public function __construct($host = '0.0.0.0', $port = 3386, $params = [])
    {
        $this->host = $host;
        $this->port = $port;
        $this->params = $params;
        $this->server = new \Swoole\Server($this->host, $this->port);
        Console::info("MyDBProxy listen in %s:%s", [$this->host, $this->port]);
        $this->server->set($this->params);
        $this->registryEvents();
//        $this->mysql_pool = new MySQLPool();
    }

    public function start()
    {
        $this->server->start();
    }

    /**
     * registry server events
     */
    private function registryEvents()
    {
        $this->server->on('Start', [$this, 'onStart']);
        $this->server->on('Connect', [$this, 'onConnect']);
        $this->server->on('Close', [$this, 'onClose']);
        $this->server->on('ManagerStart', [$this, 'onManagerStart']);
        $this->server->on('WorkerStart', [$this, 'onWorkerStart']);
        $this->server->on('WorkerStop', [$this, 'onWorkerStop']);
        $this->server->on('WorkerError', [$this, 'onWorkerError']);
        $this->server->on('Shutdown', [$this, 'onShutdown']);
        $this->server->on('Receive', [$this, 'onReceive']);
    }

    /**
     * @param swoole_server $server
     * @param int $fd
     */
    public function onConnect($server, $fd)
    {
        $auth = new Authenticator();
        $this->source[$fd] = $auth;
        if ($server->exist($fd)) {
            $server->send($fd, $auth->getHandshakePacket($fd));
        }
    }

    public function onClose(\swoole_server $server, int $fd)
    {
        if (isset($this->source[$fd])) {
            unset($this->source[$fd]);
        }

        if (isset($this->halfPack[$fd])) {
            unset($this->halfPack[$fd]);
        }

        if (isset($this->clients[$fd])) {
            /** @var MySQLProxy $client */
            $client = $this->clients[$fd];
            $client->release();
//            echo 'Connection release' . PHP_EOL;
//            $this->mysql_pool->release($this->clients[$fd]);
        }

        $cid = Coroutine::getuid();
        if ($cid > 0 && isset(self::$pool[$cid])) {
            unset(self::$pool[$cid]);
        }
    }

    /**
     * @param $server
     * @param $fd
     * @param $reactor_id
     * @param $data
     * @throws Exception
     */
    public function onReceive($server, $fd, $reactor_id, $data)
    {
//        echo "Receive: {$fd}" . PHP_EOL;
        self::go(function () use ($server, $fd, $reactor_id, $data) {
            if (!isset($this->source[$fd]->auth)) {
                throw new VaryException('Must be connected before sending data!');
            }
            if (!isset($this->halfPack[$fd])) {
                $this->halfPack[$fd] = '';
            }
            if (!$data) {
                return;
            }
            $bin = (new MySQLDecoder())->decode($data);
            if (!$this->source[$fd]->auth) {
//                Console::debug("Auth request start");
                $this->auth($bin, $server, $fd);
            } else {
                $this->query($bin, $data, $fd);
                if ($data) {
                    if(!isset($this->clients[$fd])) {
                        $client =  PoolManager::get('write')->gain();
                        $this->clients[$fd] = $client;
                    } else {
                        $client = $this->clients[$fd];
                    }
                    /**
                     * @var MySQLProxy $client
                     */
                    $client->setServerFd($fd);
                    $result = $client->send($data);
                }
            }
        });
    }

    private function query(Binary $bin, string &$data, int $fd)
    {
        $trim_data = rtrim($data);
        $data_len = strlen($trim_data);

        switch ($bin->getType()) {
            case Package::COM_INIT_DB:
                // just init the frontend
                break;
            case Package::COM_STMT_PREPARE:
            case Package::COM_QUERY:
                $queryParser = new QueryParser($bin);
                $queryParser->query();;
                break;
                break;
            case Package::COM_QUIT:                //禁用客户端退出
                $data = '';
                break;
            case Package::COM_PING:
            case Package::COM_PROCESS_KILL:
            case Package::COM_STMT_EXECUTE:
            case Package::COM_STMT_CLOSE:
            case Package::COM_HEARTBEAT:
            default:
                break;
        }
    }

    /**
     * 验证账号
     *
     * @param swoole_server $server
     * @param int $fd
     * @param string $user
     * @param string $password
     *
     * @return bool
     */
    private function checkAccount($server, $fd, $user, $password)
    {
        $checkPassword = $this->source[$fd]
            ->checkPassword($password, PROXY_CONFIG['options']['password']);
        return PROXY_CONFIG['options']['user'] == $user && $checkPassword;
    }

    /**
     * 验证账号失败
     *
     * @param swoole_server $server
     * @param int $fd
     * @param int $serverId
     * @throws MySQLException
     */
    private function accessDenied(swoole_server $server, int $fd, int $serverId)
    {
        $message = 'SMProxy@access denied for user \'' . $this->source[$fd]->user . '\'@\'' .
            $server->getClientInfo($fd)['remote_ip'] . '\' (using password: YES)';
        $errMessage = self::writeErrMessage($serverId, $message, ErrorCode::ER_ACCESS_DENIED_ERROR, 28000);
        if ($server->exist($fd)) {
            $server->send($fd, getString($errMessage));
        }
        throw new MySQLException($message);
    }

    /**
     * @param Binary $bin
     * @param swoole_server $server
     * @param int $fd
     * @throws MySQLException
     */
    private function auth(Binary $bin, swoole_server $server, int $fd)
    {
        if ($bin->data[0] == 20) {
            $checkAccount = $this->checkAccount($server, $fd, $this->source[$fd]->user, array_copy($bin->data, 4, 20));
            if (!$checkAccount) {
                $this->accessDenied($server, $fd, 4);
            } else {
                if ($server->exist($fd)) {
                    Console::info("Auth success");
                    $server->send($fd, getString(Ok::$SWITCH_AUTH_OK));
                }
                $this->source[$fd]->auth = true;
            }
        } elseif ($bin->getType() == 14) {
            if ($server->exist($fd)) {
                $server->send($fd, getString(Ok::$OK));
            }
        } else {
            $authPacket = new Auth();
            $authPacket->read($bin);
            $checkAccount = $this->checkAccount($server, $fd, $authPacket->user ?? '', $authPacket->password ?? []);
            if (!$checkAccount) {
                if ($authPacket->pluginName == 'mysql_native_password') {
                    $this->accessDenied($server, $fd, 2);
                } else {
                    $this->source[$fd]->user = $authPacket->user;
                    $this->source[$fd]->database = $authPacket->database;
                    $this->source[$fd]->seed = Random::randomBytes(20);
                    $authSwitchRequest = array_merge(
                        [254],
                        getBytes('mysql_native_password'),
                        [0],
                        $this->source[$fd]->seed,
                        [0]
                    );
                    if ($server->exist($fd)) {
                        $server->send($fd, getString(array_merge(getMysqlPackSize(count($authSwitchRequest)), [2], $authSwitchRequest)));
                    }
                }
            } else {
                if ($server->exist($fd)) {
                    $server->send($fd, getString(Ok::$AUTH_OK));
                }
                $this->source[$fd]->auth = true;
                $this->source[$fd]->database = $authPacket->database;
            }
        }
    }

    /**
     * service start event
     * @param swoole_server $serv
     */
    public function onStart($serv)
    {

//        file_put_contents('service.pid', $serv->master_pid);
        swoole_set_process_name("Vary master_{$serv->master_pid}");
    }

    /**
     * manager process start
     * @param swoole_server $serv
     */
    public function onManagerStart($serv)
    {
        swoole_set_process_name("Vary manager {$serv->manager_pid}");
    }

    /**
     * worker process start event
     * @param swoole_server $serv
     * @param int $worker_id
     */
    public function onWorkerStart($serv, $worker_id)
    {
        $pid = posix_getpid();

        if ($worker_id >= $serv->setting['worker_num']) {
            swoole_set_process_name("Vary task_{$pid}");
        } else {
            swoole_set_process_name("Vary worker_{$pid}");
        }

        Console::info("Vary proxy service worker %s start.", [$worker_id]);

//        $this->mysql_pool->init($serv);
        PoolManager::init($serv);
    }

    /**
     * worker process stop event
     * @param swoole_server $serv
     * @param int $worker_id
     */
    public function onWorkerStop($serv, $worker_id)
    {
        Console::info("Vary proxy service worker %s stop.", [$worker_id]);
    }

    /**
     * worker process error event
     * @param swoole_server $serv
     * @param int $worker_id
     * @param int $worker_pid
     * @param int $exit_code
     * @param int $signal
     */
    public function onWorkerError($serv, $worker_id, $worker_pid, $exit_code, $signal)
    {
        Console::warn("Vary Worker_%s - (%s, %s)", [$worker_pid, $exit_code, $signal]);
    }

    /**
     * service shutdown event
     * @param $serv
     */
    public function onShutdown($serv)
    {
        Console::info("Vary proxy service will shutdown...");
    }
}