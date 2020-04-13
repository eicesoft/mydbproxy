<?php
namespace Vary\Proxy;


class MySQLException extends \Exception
{
    public function errorMessage()
    {
        return sprintf('%s (%s:%s)', trim($this->getMessage()), $this->getFile(), $this->getLine());
    }
}