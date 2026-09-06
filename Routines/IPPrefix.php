<?php

namespace ATL;

interface IIPPrefix
{
    const TEXTTYPE_ADDRESS = 0; # <address> in text representation, if $prefixLength is not specified, maximum prefix length (32 for IPv4, 128 for IPv6) will be assumed
    const TEXTTYPE_ADDRESS_MASK = 1; # <address>/<netmask> in text representation, $prefixLength must be null
    const TEXTTYPE_ADDRESS_LENGTH = 2; # <address>/<length> in text representation, $prefixLength must be null
    const TEXTTYPE_BINARY_ADDRESS = 3; # <address in binary form as octet sequence, MSB first>, if $prefixLength is not specified, maximum prefix length (32 for IPv4, 128 for IPv6) will be assumed
    const TEXTTYPE_BINARY_ADDRESS_NOCHECK = 4; # <address in binary form as octet sequence, MSB first>, if $prefixLength is not specified, maximum prefix length (32 for IPv4, 128 for IPv6) will be assumed, totally like TEXTTYPE_BINARY_ADDRESS, but does no validation, the data must be valid
    const TEXTTYPE_BINARY_ADDRESS_LENGTH = 5; # <address in binary form as octet sequence, MSB first><length in binary form as octet sequence, MSB first>, $prefixLength must be null
    const TEXTTYPE_BINARY_ADDRESS_MASK = 6; # <address in binary form as octet sequence, MSB first><mask in binary form as octet sequence, MSB first>, $prefixLength must be null
    const TEXTTYPE_ADDRESS_ANY = 7; # <address> or <address>/<netmask> or <address>/<length> in text representation, <address> without <netmask> or <length> will use prefixLength
    
    public function __construct($prefixText = null, $prefixLength = null, $textType = self::TEXTTYPE_ADDRESS);

    public function validatePrefix($prefixText, $prefixLength = null, $textType = self::TEXTTYPE_ADDRESS);
    public function setPrefix($prefixText, $prefixLength = null, $textType = self::TEXTTYPE_ADDRESS);

    public function getLength();
    public function setLength($prefixLength);

    public function getBinAddress();
    public function getBinLength();
    public function getBinMask();
    public function getBinNetwork($prefixLength = null);
    public function getBinHost($prefixLength = null);
    public function getBinBroadcast($prefixLength = null);

    public function getTextPrefix($prefixLength = null);
    public function getTextAddress();
    public function getTextMask($prefixLength = null);
    public function getTextNetwork($prefixLength = null);
    public function getTextHost($prefixLength = null);
    public function getTextBroadcast($prefixLength = null);
    
    public function isNetworkAddress($prefixLength = null);
    public function isBroadcastAddress($prefixLength = null);
    public function overlapWith(/** @var \ATL\IPPrefix */ $prefix, $prefixLength = null);
    
    public static function textMaskToLength($textMask);
    public static function binMaskToLength($binMask);
    public static function binLengthToLength($binLength);
    public static function lengthToTextMask($length);
    public static function lengthToBinMask($prefixLength);

    public static function coalesceNetworkList(/** @var \ATL\IPPrefix[] */ $prefixList);
}

abstract class IPPrefix implements IIPPrefix
{
    # these must be redefined in all daughter classes
    const PREFIX_LENGTH = 'abstract'; # sample: IPv4 = 32, IPv6 = 128
    const BYTE_LENGTH = 'abstract'; # sample: IPv4 = 4, IPv6 = 16
    const ZERO_ADDRESS = 'abstract'; # sample: IPv4 = "\x00\x00\x00\x00", IPv6 = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00"
    const ONES_ADDRESS = 'abstract'; # sample: IPv4 = "\xFF\xFF\xFF\xFF", IPv6 = "\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF"

    # these are calculated right on initialization for fast processing
    protected $binAddress = null;
    protected $length = null;

    # these are calculated on demand and stored
    protected $binMask = null;
    protected $binNetwork = null;
    protected $binHost = null;
    protected $binBroadcast = null;
    protected $textPrefix = null;
    protected $textAddress = null;
    protected $textMask = null;
    protected $textNetwork = null;
    protected $textHost = null;
    protected $textBroadcast = null;
    protected $flagNetworkAddress = null; # indicates if the prefix is valid network prefix (if the host part is zero)
    protected $flagBroadcastAddress = null; # indicates if the prefix is valid broadcast prefix (if the host part is one)

    public function __construct($prefixText = null, $prefixLength = null, $textType = self::TEXTTYPE_ADDRESS_ANY)
    {
        if ($prefixText !== null) 
            if (!$this->tryConvertPrefix($prefixText, $this->binAddress, $this->length, $prefixLength, $textType)) throw new \Exception('Invalid prefix'); # initialize prefix directly
    }

