<?php
namespace Vary;

/**
 * Class Vary
 * @package Vary
 * @author kelezyb
 */
class Vary
{
    /**
     * @var string
     */
    private $config_file;

    /**
     * @var array
     */
    private $configs;

    /**
     * Vary constructor.
     * @param string $config_file
     */
    public function __construct($config_file)
    {
        $this->config_file = $config_file;
        $this->load_config();
    }


    /**
     * create class instance
     * @param string $class_name
     * @param array $params
     * @return object
     * @throws \ReflectionException
     */
    public static function newInstance($class_name, $params) {
        $class = new \ReflectionClass($class_name);
        return $class->newInstanceArgs($params);
    }

    /**
     * start proxy server
     */
    public function startServer()
    {
        try {
            /** @var Server $server */
            $server = self::newInstance('Vary\Server', $this->configs['server']);
            $server->start();
        } catch (\ReflectionException $e) {
            var_dump($e);
        }
    }

    /**
     * load configs
     */
    private function load_config()
    {
        if (!is_readable($this->config_file)) {
            die("Config file {$this->config_file} is not readable.");
        }

        $config_content = file_get_contents($this->config_file);
        $this->configs = json_decode($config_content, true);
        define('PROXY_CONFIG', $this->configs);
    }
}