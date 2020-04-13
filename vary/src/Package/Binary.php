<?php


namespace Vary\Package;


use Vary\VaryException;

class Binary extends Package implements \ArrayAccess
{
    public static $OK = 1;
    public static $ERROR = 2;
    public static $HEADER = 3;
    public static $FIELD = 4;
    public static $FIELD_EOF = 5;
    public static $ROW = 6;
    public static $PACKET_EOF = 7;
    public $data;

    public function calcPacketSize()
    {
        return null == $this->data ? 0 : count($this->data);
    }

    protected function getPacketInfo()
    {
        return 'MySQL Binary Packet';
    }

    public function getType()
    {
        return $this->data[4];
    }

    /**
     * @inheritDoc
     */
    public function offsetExists($offset)
    {
        return isset($this->data[$offset]);
    }

    /**
     * @inheritDoc
     */
    public function offsetGet($offset)
    {
        return $this->data[$offset];
    }

    /**
     * @inheritDoc
     * @throws VaryException
     */
    public function offsetSet($offset, $value)
    {
        throw new VaryException("unImpl exception");
    }

    /**
     * @inheritDoc
     */
    public function offsetUnset($offset)
    {
        throw new VaryException("unImpl exception");
    }
}