<?php

namespace ATL\Socket\Capabilities;

# additional socket capabilities
# - bulk reads/peeks/writes
# - readBytes()
# - delimited reads (normal and bulk)

# Bulk reads capability

interface IBulk
{
    public function readBulk($asString = false, $maxReadCount = PHP_INT_MAX);
    public function peekBulk($maxPeekCount = PHP_INT_MAX);
    public function writeBulk($lines);
}

trait TBulk
{
    public $socketCapabilityBulk = true;

    # reads all the read buffer (optionally up to the number of data elements provided) and returns as array (default) or optionally as string
    # returns null if there is nothing to read
    public function readBulk($asString = false, $maxReadCount = PHP_INT_MAX)
    {
        if ($this->skReadBuffer->isEmpty()) return $this->skReadResultNothing(); # nothing to read yet

        $data = [];
        for ($i = 0; $i < $maxReadCount; $i++) {
            $data[] = ($line = $this->skReadBuffer->shift());
            $this->skReadRemaining -= strlen($line);
            if ($this->skReadBuffer->isEmpty()) break;
        }
        return $this->skReadResultSuccess($asString ? implode('', $data) : $data);
    }

    # iterates over the receive buffer, honestly, don't know where and how it can be useful, but here it is for completeness, while recommended to avoid using it
    # this is Generator to be used with foreach, so be careful when using it, returns null or number of bytes peeked from buffer as Generator end result
    public function peekBulk($maxPeekCount = PHP_INT_MAX)
    {
        if ($this->skReadBuffer->isEmpty()) return null; # nothing to peek at yet

        $count = 0; $bytes = 0;
        foreach ($this->skReadBuffer as $read) {
            yield $read;
            $count++; $bytes += strlen($read);
            if ($count >= $maxPeekCount) break;
        }
        return $bytes;
    }

    # writes multiple data blocks to the socket, returns number of data bytes in the write buffer so you can throttle writes if necessary
    public function writeBulk($lines)
    {
        if (!$this->skWriteOpen) return false; # write is closed, so nothing is allowed to be written
        if (($result = $this->skPreWriteBulkCheck($lines)) !== null) return $result; # perform pre-write check and return special result if necessary
        foreach ($lines as $line) {
            if ($line !== '') {
                $this->skWriteBuffer->push($line); # push the data to the write buffer if the line is not empty
                $this->skWriteRemaining += strlen($line);
            }
        }
        return $this->skWriteRemaining; # return number of bytes in the write buffer
    }

    # supplements readResult*(), returns null if it is safe to write data normally from write(), otherwise returns result to be returned to write() caller
    # does nothing in the base capability, to be overridden from daughter classes as necessary (i.e. to provide socket polling, etc.)
    protected function skPreWriteBulkCheck($lines) { }
}

# ReadBytes capability (byte stream sockets only)

interface IReadBytes
{
    public function readBytes($count, $exact = false);
}

trait TReadBytes
{
    public $socketCapabilityReadBytes = true;

    # reads up to or exact number of bytes provided as string
    # if no data is available (or not enough data is available for exact read), returns null
    # take care that when exact read is requested, some data may still be remaining in the socket read buffer even if readBytes() returns null
    # take care that when exact read is requested, socket buffer may grow up to requested count and slightly more even if socketMaxDataBuffered is lower, otherwise there is just no way to satisfy reads correctly
    public function readBytes($count, $exact = false)
    {
        if (!$this->skReadRemaining) return $this->skReadResultNothing(); # nothing to read yet
        if ($exact && ($this->skReadRemaining < $count)) return $this->skReadResultNothing(); # not enough bytes for exact read, polling MUST be requested, otherwise we may stall on not enough data buffered
        if ($count <= 0) return null; # requested nothing to read, goodbye
        $data = '';
        while (strlen($data) < $count) {
            $data .= ($line = $this->skReadBuffer->shift());
            $this->skReadRemaining -= strlen($line);
        }
        if (strlen($data) > $count) {
            # we read more bytes than necessary, return the remainder and cut the read, yeah, this is slow
            $this->returnRead(substr($data, $count));
            $data = substr($data, 0, $count);
        }
        return $this->skReadResultSuccess($data);
    }
}

# delimited read capability, requires ReadBytes capability (byte stream sockets only)
interface IDelimitedReads extends \ATL\Socket\Capabilities\IReadBytes
{
    public function setDelimiter($delimiter = null);
    public function readDelimited($maxLineLength = PHP_INT_MAX);
    public function readDelimitedBulk($maxLineLength = PHP_INT_MAX, $maxReadCount = PHP_INT_MAX);
}

trait TDelimitedReads
{
    public $socketCapabilityDelimitedReads = true;

    protected $skDelimiter;
    protected $skDelimiterLength;
    protected $skDelimiterScanPosition;

