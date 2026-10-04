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

    echo("TEST 1: READ TEST SET IN BULK\n");
    writeTestSet($loopSocket);
    $read = $loopSocket->readBulk();
    readResult($loopSocket, $read);

    echo("TEST 2: READ TEST SET IN SMALL BULKS\n");
    writeTestSet($loopSocket);
    $read = [];
    while (!empty($chunk = $loopSocket->readBulk(4))) {
        echo(" * READ ".count($chunk)." CHUNKS\n");
        foreach ($chunk as $data) $read[] = $data;
    }
    readResult($loopSocket, $read);

    echo("TEST 3: COMBINE READING TEST SET DIRECTLY AND IN BULK, GO OVER AVAILABLE READS\n");
    writeTestSet($loopSocket);
    $read = [];
    for ($i = 0; $i < 5; $i++) {
        if (($data = $loopSocket->read()) !== false) {
            echo(" * READ CHUNK\n");
            $read[] = $data;
        } else {
            echo(" * NO CHUNK\n");
        }
        if (($data = $loopSocket->read()) !== false) {
            echo(" * READ CHUNK\n");
            $read[] = $data;
        } else {
            echo(" * NO CHUNK\n");
        }
        if (!empty($chunk = $loopSocket->readBulk(2))) {
            echo(" * READ ".count($chunk)." CHUNKS\n");
            foreach ($chunk as $data) $read[] = $data;
        } else {
            echo(" * NO CHUNKS\n");
        }
    }
    readResult($loopSocket, $read);

    echo("TEST 4: BULK STRING READ\n");
    writeTestSet($loopSocket);
    $read = [$loopSocket->readBulkString()];
    readResult($loopSocket, $read);

    echo("TEST 5: BYTEWISE READS\n");
    writeTestSet($loopSocket);
    $read = [];
    for ($i = 0; $i < 8; $i++) {
        if (($data = $loopSocket->readBytes(100)) !== '') {
            echo(" * READ ".strlen($data)." BYTES\n");
            $read[] = $data;
        } else {
            echo(" * NOTHING TO READ\n");
        }
    }
    readResult($loopSocket, $read);

    echo("TEST 6: BYTEWISE READS IN TINY BULK, THE LAST LINE WILL BE ATTEMPTED BY READBYTES, FAIL TO READ EXACT, THEN BE READ WITHOUT EXACT SIZE\n");
    writeTestSet($loopSocket);
    $read = $loopSocket->readBytesBulk(20, 100, true);
    echo(" * EXACT READ ".count($read)." CHUNKS\n");
    if (!empty($chunk = $loopSocket->readBytesBulk(20, 100))) {
        echo(" * LAST READ ".count($chunk)." CHUNKS\n");
        foreach ($chunk as $data) $read[] = $data;
    } else {
        echo(" * NO CHUNKS\n");
    }
    readResult($loopSocket, $read);

    echo("TEST 7: DELIMITED READS, LAST LINE WILL BE ATTEMPTED, FAIL TO READ, THEN FIT MAX READ SIZE\n");
    writeTestSet($loopSocket);
    $read = [];
    $loopSocket->setDelimiter("\r\n");
    while (($data = $loopSocket->readDelimited(0x1000)) !== '') $read[] = $data;
    echo(" * DELIMITED (\\R\\N) READ, TOTAL ".count($read)." LINES\n");
    $loopSocket->setDelimiter("\n");
    while (($data = $loopSocket->readDelimited(0x1000)) !== '') $read[] = $data;
    echo(" * DELIMITED (\\N) READ, TOTAL ".count($read)." LINES\n");
    if (($data = $loopSocket->readDelimited(80)) !== '') { # read first 80 bytes as delimited length is reached
        echo(" * READ ".strlen($data)." BYTES\n");
        $read[] = $data;
    } else {
        echo(" * NOTHING TO READ\n");
    }
    if (($data = $loopSocket->readDelimited(80)) !== '') { # fail to read next 80 bytes as remaining length is 7
        echo(" * READ ".strlen($data)." BYTES\n");
        $read[] = $data;
    } else {
        echo(" * NOTHING TO READ\n");
    }
    if (($data = $loopSocket->readDelimited(7)) !== '') { # and read the last 7 bytes
        echo(" * READ ".strlen($data)." BYTES\n");
        $read[] = $data;
    } else {
        echo(" * NOTHING TO READ\n");
    }
    if (($data = $loopSocket->readDelimited(7)) !== '') { # try some more, this should fail
        echo(" * READ ".strlen($data)." BYTES\n");
        $read[] = $data;
    } else {
        echo(" * NOTHING TO READ\n");
    }
    readResult($loopSocket, $read);

    echo("TEST 8: BULK DELIMITED READS WITH LOW CHUNK SIZE, THE LAST LINE WILL BE JUST READ BY READBYTES AS IT HAS NO DELIMITER\n");
    writeTestSet($loopSocket);
    $read = [];
    $loopSocket->setDelimiter("\r\n");
    if (!empty($chunk = $loopSocket->readDelimitedBulk(16, 4))) {
        echo(" * BULK DELIMITED (\\R\\N/16/4) READ, TOTAL ".count($chunk)." LINES\n");
        foreach ($chunk as $data) $read[] = $data;
    }
    if (!empty($chunk = $loopSocket->readDelimitedBulk(16, 15))) { # take care this needs the limit as we must stop before \n delimiter comes
        echo(" * BULK DELIMITED (\\R\\N/16) READ, TOTAL ".count($chunk)." LINES\n");
        foreach ($chunk as $data) $read[] = $data;
    }
    $loopSocket->setDelimiter("\n");
    if (!empty($chunk = $loopSocket->readDelimitedBulk(32))) {
        echo(" * BULK DELIMITED (\\N/32) READ, TOTAL ".count($chunk)." LINES\n");
        foreach ($chunk as $data) $read[] = $data;
    }
    if (($data = $loopSocket->readBytes()) !== '') {
        echo(" * READ ".strlen($data)." BYTES\n");
        $read[] = $data;
    } else {
        echo(" * NOTHING TO READ\n");
    }
    readResult($loopSocket, $read);

    echo("TEST 9: DELIMITED READS WITH DUAL DELIMITER, LAST LINE WILL BE ATTEMPTED, FAIL TO READ, THEN FIT MAX READ SIZE\n");
    writeTestSet($loopSocket);
    $read = [];
    $loopSocket->setDelimiter(["\r\n", "\n"]);
    $read = $loopSocket->readDelimitedBulk();
    if (($data = $loopSocket->readBytes()) !== '') {
        echo(" * READ ".strlen($data)." BYTES\n");
        $read[] = $data;
    } else {
        echo(" * NOTHING TO READ\n");
    }
    readResult($loopSocket, $read);

    # the end
    $loopSocket->disconnect();
    echo("END\n\n");
}

