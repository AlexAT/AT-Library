<?php

namespace ATL;

# efficient 64-bit mathematical BASE32 encoder/decoder, there is no task wrapping around that as input can just be split by N*5 (encode) or N*8 (decode) characters and fed in in parts
# without JIT (PHP 7.x) mathematical encoder/decoder are only nominally ~10% faster than simple decoders and on long inputs only, but with JIT (PHP 8.x), the difference is tremendous ~50%, both on short and long inputs
# PHP 7.2+ is blazingly fast on integer switch using jump tables, so we use them, this would probably be painfully slow on PHP <7.2 though but who cares in the world of PHP 8.x being mainline

class BASE32
{
    # encode routine, takes input, returns output or throws exception on error
    # encodes in capital letters, does not do '=' padding, pad to 8 characters yourself if necessary
    public static function encode($input)
    {
        if (($iLen = strlen($input)) == 0) throw \Exception("Empty input data");

        # preallocate output, accounting remainder
        $oLen = intdiv($iLen << 3, 5);
        $iRem = $iLen % 5;
        switch ($iRem) {
            case 0: $oRem = 0; break; # clean string without remainder codes
            case 1: $oRem = 2; break; # 1 char = 8 bits = 2 codes
            case 2: $oRem = 4; break; # 2 chars = 16 bits = 4 codes
            case 3: $oRem = 5; break; # 3 chars = 24 bits = 5 codes
            case 4: $oRem = 7; break; # 4 chars = 32 bits = 7 codes
        }
        $output = str_repeat("\x00", $oLen + $oRem);
        if ($oRem == 0) $oRem = 8; # the last byte sequence should output 8 codes

        # encode in units of 5
        $pos = 0; $oPos = 0;
        while ($pos < $iLen) {
            # encode 40 bits
            $encInt = 0;
            for ($i = 0; $i < 5; $i++) {
                if ($pos < $iLen) {
                    $encInt = ($encInt << 8) + ord($input[$pos]); $pos++;
                } else {
                    $encInt = ($encInt << 8);
                }
            }

            # emit codes
            if ($pos < $iLen) {
                # not at the end, full 8 codes
                for ($i = 7; $i >= 0; $i--) {
                    $b32v = $encInt & 0x1F;
                    $output[$oPos + $i] = chr(($b32v >= 26) ? $b32v + 24 : $b32v + 65); # for codes 0-25, x + 65, or for codes 26-31, x + 50 - 26
                    $encInt = $encInt >> 5;
                }
                $oPos += 8;
            } else {
                # end, emit remainder
                switch ($oRem) {
                    case 2: $encInt = $encInt >> 30; break;
                    case 4: $encInt = $encInt >> 20; break;
                    case 5: $encInt = $encInt >> 15; break;
                    case 7: $encInt = $encInt >> 5; break;
                }
                for ($i = $oRem - 1; $i >= 0; $i--) {
                    $b32v = $encInt & 0x1F;
                    $output[$oPos + $i] = chr(($b32v >= 26) ? $b32v + 24 : $b32v + 65); # for codes 0-25, x + 65, or for codes 26-31, x + 50 - 26
                    $encInt = $encInt >> 5;
                }
            }
        }

        return $output;
    }

    # decode routine, takes input, returns output or throws exception on error
    # decodes both capital and normal letters, removes '=' padding without considering its length, checks for proper remainder zero termination
    public static function decode($input)
    {
        if (($iLen = strlen($input)) == 0) throw \Exception("Empty input data");
        if ($input[$iLen - 1] == '=') $input = rtrim($input, '='); # trim padding

        # preallocate output, accounting remainder
        $oLen = ($iLen >> 3) * 5;
        $iRem = $iLen % 8;
        switch ($iRem) {
            case 0: $oRem = 0; break; # clean string without remainder bytes
            case 1: throw new \Exception("Invalid BASE32 sequence (1 remainder character does not account for a byte)"); # 1 char = 5 bits, invalid
            case 2: $oRem = 1; break; # 2 chars = 10 bits = 1 byte
            case 3: throw new \Exception("Invalid BASE32 sequence (3 remainder characters do not account for 2 bytes)"); # 3 chars = 15 bits, invalid
            case 4: $oRem = 2; break; # 4 chars = 20 bits, 2 bytes
            case 5: $oRem = 3; break; # 5 chars = 25 bits, 3 bytes
            case 6: throw new \Exception("Invalid BASE32 sequence (6 remainder characters do not account for 4 bytes)"); # 6 chars = 30 bits, invalid
            case 7: $oRem = 4; break; # 7 chars = 35 bits, 4 bytes
        }
        $output = str_repeat("\x00", $oLen + $oRem);
        if ($oRem == 0) $oRem = 5; # the last byte sequence should output 5 characters

        # decode in units of 8
        $pos = 0; $oPos = 0;
        while ($pos < $iLen) {
            # decode 40 bits
            $decInt = 0;
            for ($i = 0; $i < 8; $i++) {
                if ($pos < $iLen) {
                    $b32c = ord($input[$pos]); $pos++;
                    if (($b32c >= 65) && ($b32c <= 90)) {
                        $b32v = $b32c - 65; # A-Z, x - 65
                    } elseif (($b32c >= 97) && ($b32c <= 122)) {
                        $b32v = $b32c - 97; # a-z, x - 90
                    } elseif (($b32c >= 50) && ($b32c <= 55)) {
                        $b32v = $b32c - 24; # 2-7, x - 50 + 26
                    } else {
                        throw new \Exception("Invalid BASE32 sequence (wrong character code {$b32c})");
                    }
                } else {
                    $b32v = 0; // out of string, pad with zeros
                }

                # store another 5 bits
                $decInt = ($decInt << 5) + $b32v;
            }

            # emit bytes
            if ($pos < $iLen) {
                # not at the end, full 5 bytes
                for ($i = 4; $i >= 0; $i--) {
                    $output[$oPos + $i] = chr($decInt & 0xFF);
                    $decInt = $decInt >> 8;
                }
                $oPos += 5;
            } else {
                # end, emit remainder and check
                switch ($oRem) {
                    case 1: $decInt = $decInt >> 32; break;
                    case 2: $decInt = $decInt >> 24; break;
                    case 3: $decInt = $decInt >> 16; break;
                    case 4: $decInt = $decInt >> 8; break;
                }
                for ($i = $oRem - 1; $i >= 0; $i--) {
                    $output[$oPos + $i] = chr($decInt & 0xFF);
                    $decInt = $decInt >> 8;
                }
                if ($decInt != 0) throw new \Exception("Invalid BASE32 sequence (the end remainder bits are non-zero)");
            }
        }

        return $output;
    }
}
