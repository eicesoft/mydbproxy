<?php
namespace Vary\Package;

use Vary\Util\Byte;
use Vary\VaryException;
use function Vary\Helper\getBytes;

class MySQLDecoder
{
    private $packetHeaderSize = 4;
    private $maxPacketSize = 16777216;

    /**
     * MySql外层结构解包.
     *
     * @param string $data
     * @return Binary
     * @throws VaryException
     */
    public function decode(string $data)
    {
        $data = getBytes($data);
        // 4 bytes:3 length + 1 packetId
        if (count($data) < $this->packetHeaderSize) {
            throw new VaryException('Packet is empty');
        }
        $packetLength = Byte::readUB3($data);
//        // 过载保护
        if ($packetLength > $this->maxPacketSize) {
            throw new VaryException('Packet size over the limit ' . $this->maxPacketSize);
        }
        $packetId = $data[3];
//        if (in.readableBytes() < packetLength) {
//            // 半包回溯
//            in.resetReaderIndex();
//            return;
//        }
        $packet = new Binary();
        $packet->packetLength = $packetLength;
        $packet->packetId = $packetId;
        // data will not be accessed any more,so we can use this array safely
        $packet->data = $data;
        if (null == $packet->data || 0 == count($packet->data)) {
            throw new VaryException('get data errorMessage,packetLength=' . $packet->packetLength);
        }

        return $packet;
    }
}