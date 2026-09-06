<?php

namespace ATL;

########
# This file contains very small minor helper classes that do not deserve their separate class files

# ValueObject: used to hold arbitrary value, for rare cases where there is need to distinguish between value and no value but value can be absolutely anything
class ValueObject
{
    public $value;

    public function __construct($value)
    {
        $this->value = $value;
    }
}

# StreamBase64Data: minimal stream wrapper that can be used to instantiate and read arbitrary base64-encoded contents, designed for dynamic code inclusion
# Actually data:// wrapper exists in PHP for similar purposes but alas it is restricted by URL inclusion limit while this one is not
# Instantiate with any wrapper URL method, supply base64-encoded content right after ://
class StreamBase64Data
{
    public $context;

    protected $data;
    protected $position;
    protected $time;

    public function __construct()
    {
        $this->data = '';
        $this->position = 0;
        $this->time = time();
    }

    public function stream_open($path, $mode, $options, &$opened_path)
    {
        $this->data = base64_decode(preg_replace('#^\\S+\\:\\/\\/#', '', $path));
        $this->position = 0;
        $this->time = time();
        return true;
    }

    public function stream_set_option($option, $arg1, $arg2)
    {
        return false;
    }

    public function stream_read($count)
    {
        $out = substr($this->data, $this->position, $count);
        $this->position = min(strlen($this->data), $this->position + $count);
        return $out;
    }

    public function stream_eof()
    {
        return ($this->position >= strlen($this->data));
    }

    public function stream_tell()
    {
        return $this->position;
    }

    public function stream_seek($offset, $whence)
    {
        switch ($whence)
        {
            case SEEK_SET:
            $this->position = min($offset, strlen($this->data));
            return true;

            case SEEK_CUR:
            $this->position = max(0, min($offset, $this->position + $offset));
            return true;

            case SEEK_END:
            $this->position = strlen($this->data);
            return true;

            default:
            return false;
        }
    }

    public function stream_stat()
    {
        return [
            'dev' => 0,
            'ino' => 0,
            'mode' => 0100640,
            'nlink' => 1,
            'uid' => 0,
            'gid' => 0,
            'rdev' => 0,
            'size' => strlen($this->data),
            'atime' => $this->time,
            'mtime' => $this->time,
            'ctime' => $this->time,
            'blksize' => 0,
            'blocks' => 0,
        ];
    }
}
