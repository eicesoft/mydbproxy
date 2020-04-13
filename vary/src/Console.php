<?php
namespace Vary;

use Swoole\Coroutine;

/**
 * Class Debug
 * @package Lark\Core
 */
class Console
{
    const DEBUG = 0;
    const INFO = 1;
    const WARN = 2;
    const ERROR = 3;

    private static $labels = [
        self::DEBUG => "\033[0;37;40m[DEBUG]\033[0m",
        self::INFO => "\033[1;32;40m[INFO]\033[0m",
        self::WARN => "\033[1;31;43m[WARN]\033[0m",
        self::ERROR => "\033[1;32;41m[ERROR]\033[0m",
    ];

    public static function debug($message, $params=[]) {
        self::log($message, $params, self::DEBUG);
    }

    public static function info($message, $params=[]) {
        self::log($message, $params, self::INFO);
    }

    public static function warn($message, $params=[]) {
        self::log($message, $params, self::WARN);
    }

    public static function error($message, $params=[]) {
        self::log($message, $params, self::ERROR);
    }

    private static function log($message, $params=[], $level=self::DEBUG) {
        $label = self::$labels[$level];
        echo sprintf("%s: %s - [%s]\n", $label, vsprintf($message, $params), Coroutine::getCid());
    }
}