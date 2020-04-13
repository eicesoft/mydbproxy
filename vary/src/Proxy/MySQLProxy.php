<?php


namespace Vary\Proxy;


use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Client;
use swoole_server;
use Vary\Base;
use Vary\Package\Auth;
use Vary\Package\Binary;
use Vary\Package\Capabilities;
use Vary\Package\Error;
use Vary\Package\Handshake;
use Vary\Package\MySQLMessage;
use Vary\Package\Ok;
use Vary\Util\Charset;
use Vary\Util\Security;
use function Vary\Helper\getBytes;
use function Vary\Helper\getMysqlPackSize;
use function Vary\Helper\getString;
use function Vary\Helper\packageLengthSetting;

class MySQLProxy extends Base
{
    /**
     * @var Client
     */
    private $client;

    /**
     * @var string
     */
    private $host;
    /**
     * @var int
     */
    private $port;
    /**
     * @var int
     */
    private $timeout;
    /**
     * @var string
     */
    private $database;

    private $auth = false;

    /**
     * @var mixed
     */
    private $salt;

    /**
     * @var bool
     */
    private $connected = false;

    /**
     * @var string
     */
    private $serverPublicKey;

    /**
     * @var mixed
     */
    private $account;

    /**
     * @var swoole_server
     */
    private $server;

    /**
     * @var Channel
     */
    private $chan;

    /**
     * @var MySQLPool
     */
    private $mysql_pool;

    /**
     * @param mixed $account
     */
    public function setAccount($account): void
    {
        $this->account = $account;
    }

    /**
     * @var int
     */
    private $serverFd;

    public function __construct(swoole_server $server, Channel $chan)
    {
        $this->server = $server;
        $this->chan = $chan;

        $this->client = new Client(SWOOLE_SOCK_TCP);
        $this->client->set(PROXY_CONFIG['client_setting'] ?? []);
        $this->client->set(packageLengthSetting());
    }

    /**
     * @param string $host
     * @param int $port
     * @param string $database
     * @param float $timeout
     * @param int $tryStep
     * @return bool
     */
    public function connect(string $host, int $port, string $database, float $timeout = 0.1, int $tryStep = 0)
    {
        $this->timeout = $timeout;
        $this->host = $host;
        $this->port = $port;
        $this->database = $database;
        if (!$this->client->connect($host, $port, $timeout)) {
            if ($tryStep < 3) {
                $this->client->close();
                return $this->connect($host, $port, $timeout, ++$tryStep);
            } else {
                $this->onClientError();
                return false;
            }
        } else {
            $this->data_recv();
            return true;
        }
    }

    /**
     * data recv coroutine
     */
    private function data_recv()
    {
        self::go(function () {
            $remain = '';
            while (true) {
                $data = $this->recv($remain);

                if ($data === '' || $data === false) {
                    break;
                }
            }
        });
    }

    /**
     * @param $remain
     * @return mixed
     * @throws MySQLException
     */
    private function recv(&$remain)
    {
        $data = $this->client->recv(5);

        if ($data === '' || $data === false) {
            $this->onClientClose();
        } elseif (is_string($data)) {
            $this->onClientReceive($data);
        }

        return $data;
    }

