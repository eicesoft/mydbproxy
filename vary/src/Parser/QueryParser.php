<?php
namespace Vary\Parser;

use Vary\Console;
use Vary\Package\Binary;
use Vary\Package\MySQLMessage;
use Vary\Proxy\MySQLException;

class QueryParser
{
    const SELECT = 1;
    const UPDATE = 2;
    const INSERT = 3;
    const DELETE = 4;
    const REPLACE = 5;
    const REGULARS = [
        self::SELECT => '#SELECT\ .*\ FROM\ (.*)\ .*#isU',
        self::UPDATE => '#UPDATE\ (.*)\ SET.*#isU',
        self::INSERT => '#INSERT\ INTO(.*)\(.*\).*#isU',
        self::DELETE => '#DELETE\ FROM\ (.*)\ WHERE.*#isU',
        self::REPLACE => 'REPLACE\ INTO(.*)\(.*\).*#isU',
    ];

    private $binary;

    public function __construct(Binary $bin)
    {
        $this->binary = $bin;
    }

    public function query()
    {
        $mm = new MySQLMessage($this->binary->data);
        $mm->position(5);
        $sql = $mm->readString();
        Console::debug("SQL execute: {$sql}");
        if (null == $sql || 0 == strlen($sql)) {
            throw new MySQLException('Empty SQL');
        }
        return $this->parser($sql);
    }

    private function parser($sql)
    {

        return 0;
    }
}