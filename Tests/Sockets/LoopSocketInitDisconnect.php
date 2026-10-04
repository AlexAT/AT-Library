<?php

namespace ATL\Tests;

error_reporting(E_ALL);
ini_set('display_errors', 1);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
header('Content-Type: text/plain');

require_once(__DIR__.'/../../ATLibrary.php');
require_once(__DIR__.'/../../Sockets/Demo/DemoSockets.php');

function testMain()
{
    $loopSocket = new \ATL\Sockets\Demo\LoopSocket('self');
    $loopSocket->connect();
    $loopSocket->write('TEST!');
    $loopSocket->disconnect();
    print_r($loopSocket);
}

testMain();
exit;