    public function validatePrefix($prefixText, $prefixLength = null, $textType = self::TEXTTYPE_ADDRESS_ANY)
    {
        $binAddress = null; $length = null;
        return $this->tryConvertPrefix($prefixText, $binAddress, $length, $prefixLength, $textType);
    }

    public function setPrefix($prefixText, $prefixLength = null, $textType = self::TEXTTYPE_ADDRESS_ANY)
    {
        $binAddress = null; $length = null; # we must make sure we do not overwrite unless we are able to convert
        if (!$this->tryConvertPrefix($prefixText, $binAddress, $length, $prefixLength, $textType)) throw new \Exception('Invalid prefix');
        if ($this->binAddress !== null) $this->resetCachedData();
        $this->binAddress = $binAddress;
        $this->length = $length;
    }

    public function getLength()
    {
        return $this->length;
    }
    
    public function setLength($prefixLength)
    {
        $length = $this->cleanupPrefixLength($prefixLength);
        if ($this->length != $length) {
            $this->length = $length;
            $this->resetCachedData();
        }
    }

    public function getBinAddress()
    {
        return $this->binAddress;
    }
    
    public function getBinLength($prefixLength = null)
    {
        return ($prefixLength === null) ? chr($this->length) : chr($prefixLength); 
    }

    public function getBinMask($prefixLength = null)
    {
        return ($this->binAddress === null) ?
            null
            : (($prefixLength === null) ?
                ($this->binMask ?? ($this->binMask = static::lengthToBinMask($this->length)))
                : static::lengthToBinMask($prefixLength)
            );
    }

    public function getBinNetwork($prefixLength = null)
    {
        return ($this->binAddress === null) ?
            null
            : (($prefixLength === null) ?
                ($this->binNetwork ?? ($this->binNetwork = $this->binAddress & $this->getBinMask()))
                : ($this->binAddress & static::lengthToBinMask($prefixLength))
            );
    }

    public function getBinHost($prefixLength = null)
    {
        return ($this->binAddress === null) ?
            null
            : (($prefixLength === null) ?
                ($this->binHost ?? ($this->binHost = $this->binAddress & ($this->getBinMask() ^ static::ONES_ADDRESS)))
                : ($this->binAddress & (static::lengthToBinMask($prefixLength) ^ static::ONES_ADDRESS))
            );
    }

    public function getBinBroadcast($prefixLength = null)
    {
        return ($this->binAddress === null) ?
            null
            : (($prefixLength === null) ?
                ($this->binBroadcast ?? ($this->binBroadcast = ($this->binAddress | ($this->getBinMask() ^ static::ONES_ADDRESS))))
                : ($this->binAddress | (static::lengthToBinMask($prefixLength) ^ static::ONES_ADDRESS))
            );
    }

    public function isNetworkAddress($prefixLength = null)
    {
        return ($this->binAddress === null) ?
            null
            : (($prefixLength === null) ?
                ($this->flagNetworkAddress ?? ($this->flagNetworkAddress = ($this->getBinNetwork() === $this->binAddress)))
                : ($this->getBinNetwork($prefixLength) === $this->binAddress)
            );
    }

    public function isBroadcastAddress($prefixLength = null)
    {
        return ($this->binAddress === null) ?
            null
            : (($prefixLength === null) ?
                ($this->flagBroadcastAddress ?? ($this->flagBroadcastAddress = ($this->getBinBroadcast() === $this->binAddress)))
                : ($this->getBinBroadcast($prefixLength) === $this->binAddress)
            );
    }

    public function getTextPrefix($prefixLength = null)
    {
        return ($this->binAddress === null) ?
            null
            : (($prefixLength === null) ?
                ($this->textPrefix ?? ($this->textPrefix = $this->getTextAddress().'/'.$this->length))
                : ($this->getTextAddress().'/'.$prefixLength)
            );
    }

    public function getTextAddress()
    {
        return ($this->binAddress === null) ?
            null
            : ($this->textAddress ?? ($this->textAddress = $this->createTextAddress($this->binAddress)));
    }

    public function getTextMask($prefixLength = null)
    {
        return ($this->binAddress === null) ?
            null
            : (($prefixLength === null) ?
                ($this->textMask ?? ($this->textMask = $this->createTextAddress($this->getBinMask())))
                : $this->createTextAddress($this->getBinMask($prefixLength))
            );
    }

