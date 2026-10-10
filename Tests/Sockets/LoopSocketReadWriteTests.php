<?php

namespace ATL\Tests;

error_reporting(E_ALL);
ini_set('display_errors', 1);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
header('Content-Type: text/plain');

require_once(__DIR__.'/../../ATLibrary.php');
require_once(__DIR__.'/../../Sockets/Demo/DemoSockets.php');

class Test
{
    function testMain()
    {
        $loopSocket = new \ATL\Sockets\Demo\LoopSocket('self');
        $loopSocket->connect();

        $tests = [
            1 => ['name' => 'READ TEST SET IN BULK'],
            2 => ['name' => 'READ TEST SET IN SMALL BULKS'],
            3 => ['name' => 'COMBINE READING TEST SET DIRECTLY AND IN BULK, GO OVER AVAILABLE READS'],
            4 => ['name' => 'BULK STRING READ'],
            5 => ['name' => 'BYTEWISE READS'],
            6 => ['name' => 'BYTEWISE READS IN TINY BULK, THE LAST LINE WILL BE ATTEMPTED BY READBYTES, FAIL TO READ EXACT, THEN BE READ WITHOUT EXACT SIZE'],
            7 => ['name' => 'DELIMITED READS, LAST LINE WILL BE ATTEMPTED, FAIL TO READ, THEN FIT MAX READ SIZE'],
            8 => ['name' => 'BULK DELIMITED READS WITH LOW CHUNK SIZE, THE LAST LINE WILL BE JUST READ BY READBYTES AS IT HAS NO DELIMITER'],
            9 => ['name' => 'LARGE BULK DELIMITED READ WITH DUAL DELIMITER, LAST LINE WILL BE JUST READ BY READBYTES AS IT HAS NO DELIMITER'],
            10 => ['name' => 'LARGE BULK DELIMITED READ WITH DUAL DELIMITER, STOPPING ON EMPTY LINE, LAST LINE WILL BE JUST READ BY READBYTES AS IT HAS NO DELIMITER'],
        ];
        ob_start();
        foreach ($tests as $id => &$test) {
            $test['result'] = $this->runTest($id, $test['name'], $loopSocket);
        } unset($test);
        $testOutput = ob_get_contents();
        ob_end_clean();

        # test summary
        echo("TEST SUMMARY\n\n");
        foreach ($tests as $id => $test)
            echo("TEST {$id}: {$test['result']} ({$test['name']})\n");
        echo("\n");

        # test output
        echo("TEST OUTPUT\n\n");
        echo($testOutput);

        # the end
        $loopSocket->disconnect();
        echo("END\n\n");
    }

    function runTest($id, $name, $socket)
    {
        echo("TEST {$id}: {$name}\n");
        $original = $this->writeTestSet($socket);
        $fx = [$this, "test{$id}"];
        $read = $fx($socket);
        return $this->readResult($socket, $read, $original);
    }

    function test1($socket)
    {
        # READ TEST SET IN BULK
        return $socket->readBulk();
    }

    function test2($socket)
    {
        # READ TEST SET IN SMALL BULKS
        $read = [];
        while (!empty($chunk = $socket->readBulk(4))) {
            echo(" * READ ".count($chunk)." CHUNKS\n");
            foreach ($chunk as $data) $read[] = $data;
        }
        return $read;
    }

    function test3($socket)
    {
        # COMBINE READING TEST SET DIRECTLY AND IN BULK, GO OVER AVAILABLE READS
        $read = [];
        for ($i = 0; $i < 5; $i++) {
            if (($data = $socket->read()) !== false) {
                echo(" * READ CHUNK\n");
                $read[] = $data;
            } else {
                echo(" * NO CHUNK\n");
            }
            if (($data = $socket->read()) !== false) {
                echo(" * READ CHUNK\n");
                $read[] = $data;
            } else {
                echo(" * NO CHUNK\n");
            }
            if (!empty($chunk = $socket->readBulk(2))) {
                echo(" * READ ".count($chunk)." CHUNKS\n");
                foreach ($chunk as $data) $read[] = $data;
            } else {
                echo(" * NO CHUNKS\n");
            }
        }
        return $read;
    }

    function test4($socket)
    {
        # BULK STRING READ
        return [$socket->readBulkString()];
    }