function socketReadState($socket, $type)
{
    $buffer = $socket->skGetReadBuffer($socket::SK_DEFAULT_READ_BUFFER);
    echo(" - SOCKET READ BUFFER ({$type}) STATE: {$buffer->skbCount} CHUNKS, {$buffer->skbSize} BYTES\n");
}

function readResult($socket, $read)
{
    socketReadState($socket, 'POST-READ');
    echo(" === BEGIN READ DATA ===\n");
    foreach ($read as $v) echo(" | ".\ATL\Routines::escapeUTF8StringToPrintable($v)."\n");
    echo(" === END READ DATA ===\n");
    echo(" - READ RESULT: ".count($read)." CHUNKS, ".array_sum(array_map('strlen', $read))." BYTES, HASH ".hash('sha256', implode('', $read))."\n\n");
}

function writeTestSet($socket)
{
    $response = [];
    $response[] = "SOME TEST LINE THERE\nHE";
    $response[] = "RE WE NEED SOME MORE TEST LINES\nDELIMITED BY LINE DELI";
    $response[] = "MITER BUT SOMEHOW BRO";
    $response[] = "KEN IN PARTS SO WE CAN TEST READ FUNCTIONS THAT AGGRE";
    $response[] = "GATE THESE LINES\nAND YAY THERE ARE MULTIPLE\nDELIMITERS\n\nON THE SAME\nBLOCK\nAND NOW";
    $response[] = " WE NEED ANOTHER TEST LINE THAT HAS DELIMITER ABSENT AT ALL, IT IS THE LAST LINE";
    $length = array_sum(array_map('strlen', $response));

    $socket->write("Content-Type: text/plain\r\n");
    $socket->writeBulk([
        "Server: Test suite\r\n",
        "Set-Cookie: xyzzy=abba; Domain=.test.domain; Path=/; ",
        "Expires=Sun, 02 Oct 2026 01:02:03 GMT\r\n",
        "Set-Cookie: abba=xyzzy; Domain=.test.domain; Path=/; Expires=Sun, 02 Oct 2026 01:02:03 GMT\r",
        "\n", # take care, delimiter is deliberately split here to make sure delimited reads scan & aggregate well
    ]);
    $socket->write("Content-Length: ".$length."\r\n");
    $socket->write("\r");
    $socket->write("\n"); # take care, delimiter is deliberately split here again to check delimited reads with zero preceding length

    $socket->writeBulk($response);
    socketReadState($socket, 'POST-WRITE');
}

testMain();
exit;