    public function getTextNetwork($prefixLength = null)
    {
        return ($this->binAddress === null) ?
            null
            : (($prefixLength === null) ?
                ($this->textNetwork ?? ($this->textNetwork = $this->createTextAddress($this->getBinNetwork())))
                : ($this->createTextAddress($this->getBinNetwork($prefixLength)))
            );
    }

    public function getTextHost($prefixLength = null)
    {
        return ($this->binAddress === null) ?
            null
            : (($prefixLength === null) ?
                ($this->textHost ?? ($this->textHost = $this->createTextAddress($this->getBinHost())))
                : ($this->createTextAddress($this->getBinHost($prefixLength)))
            );
    }

    public function getTextBroadcast($prefixLength = null)
    {
        return ($this->binAddress === null) ?
            null
            : (($prefixLength === null) ?
                ($this->textBroadcast ?? ($this->textBroadcast = $this->createTextAddress($this->getBinBroadcast())))
                : ($this->createTextAddress($this->getBinBroadcast($prefixLength)))
            );
    }

    public function overlapWith(/** @var \ATL\IPPrefix */ $prefix, $prefixLength = null)
    {
        # result: false = different prefixes, 'same' = same prefix, 'super' = superprefix of passed prefix, 'sub' = subprefix of passed prefix, null = one of the prefixes is not initialized
        if ((static::BYTE_LENGTH != $prefix::BYTE_LENGTH) || (static::PREFIX_LENGTH != $prefix::PREFIX_LENGTH)) throw new \Exception('Incompatible prefixes');
        if (($this->binAddress === null) || ($prefix->getBinAddress() === null)) return null; # one of the prefixes is not initialized
        return (($aLen = ($prefixLength ?? $this->length)) == ($bLen = $prefix->getLength())) ?
            (($this->getBinNetwork($prefixLength) === $prefix->getBinNetwork()) ? 'same' : false)
            : (
                ($aLen < $bLen) ?
                    (($this->getBinNetwork($prefixLength) === $prefix->getBinNetwork($aLen)) ? 'super' : false)
                    : (($this->getBinNetwork($bLen) === $prefix->getBinNetwork()) ? 'sub' : false)
            );
    }
    
    protected function resetCachedData()
    {
        $this->binMask = null;
        $this->binNetwork = null;
        $this->binHost = null;
        $this->binBroadcast = null;
        $this->textPrefix = null;
        $this->textAddress = null;
        $this->textMask = null;
        $this->textNetwork = null;
        $this->textHost = null;
        $this->textBroadcast = null;
        $this->flagNetworkAddress = null;
        $this->flagBroadcastAddress = null;
    }
    
    protected static function cleanupPrefixLength($prefixLength)
    {
        if (!preg_match('#^\\d{1,8}$#', $prefixLength ?? '')) throw new \Exception('Invalid prefix length');
        $length = (int) $prefixLength;
        if ($length > static::PREFIX_LENGTH) throw new \Exception('Invalid prefix length');
        return $length;
    }

    protected function tryConvertPrefix($prefixText, &$binAddress, &$length, $prefixLength, $textType)
    {
        if ($textType === static::TEXTTYPE_BINARY_ADDRESS_NOCHECK) {
            # we are asked to do no checks, this may be used for very fast prefix creation from the binary address and length that are expected to be valid
            $binAddress = $prefixText;
            $length = $prefixLength;
        } elseif ($textType !== static::TEXTTYPE_ADDRESS_ANY) {
            $this->convertPrefixToBin($prefixText, $prefixLength, $textType, $binAddress, $length);
        } else {
            try {
                $this->convertPrefixToBin($prefixText, $prefixLength, static::TEXTTYPE_ADDRESS, $binAddress, $length);
            } catch (\Exception $e) {
                try {
                    $this->convertPrefixToBin($prefixText, null, static::TEXTTYPE_ADDRESS_LENGTH, $binAddress, $length);
                } catch (\Exception $e) {
                    try {
                        $this->convertPrefixToBin($prefixText, null, static::TEXTTYPE_ADDRESS_MASK, $binAddress, $length);
                    } catch (\Exception $e) {
                        return false;
                    }
                }
            }
        }
        return true;
    }