    function test5($socket)
    {
        # BYTEWISE READS
        $read = [];
        for ($i = 0; $i < 8; $i++) {
            if (($data = $socket->readBytes(100)) !== '') {
                echo(" * READ ".strlen($data)." BYTES\n");
                $read[] = $data;
            } else {
                echo(" * NOTHING TO READ\n");
            }
        }
        return $read;
    }

    function test6($socket)
    {
        # BYTEWISE READS IN TINY BULK, THE LAST LINE WILL BE ATTEMPTED BY READBYTES, FAIL TO READ EXACT, THEN BE READ WITHOUT EXACT SIZE
        $read = $socket->readBytesBulk(20, 100, true);
        echo(" * EXACT READ ".count($read)." CHUNKS\n");
        if (!empty($chunk = $socket->readBytesBulk(20, 100))) {
            echo(" * LAST READ ".count($chunk)." CHUNKS\n");
            foreach ($chunk as $data) $read[] = $data;
        } else {
            echo(" * NO CHUNKS\n");
        }
        return $read;
    }

    function test7($socket)
    {
        # DELIMITED READS, LAST LINE WILL BE ATTEMPTED, FAIL TO READ, THEN FIT MAX READ SIZE
        $read = [];
        $socket->setDelimiter("\r\n");
        while (($data = $socket->readDelimited(0x1000)) !== '') $read[] = $data;
        echo(" * DELIMITED (\\R\\N) READ, TOTAL ".count($read)." LINES\n");
        $socket->setDelimiter("\n");
        while (($data = $socket->readDelimited(0x1000)) !== '') $read[] = $data;
        echo(" * DELIMITED (\\N) READ, TOTAL ".count($read)." LINES\n");
        if (($data = $socket->readDelimited(80)) !== '') { # read first 80 bytes as delimited length is reached
            echo(" * READ ".strlen($data)." BYTES\n");
            $read[] = $data;
        } else {
            echo(" * NOTHING TO READ\n");
        }
        if (($data = $socket->readDelimited(80)) !== '') { # fail to read next 80 bytes as remaining length is 7
            echo(" * READ ".strlen($data)." BYTES\n");
            $read[] = $data;
        } else {
            echo(" * NOTHING TO READ\n");
        }
        if (($data = $socket->readDelimited(7)) !== '') { # and read the last 7 bytes
            echo(" * READ ".strlen($data)." BYTES\n");
            $read[] = $data;
        } else {
            echo(" * NOTHING TO READ\n");
        }
        if (($data = $socket->readDelimited(7)) !== '') { # try some more, this should fail
            echo(" * READ ".strlen($data)." BYTES\n");
            $read[] = $data;
        } else {
            echo(" * NOTHING TO READ\n");
        }
        return $read;
    }

    function test8($socket)
    {
        # BULK DELIMITED READS WITH LOW CHUNK SIZE, THE LAST LINE WILL BE JUST READ BY READBYTES AS IT HAS NO DELIMITER
        $read = [];
        $socket->setDelimiter("\r\n");
        if (!empty($chunk = $socket->readDelimitedBulk(16, 4))) {
            echo(" * BULK DELIMITED (\\R\\N/16/4) READ, TOTAL ".count($chunk)." LINES\n");
            foreach ($chunk as $data) $read[] = $data;
        }
        if (!empty($chunk = $socket->readDelimitedBulk(16, 15))) { # take care this needs the limit as we must stop before \n delimiter comes
            echo(" * BULK DELIMITED (\\R\\N/16) READ, TOTAL ".count($chunk)." LINES\n");
            foreach ($chunk as $data) $read[] = $data;
        }
        $socket->setDelimiter("\n");
        if (!empty($chunk = $socket->readDelimitedBulk(32))) {
            echo(" * BULK DELIMITED (\\N/32) READ, TOTAL ".count($chunk)." LINES\n");
            foreach ($chunk as $data) $read[] = $data;
        }
        if (($data = $socket->readBytes()) !== '') {
            echo(" * READ ".strlen($data)." BYTES\n");
            $read[] = $data;
        } else {
            echo(" * NOTHING TO READ\n");
        }
        return $read;
    }