    protected function skSocketSetup()
    {
        parent::skSocketSetup();
        $this->skDelimiter = null;
        $this->skDelimiterLength = 0;
        $this->skDelimiterScanPosition = 0;
    }

    protected function skReadResultSuccess($result)
    {
        if ($this->skDelimiter !== null) $this->skDelimiterScanPosition = 0; # reset delimiter scan position
        return parent::skReadResultSuccess($result);
    }

    public function returnRead($lines)
    {
        if ($this->skDelimiter !== null) $this->skDelimiterScanPosition = 0; # reset delimiter scan position
        parent::returnRead($lines);
    }

    # sets delimiter to scan read stream for, split read stream by and use in readDelimited() and readDelimitedBulk() calls
    # pass null to stop delimiting input stream and make delimiter read functions fall back to read() / readBulk()
    # when delimiter is set, your hasData handler will *mostly* be called when delimiter possibly exists or appears in the read stream, take care, it may still be called even if there is no complete delimited string to read
    public function setDelimiter($delimiter = null)
    {
        if ($this->skDelimiter !== $delimiter) {
            $this->skDelimiter = $delimiter;
            $this->skDelimiterLength = strlen($delimiter ?? '');
            $this->skDelimiterScanPosition = 0;
        }
    }

    # reads next delimited string from the read stream, including the trailing delimiter
    # returns null if there is nothing to read or no string with delimiter is available yet
    # maxLineLength must always be specified, if read buffer contains enough data to read, maxLineLength bytes are returned from the buffer even if delimiter is not available
    # take care socket buffer may grow up to maxLineLength and slightly more even if socketMaxDataBuffered is lower, otherwise there is just no way to satisfy reads correctly
    # take care maxLineLength only applies to line before the delimiter and not the delimiter itself, so returned line can exceed maxLineLength by up to size of delimiter - 1
    # take care that some data may still be remaining in the socket read buffer even if readDelimited() returns null (it does if there is less than maxLineLength undelimited data available)
    public function readDelimited($maxLineLength = PHP_INT_MAX)
    {
        if ($this->skDelimiter === null) return $this->readBytes($maxLineLength); # no delimiter supplied, fallback to readBytes()
        if ($this->skDelimiterScanPosition > $maxLineLength) return $this->readBytes($maxLineLength, true); # fast path for undelimited line already exceeding maxLineLength
        if ($this->skDelimiterScanPosition > ($this->skReadRemaining - $this->skDelimiterLength)) return $this->skReadResultNothing(); # no delimiter yet and not enough new data above scan position

        # fast forward read buffer until current scan position, scan for possible delimiter then
        $currentPosition = 0; $scanData = null; $scanDataStart = null;
        foreach ($this->skReadBuffer as $line) {
            $lineLength = strlen($line);
            if (($currentPosition + $lineLength) > $this->skDelimiterScanPosition) {
                if ($scanData !== null) { # here is an interesting optimization that avoids doing concatenation on first line using fast pointer assignment, as delimiter is mostly expected to be found in the first line
                    $scanData .= $line;
                } else {
                    $scanData = $line;
                    $scanDataStart = $currentPosition;
                }
                if (($dPos = strpos($scanData, $this->skDelimiter, $this->skDelimiterScanPosition - $scanDataStart)) !== false) {
                    # found delimiter in scan data stream, return either maxLineLength bytes if line without delimiter is longer or up to maxLineLength bytes + delimiter
                    $dPos += $scanDataStart;
                    return $this->readBytes(($dPos <= $maxLineLength) ? $dPos + $this->skDelimiterLength : $maxLineLength, true);
                } else {
                    # still no delimiter in scan data stream
                    $currentPosition += $lineLength;
                    if ($currentPosition >= $this->skDelimiterLength)
                        $this->skDelimiterScanPosition = $currentPosition - $this->skDelimiterLength + 1;
                    if ($this->skDelimiterScanPosition > $maxLineLength) return $this->readBytes($maxLineLength, true); # if undelimited line exceeds maxLineLength, return maxLineLength part of it
                }
            } else {
                # have not yet reached scan position
                $currentPosition += $lineLength;
            }
        }

        # scanned all the buffer and have not yet found the delimiter nor reached maxLineLength
        return $this->skReadResultNothing();
    }

