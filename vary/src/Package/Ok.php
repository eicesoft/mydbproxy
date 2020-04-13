<?php


namespace Vary\Package;


use Vary\Util\Buffer;

class Ok extends Package
{
    public static $FIELD_COUNT = 0x00;
    public static $OK = [7, 0, 0, 1, 0, 0, 0, 2, 0, 0, 0];
    public static $AUTH_OK = [7, 0, 0, 2, 0, 0, 0, 2, 0, 0, 0];
    public static $FAST_AUTH_OK = [7, 0, 0, 3, 0, 0, 0, 2, 0, 0, 0];
    public static $SWITCH_AUTH_OK = [7, 0, 0, 4, 0, 0, 0, 2, 0, 0, 0];
    public static $FULL_AUTH_OK = [7, 0, 0, 6, 0, 0, 0, 2, 0, 0, 0];

    public $fieldCount = 0x00;
    public $affectedRows;
    public $insertId;
    public $serverStatus;
    public $warningCount;
    public $message;

    public function read(Binary $bin)
    {
        $this->packetLength = $bin->packetLength;
        $this->packetId = $bin->packetId;
        $mm = new MySQLMessage($bin->data);
        $this->fieldCount = $mm->read();
        $this->affectedRows = $mm->readLength();
        $this->insertId = $mm->readLength();
        $this->serverStatus = $mm->readUB2();
        $this->warningCount = $mm->readUB2();
        if ($mm->hasRemaining()) {
            $this->message = $mm->readBytesWithLength();
        }
    }

    public function calcPacketSize()
    {
        $i = 1;
        $i += Buffer::getLength($this->affectedRows);
        $i += Buffer::getLength($this->insertId);
        $i += 4;
        if (null != $this->message) {
            $i += Buffer::getLength($this->message);
        }

        return $i;
    }

    protected function getPacketInfo()
    {
        return 'MySQL OK Packet';
    }
}