    protected function convertPrefixToBin($prefixText, $prefixLength, $textType, &$binAddress, &$length)
    {
        $binAddress = null;
        $length = null;
        switch ($textType) {
            case static::TEXTTYPE_ADDRESS:
            $binAddress = $this->parseTextAddress($prefixText);
            $length = ($prefixLength !== null) ? $length = $this->cleanupPrefixLength($prefixLength) : static::PREFIX_LENGTH;
            break;

            case static::TEXTTYPE_ADDRESS_MASK:
            if ($prefixLength !== null) throw new \ErrorException('Non-null prefixLength value supplied with incompatible prefix text type');
            $parts = explode('/', $prefixText);
            if (count($parts) != 2) throw new \Exception('Invalid prefix/network mask combination');
            $binAddress = $this->parseTextAddress($parts[0]);
            try { $binMask = $this->parseTextAddress($parts[1]); } catch (\Exception $e) { throw new \Exception('Invalid network mask'); }
            $length = static::binMaskToLength($binMask);
            break;

            case static::TEXTTYPE_ADDRESS_LENGTH:
            if ($prefixLength !== null) throw new \ErrorException('Non-null prefixLength value supplied with incompatible prefix text type');
            $parts = explode('/', $prefixText);
            if (count($parts) != 2) throw new \Exception('Invalid prefix/length combination');
            if (!preg_match('#^\\d{1,8}$#', $parts[1])) throw new \Exception('Invalid prefix length');
            $length = (int) $parts[1];
            if ($length > static::PREFIX_LENGTH) throw new \Exception('Invalid prefix length');
            $binAddress = $this->parseTextAddress($parts[0]);
            break;

            case static::TEXTTYPE_BINARY_ADDRESS:
            if (strlen($prefixText) != static::BYTE_LENGTH) throw new \Exception('Invalid prefix');
            $binAddress = $prefixText;
            $length = ($prefixLength !== null) ? $length = $this->cleanupPrefixLength($prefixLength) : static::PREFIX_LENGTH;
            break;

            case static::TEXTTYPE_BINARY_ADDRESS_LENGTH:
            if ($prefixLength !== null) throw new \ErrorException('Non-null prefixLength value supplied with incompatible prefix text type');
            if (strlen($prefixText) != (static::BYTE_LENGTH << 1)) throw new \Exception('Invalid prefix');
            $binAddress = substr($prefixText, 0, static::BYTE_LENGTH);
            $binLength = substr($prefixText, static::BYTE_LENGTH);
            $length = static::binLengthToLength($binLength);
            break;

            case static::TEXTTYPE_BINARY_ADDRESS_MASK:
            if ($prefixLength !== null) throw new \ErrorException('Non-null prefixLength value supplied with incompatible prefix text type');
            if (strlen($prefixText) != (static::BYTE_LENGTH << 1)) throw new \Exception('Invalid prefix');
            $binAddress = substr($prefixText, 0, static::BYTE_LENGTH);
            $binMask = substr($prefixText, static::BYTE_LENGTH);
            $length = static::binMaskToLength($binMask);
            break;

            default: throw new \Exception('Invalid prefix text type');
        }
        if (($length < 0) || ($length > static::PREFIX_LENGTH)) throw new \Exception('Invalid prefix length');
    }

    public static function textMaskToLength($textMask)
    {
        try { $binMask = static::parseTextAddress($textMask); } catch (\Exception $e) { throw new \Exception('Invalid network mask'); }
        return static::binMaskToLength($binMask);
    }
    
    public static function binLengthToLength($binLength)
    {
        if (strlen($binLength) > 1) throw new \Exception('Invalid prefix length');
        $length = ord($binLength);
        if ($length > static::PREFIX_LENGTH) throw new \Exception('Invalid prefix length');
    }

    public static function binMaskToLength($binMask)
    {
        if (strlen($binMask) != static::BYTE_LENGTH) throw new \Exception('Invalid network mask');

        $length = 0;
        for ($i = 0; $i < static::BYTE_LENGTH; $i++) {
            if ($binMask[$i] === "\xFF") {
                $length += 8;
            } else {
                switch (ord($binMask[$i])) {
                    case 0x00: break;
                    case 0x80: $length += 1; break;
                    case 0xC0: $length += 2; break;
                    case 0xE0: $length += 3; break;
                    case 0xF0: $length += 4; break;
                    case 0xF8: $length += 5; break;
                    case 0xFC: $length += 6; break;
                    case 0xFE: $length += 7; break;
                    default: throw new \Exception('Non-contiguous network mask');
                }
                $i++; # continue from the next byte
                break;
            }
        }

        for (; $i < static::BYTE_LENGTH; $i++)
            if ($binMask[$i] !== "\x00") throw new \Exception('Non-contiguous network mask');

        return $length;
    }

    public static function lengthToTextMask($prefixLength)
    {
      return static::createTextAddress(static::lengthToBinMask($prefixLength));
    }