    /**
     * @param $data
     * @throws MySQLException
     */
    private function onClientReceive($data)
    {

        $binary = new Binary();
        $binary->data = getBytes($data);
        $binary->packetLength = $binary->calcPacketSize();
//        echo 'Recv data :' . strlen($data) . PHP_EOL;
        if (isset($binary->data[4])) {
            $send = true;

            if ($binary->getType() == Error::$FIELD_COUNT) {     //ERROR Packet
                $errorPacket = new Error();
                $errorPacket->read($binary);
                //$errorPacket->errno = ErrorCode::ER_SYNTAX_ERROR;
                $data = getString($errorPacket->write());
            } elseif (!$this->connected) {
                //OK Packet
                if ($binary->getType() == Ok::$FIELD_COUNT) {
                    $send = false;
                    $this->connected = true;
//                    var_dump('client auth success!!!');
                    $this->chan->push($this);
                    # 快速认证
                } elseif ($binary->getType() == 0x01) {
                    # 请求公钥
                    if ($binary->packetLength == 6) {
                        if ($binary->data[$binary->packetLength - 1] == 4) {
                            $data = getString(array_merge(getMysqlPackSize(1), [3, 2]));
                            $this->send($data);
                        }
                    } else {
                        $this->serverPublicKey = substr($data, 5, strlen($data) - 2);
                        $encryptData = Security::sha2RsaEncrypt($this->account['password'], $this->salt, $this->serverPublicKey);
                        $data = getString(array_merge(getMysqlPackSize(strlen($encryptData)), [5])) . $encryptData;
                        $this->send($data);
                    }
                    $send = false;
                    //EOF Packet
                } elseif ($binary->getType() == 0xfe) {
                    $mm = new MySQLMessage($binary->data);
                    $mm->move(5);
                    $pluginName = $mm->readStringWithNull();
                    $this->salt = $mm->readBytesWithNull();
                    $password = $this->process_auth($pluginName ?: 'mysql_native_password');
                    $this->send(getString(array_merge(getMysqlPackSize(count($password)), [3], $password)));
                    $send = false;
                    //未授权
                } elseif (!$this->auth) {
                    $handshake = (new Handshake())->read($binary);
//                        $this->mysqlServer = $handshakePacket;
                    $this->salt = array_merge($handshake->seed, $handshake->restOfScrambleBuff);
                    $password = $this->process_auth($handshake->pluginName);
                    $clientFlag = Capabilities::CLIENT_CAPABILITIES;
                    $authPacket = new Auth();
                    $authPacket->pluginName = $handshake->pluginName;
                    $authPacket->packetId = 1;
                    if (isset($this->database) && $this->database) {
                        $authPacket->database = $this->database;
                    } else {
                        $authPacket->database = 0;
                    }
                    if ($authPacket->database) {
                        $clientFlag |= Capabilities::CLIENT_CONNECT_WITH_DB;
                    }
                    if (version_compare($handshake->serverVersion, '5.0', '>=')) {
                        $clientFlag |= Capabilities::CLIENT_MULTI_RESULTS;
                    }
                    $authPacket->clientFlags = $clientFlag;
                    $authPacket->serverCapabilities = $handshake->serverCapabilities;
                    $authPacket->maxPacketSize = 16777215;
                    $authPacket->charsetIndex = Charset::getIndex($this->charset ?? 'utf8mb4');
                    $authPacket->user = $this->account['user'];
                    $authPacket->password = $password;
                    $this->auth = true;
                    $this->send(getString($authPacket->write()));
                    $send = false;
                }
            }

            if ($send && $this->server->exist($this->serverFd)) {
//                echo 'Write data' . PHP_EOL;
                $this->server->send($this->serverFd, $data);
            }
        }
    }

    /**
     * send.
     *
     * @param mixed ...$data
     * @return bool
     */
    public function send(...$data)
    {
//        echo 'Send: ' . $data[0] . PHP_EOL;
        if ($this->client->isConnected()) {
//            echo 'Send: ' . $data[0] . PHP_EOL;
            return $this->client->send(...$data);
        } else {
            return false;
        }
    }

    private function onClientError()
    {
        echo 'Connection error.';
    }

    private function onClientClose()
    {
//        echo 'Connection close.';
        $this->mysql_pool->free($this);
    }

    /**
     * 认证
     *
     * @param string $pluginName
     *
     * @return array
     * @throws MySQLException
     */
    public function process_auth(string $pluginName)
    {
        switch ($pluginName) {
            case 'caching_sha2_password':
                $password = Security::scrambleSha256($this->account['password'], $this->salt);
                break;
            case 'sha256_password':
                throw new MySQLException('Sha256_password plugin is not supported yet');
                break;
            case 'mysql_old_password':
                throw new MySQLException('mysql_old_password plugin is not supported yet');
                break;
            case 'mysql_clear_password':
                $password = array_merge(getBytes($this->account['password']), [0]);
                break;
            case 'mysql_native_password':
            default:
                $password = Security::scramble411($this->account['password'], $this->salt);
                break;
        }
        return $password;
    }

    /**
     * @param int $serverFd
     */
    public function setServerFd(int $serverFd): void
    {
        $this->serverFd = $serverFd;
    }

    public function close()
    {
        if ($this->client->isConnected()) {
            $this->client->close();
        }
    }

    /**
     * @param MySQLPool $pool
     */
    public function setPool(MySQLPool $pool): void
    {
        $this->mysql_pool = $pool;
    }

    public function release()
    {
        $this->mysql_pool->release($this);
    }
}