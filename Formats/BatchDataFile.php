<?php

namespace ATL;

########
# batch data files implement simple file structure where you can write array based batches one by one or by series of entries
# each batch is basically an array of key => value data, the file is only open on each complete batch write and on read, and kept closed otherwise
# you can read the whole batch file at once into array, overlapping keys will be replaced by later batch entries
# the benefit of this approach is that you can calculate data to be written in portions, conserving memory, and reads also happen in same portions
# this is conserving memory for string transformations during read and write processes, and allowing you to conserve memory on calculations
# writes append batches to existing files, so you do not need to rewrite the whole file when you just need to update few entries in the large batch file
# while batch data files may surely look like text files with serialized data, they are actually used as binary, optimized for fast fread() calls

# batch file format:
# each batch is self-sufficient, there are no global headers
# each batch header 'line' starts with two digits ("XX") evaluating to decimal 'batch data length' field length
# space follows, then XX digits of serialized batch data length ("LLL...") follow, then \n follows
# so each batch header looks like "XX LLL...\n", i.e. "04 9718\n", indicating batch of 9718 bytes serialized, NOT including \n character after the serialized data
# after batch header, serialize()d batch data of length LLL... follows, terminated with \n character (not included in length)
# example of batch serialization of two simple arrays [1 => 'one', 2 => 'two'] and ['3' => 'three', '2' => null]

# 02 34\n
# a:2:{i:1;s:3:"one";i:2;s:3:"two";}\n
# 02 28\n
# a:2:{i:3;s:5:"three";i:2;N;}\n

# optionally, you can implement a merge function for batch reading that accepts (&$data, $batch), where $data is full data read till function call
# you can i.e. use it to auto-remove nulls on read and/or transform batch data in some way you want, an example null filtering function is provided as mergeRemovingNULL in this class
# also you can actually write empty batches by using writeBatch with WRITE_PREFLUSH or WRITE_OOB mode and empty array passed as batch, this is meaningless but can be used
# as some kind of marker to the merge function you devise

# you can also optionally set a preprocessor function to i.e. compress/decompress data stored to file / read from file
# preprocessor function accepts ($data, $mode), where mode is one of PREPROCESS_READ or PREPROCESS_WRITE and should return preprocessed data (batch on read, string on write)
# if preprocessor function is set, it must perform batch serialization/deserialization itself, input / output is expected to be an array
# an example preprocessor function is provided as preprocessGZIP in this class

# batch write modes:
#   WRITE_BUFFER - buffer batch and write only full size batches from it along with existing batch data
#   WRITE_BUFFER_FLUSH - same as WRITE_BUFFER, but calls flush() before return
#   WRITE_PREFLUSH - write existing batch and then batch supplied
#   WRITE_OOB - write batch supplied disregarding existing batch and batch size (take care! causes data to be out of order, use only when you know what you are doing)

# do not forget to call flush() after writing all the data if you use buffering or appending, otherwise you may lose the last batch data buffered

interface IBatchDataFile
{
    const WRITE_BUFFER = 0;
    const WRITE_PREFLUSH = 1;
    const WRITE_OOB = 2;
    const WRITE_BUFFER_FLUSH = 3;

    const PREPROCESS_READ = 0;
    const PREPROCESS_WRITE = 1;

    public function readBatchFile($readMergeFunction = null);
    public function appendEntry($index, $value, $batchSize = null);
    public function appendBatch($batch, $batchSize = null);
    public function writeBatch($batch, $mode = self::WRITE_BUFFER, $batchSize = null);
    public function flush();
    public function getCurrentBatchCount();
}

trait TBatchDataFile
{
    protected $fileName;
    protected $batchSize;
    protected $readMergeFunction;
    protected $preprocessor;

    protected $batch;
    protected $hadWrites; # used to indicate if we should touch the file on flush

    public function __construct($fileName, $batchSize = 1000, $readMergeFunction = null, $preprocessor = null)
    {
        $this->fileName = $fileName;
        $this->batchSize = $batchSize;
        $this->readMergeFunction = $readMergeFunction;
        $this->preprocessor = $preprocessor;

        $this->batch = [];
        $this->hadWrites = false;
    }

