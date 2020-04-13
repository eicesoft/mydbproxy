<?php
include 'vendor/autoload.php';

use Vary\Console;
use Vary\Vary;

try {
    $vary = new Vary('config.json');
    $vary->startServer();
} catch (Exception $ex) {
    Console::error($ex->getMessage());
}