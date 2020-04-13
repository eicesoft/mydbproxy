<?php
namespace Vary\Package;

use Vary\Util\Buffer;
use function Vary\Helper\array_copy;
use function Vary\Helper\getBytes;
use function Vary\Helper\getMysqlPackSize;

class Auth extends Package
{
    private static $FILLER = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0]; //23位array

    public $clientFlags;
    public $maxPacketSize;
    public $charsetIndex;
    public $extra; // from FILLER(23)
    public $user;
    public $password;
    public $database = 0;
    public $pluginName = 'mysql_native_password';
    public $serverCapabilities;

    public function read(Binary $bin)
    {
        $this->packetLength = $bin->packetLength;
        $this->packetId = $bin->packetId;
        $mm = new MySQLMessage($bin->data);
        $mm->move(4);
        $this->clientFlags = $mm->readUB4();
        $this->maxPacketSize = $mm->readUB4();
        $this->charsetIndex = ($mm->read() & 0xff);
        $current = $mm->position();
        $len = (int) $mm->readLength();
        if ($len > 0 && $len < count(self::$FILLER)) {
            $this->extra = array_copy($mm->bytes(), $mm->position(), $len);
        }
        $mm->position($current + count(self::$FILLER));
        $this->user = $mm->readStringWithNull();
        $this->password = $mm->readBytesWithLength();
        if ((0 != ($this->clientFlags & Capabilities::CLIENT_CONNECT_WITH_DB)) && $mm->hasRemaining()) {
            $this->database = $mm->readStringWithNull();
        }
        $this->pluginName = $mm->readStringWithNull();

        return $this;
    }

    /**
     * @return array
     */
    public function write()
    {
        $data = getMysqlPackSize($this ->calcPacketSize());
        $data[] = $this->packetId;
        Buffer::writeUB4($data, $this->clientFlags);
        Buffer::writeUB4($data, $this->maxPacketSize);
        $data[] = $this->charsetIndex;

        $data = array_merge($data, self::$FILLER);

        if (null == $this->user) {
            $data[] = 0;
        } else {
            Buffer::writeWithNull($data, getBytes($this->user));
        }
        if (null == $this->password) {
            $authResponseLength  = 0;
            $authResponse = 0;
        } else {
            $authResponseLength  = count($this->password);
            $authResponse = $this->password;
        }
        if ($this ->clientFlags & Capabilities::CLIENT_PLUGIN_AUTH_LENENC_CLIENT_DATA) {
            Buffer::writeLength($data, $authResponseLength);
            Buffer::writeWithNull($data, $authResponse, false);
        } else if ($this ->clientFlags & Capabilities::CLIENT_SECURE_CONNECTION) {
            $data[] = $authResponseLength;
            Buffer::writeWithNull($data, $authResponse, false);
        } else {
            Buffer::writeWithNull($data, $authResponse);
        }

        if ($this ->clientFlags & Capabilities::CLIENT_CONNECT_WITH_DB) {
            $database = getBytes($this->database);
            Buffer::writeWithNull($data, $database);
        }
        if ($this ->clientFlags & Capabilities::CLIENT_PLUGIN_AUTH) {
            Buffer::writeWithNull($data, getBytes($this->pluginName));
        }
        return $data;
    }

    public function calcPacketSize()
    {
        $size = 32; // 4+4+1+23;
        $size += (null == $this->user) ? 1 : strlen($this->user) + 1;
        if ($this ->clientFlags & Capabilities::CLIENT_PLUGIN_AUTH_LENENC_CLIENT_DATA) {
            $size += Buffer::getLength(count($this->password)) - 1;
        }
        $size += (null == $this->password) ? 1 : Buffer::getLength($this->password);
        if ($this ->clientFlags & Capabilities::CLIENT_CONNECT_WITH_DB) {
            $size += (null == $this->database) ? 1 : strlen($this->database) + 1;
        }
        if ($this ->clientFlags & Capabilities::CLIENT_PLUGIN_AUTH) {
            $size += strlen($this ->pluginName) + 1;
        }

        return $size;
    }

    protected function getPacketInfo()
    {
        return 'MySQL Authentication Packet';
    }
}