    public function readBatchFile($readMergeFunction = null)
    {
        $data = [];

        $readMergeFunction = $readMergeFunction ?? $this->readMergeFunction;
        if (!$fh = fopen($this->fileName, 'rb')) throw new \Exception("Could not open batch file {$this->fileName} for reading");
        try {
            $batchNumber = 1;
            while (!feof($fh)) {
                $lenLen = fread($fh, 2);
                if (($lenLen === '') && feof($fh)) break; # end of file encountered

                if (!preg_match('#^\\d{2}$#S', $lenLen)) throw new \Exception("Invalid data length length field read from batch file, batch {$batchNumber}");
                if (fread($fh, 1) !== ' ') throw new \Exception("Expected space after data length length field read from batch file, batch {$batchNumber}");
                if (!preg_match("#^\\d{{$lenLen}}$#S", $batchLen = fread($fh, $lenLen))) throw new \Exception("Invalid data length field read from batch file, batch {$batchNumber}");
                if (fread($fh, 1) !== "\n") throw new \Exception("Expected \\n after data length field read from batch file, batch {$batchNumber}");

                $batch = fread($fh, $batchLen);
                if (strlen($batch) != $batchLen) throw new \Exception("Expected {$batchLen} bytes batch, but read ".strlen($batch)." bytes, batch {$batchNumber}");
                $batch = ($this->preprocessor === null) ? unserialize($batch) : $this->preprocessor($batch, self::PREPROCESS_READ);
                if (!is_array($batch)) throw new \Exception("Expected batch data read from batch file to successfully deserialize into array, batch {$batchNumber}");
                if (fread($fh, 1) !== "\n") throw new \Exception("Expected \\n after batch data read from batch file, batch {$batchNumber}");

                if ($readMergeFunction === null) {
                    # just a good old direct merge - note we do not use array_merge there because we do not know anything about keys nature
                    foreach ($batch as $k => $v) $data[$k] = $v;
                } else {
                    # call merge function
                    $readMergeFunction($data, $batch);
                }

                $batchNumber++;
            }
        } catch (\Exception $e) {
            fclose($fh);
            throw $e;
        }
        fclose($fh);

        return $data;
    }

    public function appendEntry($index, $value, $batchSize = null)
    {
        $this->batch[$index] = $value;
        if (count($this->batch) >= ($batchSize ?? $this->batchSize)) $this->flush();
    }

    public function appendBatch($batch, $batchSize = null)
    {
        $batchSize = $batchSize ?? $this->batchSize;
        foreach ($batch as $k => $v) {
            $this->batch[$k] = $v;
            if (count($this->batch) >= $batchSize) $this->flush();
        }
    }

    public function writeBatch($batch, $mode = self::WRITE_BUFFER, $batchSize = null)
    {
        if (!is_array($batch)) throw new \Exception("Batch to be written to the batch file should be an array");
        if ($mode == self::WRITE_OOB) {
            # direct out of band write disregarding batch size
            $this->writeBatchToFile($batch);
            return;
        }
        if ($mode == self::WRITE_PREFLUSH) $this->flush();
        $this->appendBatch($batch, $batchSize);
        if ($mode == self::WRITE_BUFFER_FLUSH) $this->flush();
    }

    public function flush()
    {
        if (count($this->batch) > 0) {
            $this->writeBatchToFile($this->batch);
            $this->batch = [];
        } elseif (!$this->hadWrites) {
            # touch the file to indicate it exists
            touch($this->fileName);
        }
    }

    public function getCurrentBatchCount()
    {
        return count($this->batch);
    }

    protected function writeBatchToFile($batch)
    {
        if (!$fh = fopen($this->fileName, 'ab+')) throw new \Exception("Could not open batch file {$this->fileName} for appending");
        $batch = ($this->preprocessor === null) ? serialize($batch) : $this->preprocessor($batch, self::PREPROCESS_WRITE);
        $batchLen = strlen($batch);
        $lenLen = strlen($batchLen);
        if (($lenLen <= 0) || ($lenLen > 99)) throw new \Exception("Invalid batch data length encountered, something is terribly wrong: {$lenLen}");
        $lenLen = str_pad($lenLen, 2, '0', STR_PAD_LEFT);
        fwrite($fh, "{$lenLen} {$batchLen}\n");
        fwrite($fh, $batch);
        fwrite($fh, "\n");
        fclose($fh);
        $this->hadWrites = true;
    }

    # merge function example, unsets NULL entries on load
    public static function mergeRemovingNULL(&$data, $batch)
    {
        foreach ($batch as $k => $v) {
            if ($v !== null) {
                $data[$k] = $v;
            } else {
                unset($data[$k]);
            }
        }
    }

    # preprocess function example, compresses/decompresses GZIP data
    public static function preprocessGZIP($data, $mode)
    {
        switch ($mode) {
            case self::PREPROCESS_READ:
            return unserialize(gzdecode($data));

            case self::PREPROCESS_WRITE:
            return gzencode(serialize($data));

            default: throw new Exception("Invalid preprocessing mode");
        }
    }
}

class BatchDataFile implements \ATL\IBatchDataFile { use \ATL\TBatchDataFile; }