    public static function lengthToBinMask($prefixLength)
    {
        $prefixLength = static::cleanupPrefixLength($prefixLength);

        $bytes = $prefixLength >> 3;
        $bits = $prefixLength & 0x07;
        $remainder = (static::PREFIX_LENGTH - $prefixLength) >> 3;

        $binMask = '';
        if ($bytes > 0) $binMask = str_repeat("\xFF", $bytes);
        if ($bits > 0) $binMask .= chr((0xFF << (8 - $bits)) & 0xFF);
        if ($remainder > 0) $binMask .= str_repeat("\x00", $remainder);
        return $binMask;
    }
    
    public static function coalesceNetworkList(/** @var \ATL\IPPrefix[] */ $prefixList, $joinAdjacent = true)
    {
        if (empty($prefixList)) return [];

        # when prefixes are sorted by their binary MSB-first representation, length included, we can go with single pass algorithm for eliminating both subnetworks and adjacent prefixes
        # pre-index prefixes by binary address, eliminating direct subprefixes with same network numbers, then sort by binary address
        $list = [];
        foreach ($prefixList as $prefix)
            if (!isset($list[$id = $prefix->getBinNetwork()]) || ($list[$id]->getLength() > $prefix->getLength())) $list[$id] = $prefix;
        ksort($list, SORT_STRING);

        /* @var \ATL\IPPrefix */ $upPrefix = null;
        foreach ($list as $id => $prefix) {
            if ($upPrefix !== null) {
                if ($upPrefix->overlapWith($prefix) === 'super') { # check if we are subprefix of previous prefix
                    # eliminate
                    unset($list[$id]);
                    continue;
                } elseif ($joinAdjacent) {
                    # if we think a bit, to coalesce, we may only be the 'one' subnetwork of some other similar 'zero' subnetwork to coalesce, so we check for presence of 'zero' one but then exclude the case we are one
                    $lastNet = null;
                    $prefixLen = $prefix->getLength();
                    while (($prefixLen > 0) && isset($list[$coalesceNet = $prefix->getBinNetwork($coalesceLen = $prefixLen - 1)]) && ($list[$coalesceNet] !== $prefix) && ($list[$coalesceNet]->getLength() == $prefixLen)) {
                        # remove us and continue trying above subnets until we reach /0 or are not having prefix to coalesce with anymore
                        unset($list[$prefix->getBinNetwork()]);
                        $prefix = $list[$lastNet = $coalesceNet];
                        $prefixLen = $coalesceLen;
                    }
                    if ($lastNet !== null) $list[$lastNet] = $prefix = new static($lastNet, $prefixLen, static::TEXTTYPE_BINARY_ADDRESS_NOCHECK); # finally replace the uppermost coalesced prefix with correct length,  use quick initialization as the data is valid
                }
            }
            $upPrefix = $prefix;
        }
        
        return $list;
    }
    
    abstract protected static function parseTextAddress($textAddress);
    abstract protected static function createTextAddress($binAddress);
}

class IPv4Prefix extends IPPrefix
{
    const PREFIX_LENGTH = 32;
    const BYTE_LENGTH = 4;
    const ZERO_ADDRESS = "\x00\x00\x00\x00";
    const ONES_ADDRESS = "\xFF\xFF\xFF\xFF";

    protected static function parseTextAddress($textAddress)
    {
        # why we are not using inet_pton() here? because it may allow for some weird representations like octal that we do want to avoid
        $octets = explode('.', trim($textAddress), 5); # why 5? any extra beyond 4 is an error, no need to extract further
        if (count($octets) != 4) throw new \Exception('Invalid IPv4 prefix');
        $octets = preg_grep('#^(?:[0-1][0-9][0-9]|2[0-4][0-9]|25[0-5]|[0-9][0-9]|[0-9])$#S', $octets);
        if (count($octets) != 4) throw new \Exception('Invalid IPv4 prefix');
        return chr($octets[0]).chr($octets[1]).chr($octets[2]).chr($octets[3]);
    }

    protected static function createTextAddress($binAddress)
    {
        return inet_ntop($binAddress); # using inet_ntop() is totally fine
    }
}

class IPv6Prefix extends IPPrefix
{
    const PREFIX_LENGTH = 128;
    const BYTE_LENGTH = 16;
    const ZERO_ADDRESS = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00";
    const ONES_ADDRESS = "\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF";

    protected static function parseTextAddress($textAddress)
    {
        $binAddress = @inet_pton($textAddress);
        if (($binAddress === false) || (strlen($binAddress) != static::BYTE_LENGTH))
            throw new \Exception('Invalid IPv6 prefix');
        return $binAddress;
    }

    protected static function createTextAddress($binAddress)
    {
        return inet_ntop($binAddress);
    }
}