    # same as readDelimited, but reads every line it can from the read buffer at once up to maxReadCount
    public function readDelimitedBulk($maxLineLength = PHP_INT_MAX, $maxReadCount = PHP_INT_MAX)
    {
        if (!$this->skReadRemaining) return $this->skReadResultNothing(); # drop out fast if there is nothing to read

        if ($this->skDelimiter === null) {
            # no delimiter supplied, use readBytes to clamp reads to maxLineLength
            $result = [];
            while ($this->skReadRemaining && (count($result) < $maxReadCount))
                $result[] = $this->readBytes($maxLineLength);
            return $result;
        }

        if (($this->skReadRemaining <= $maxLineLength) && ($this->skDelimiterScanPosition > ($this->skReadRemaining - $this->skDelimiterLength)))
            return $this->skReadResultNothing(); # not exceeding maxLineLength, no delimiter yet, and not enough new data above scan position

        $result = [];
        do {
            # there is only one minor but complex optimization here: if we are to scan from the start of read buffer, we may expect the first block to contain one or multiple delimited lines or exceed maxLineLength
            # this optimization is totally viable despite its complexity because socket data usually comes in good bulk and i.e. text-based delimited protocols usually employ short lines worth just tens of bytes
            if ($this->skDelimiterScanPosition == 0) {
                if (!$this->skReadRemaining) break; # we can reach here multiple times so check if we still have anything to read, if not, break out of the loop
                $data = explode($this->skDelimiter, $this->skReadBuffer->bottom());
                if ((($dCount = count($data)) > 1) || (($d0Length = strlen($data[0])) > $maxLineLength)) {
                    # yes, we have some delimited lines in the first read block or the block exceeds maxLineLength, optimization is viable
                    $this->skReadRemaining -= strlen($this->skReadBuffer->shift()); # remove first read block from the read buffer, we will process and return the rest

                    # process delimited lines if available
                    if ($dCount > 1) {
                        $lCount = $dCount - 1; # avoid last line, it is non-delimited
                        for ($i = 0; $i < $lCount; $i++) {
                            if (strlen($data[$i]) <= $maxLineLength) {
                                # easy path
                                $result[] = $data[$i].$this->skDelimiter;
                                unset($data[$i]);
                            } else {
                                # line exceeds max delimited length, we need to split it down
                                $lines = str_split($data[$i], $maxLineLength);
                                if ((count($result) + count($lines)) <= $maxReadCount) {
                                    # we do not reach max read count or reach it with the last line
                                    $lines[count($lines) - 1] .= $this->skDelimiter; # append delimiter to the last line
                                    foreach ($lines as $line) $result[] = $line; # append lines to the result
                                    unset($data[$i]);
                                } else {
                                    # we reach max read count, so we need to process only part of lines and return the rest back
                                    $pCount = $maxReadCount - count($result);
                                    for ($j = 0; $j < $pCount; $j++) {
                                        $result[] = $lines[$j];
                                        unset($lines[$j]);
                                    }
                                    $data[$i] = implode('', $lines); # compand the remainder back
                                }
                            }
                            if (count($result) >= $maxReadCount) {
                                # whoops, we reached max read count, return remainder
                                $return = implode($this->skDelimiter, $data);
                                if ($return !== '') $this->returnRead($return);
                                return $this->skReadResultSuccess($result);
                            }
                        }
                    }

                    # process data remainder if it is longer than maxLineLength
                    $dLast = $data[$dCount - 1];
                    if (strlen($dLast) > $maxLineLength) {
                        $dLast = str_split($dLast, $maxLineLength);
                        $lCount = count($dLast) - 1; # the last element should remain for further scanning
                        for ($i = 0; $i < $lCount; $i++) {
                            $result[] = $dLast[$i];
                            unset($dLast[$i]);
                            if (count($result) >= $maxReadCount) {
                                # reached max read count, return remainder
                                $return = implode('', $dLast); # imploding back is intentional, we may have optimized read next time as well
                                if ($return !== '') $this->returnRead($return);
                                return $this->skReadResultSuccess($result);
                            }
                        }
                        $dLast = $dLast[$lCount]; # the last element remains for further scanning
                    }

                    # return remainder to read buffer if it is not yet empty, we also consider it scanned
                    if (($dLastLength = strlen($dLast)) > 0) {
                        $this->returnRead($dLast);
                        if ($dLastLength >= $this->skDelimiterLength)
                            $this->skDelimiterScanPosition = $dLastLength - $this->skDelimiterLength + 1;
                    }
                } else {
                    # we do not have any delimited lines in the first block and it does not exceed maxLineLength, but the thing is, we actually have just scanned it for delimiter
                    if ($d0Length >= $this->skDelimiterLength)
                        $this->skDelimiterScanPosition = $d0Length - $this->skDelimiterLength + 1;
                }
            }

            # and after we had the first block processed we do not invent anything new and just use readDelimited() as we still have to serialize and scan the buffer the hard way
            if (($line = $this->readDelimited($maxLineLength)) === null) break;
            $result[] = $line;
        } while (count($result) < $maxReadCount);
        return !empty($result) ? $this->skReadResultSuccess($result) : $this->skReadResultNothing();
    }
}