    function test9($socket)
    {
        # LARGE BULK DELIMITED READ WITH DUAL DELIMITER, LAST LINE WILL BE JUST READ BY READBYTES AS IT HAS NO DELIMITER
        $socket->setDelimiter(["\r\n", "\n"]);
        $read = $socket->readDelimitedBulk();
        if (($data = $socket->readBytes()) !== '') {
            echo(" * READ ".strlen($data)." BYTES\n");
            $read[] = $data;
        } else {
            echo(" * NOTHING TO READ\n");
        }
        return $read;
    }

    function test10($socket)
    {
        # LARGE BULK DELIMITED READ WITH DUAL DELIMITER, STOPPING ON EMPTY LINE, LAST LINE WILL BE JUST READ BY READBYTES AS IT HAS NO DELIMITER
        $read = [];
        $socket->setDelimiter(["\r\n", "\n"]);
        for ($i = 1; $i < 5; $i++) {
            if (!empty($chunk = $socket->readDelimitedBulk(PHP_INT_MAX, PHP_INT_MAX, true, true))) {
                echo(" * BULK DELIMITED READ {$i}, TOTAL ".count($chunk)." LINES\n");
                foreach ($chunk as $data) $read[] = $data;
            }
        }
        if (($data = $socket->readBytes()) !== '') {
            echo(" * READ ".strlen($data)." BYTES\n");
            $read[] = $data;
        } else {
            echo(" * NOTHING TO READ\n");
        }
        return $read;
    }

    function socketReadState($socket, $type)
    {
        $buffer = $socket->skGetReadBuffer($socket::SK_DEFAULT_READ_BUFFER);
        echo(" - SOCKET READ BUFFER ({$type}) STATE: {$buffer->skbCount} CHUNKS, {$buffer->skbSize} BYTES\n");
        return (($buffer->skbCount == 0) && ($buffer->skbSize == 0));
    }

    function readResult($socket, $read, $original)
    {
        $result = $this->socketReadState($socket, 'POST-READ');
        echo(" === BEGIN READ DATA ===\n");
        foreach ($read as $v) echo(" | ".\ATL\Routines::escapeUTF8StringToPrintable($v)."\n");
        echo(" === END READ DATA ===\n");
        $implode = implode('', $read);
        if ($result) {
            $result = $result && !strcmp($implode, $original);
            $result = $result ? 'SUCCESS' : 'FAIL [DATA]';
        } else {
            $result = 'FAIL [STATE]';
        }
        echo(" - READ RESULT: {$result}, ".count($read)." CHUNKS, ".array_sum(array_map('strlen', $read))." BYTES, HASH ".hash('sha256', implode('', $read))."\n\n");
        return $result;
    }

    function writeTestSet($socket)
    {
        $response = [
            "SOME TEST LINE THERE\nHE",
            "RE WE NEED SOME MORE TEST LINES\nDELIMITED BY LINE DELI",
            "MITER BUT SOMEHOW BRO",
            "KEN IN PARTS SO WE CAN TEST READ FUNCTIONS THAT AGGRE",
            "GATE THESE LINES\nAND YAY THERE ARE MULTIPLE\nDELIMITERS\n\nON THE SAME\nBLOCK\nAND NOW",
            " WE NEED ANOTHER TEST LINE THAT HAS DELIMITER ABSENT AT ALL, IT IS THE LAST LINE",
        ];
        $length = array_sum(array_map('strlen', $response));

        $request = [
            "Content-Type: text/plain\r\n",
            [
                "Server: Test suite\r\n",
                "Set-Cookie: xyzzy=abba; Domain=.test.domain; Path=/; ",
                "Expires=Sun, 02 Oct 2026 01:02:03 GMT\r\n",
                "Set-Cookie: abba=xyzzy; Domain=.test.domain; Path=/; Expires=Sun, 02 Oct 2026 01:02:03 GMT\r",
                "\n", # take care, delimiter is deliberately split here to make sure delimited reads scan & aggregate well
            ],
            "Content-Length: {$length}\r\n",
            "\r",
            "\n", # take care, delimiter is deliberately split here again to check delimited reads with zero preceding length
        ];

        $data = $request;
        $data[] = $response;
        $implode = '';
        foreach ($data as $item) {
            if (is_array($item)) {
                $socket->writeBulk($item);
                $implode .= implode('', $item);
            } else {
                $socket->write($item);
                $implode .= $item;
            }
        }
        $this->socketReadState($socket, 'POST-WRITE');

        return $implode;
    }
}

$test = new Test();
$test->testMain();
exit;
