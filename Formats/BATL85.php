<?php

namespace ATL;

########
# BATL85 encoding, 64-bit registers only, ATL-specific variant of BASE85 encoding using JSON-safe RFC1924 character set
# Optionally uses compact 2-byte encoding for common text file sequences (pass BATL85_ENCODE_SEQUENCES flag to encoder), the list of sequences is not planned to be extended
# BATL85 encoder/decoder is NOT compatible with ASCII85 or BASE85 encoding as it uses its own extended encoding scheme and also specifies how to encode/decode data of arbitrary length
# I tried to make both encoder and decoder as work conserving as possible, but was not squeezing every last bit of performance for the sake of saving time and ensuring readability
# While BATL85 base implementation has different compression means embedded, I recommend to prefer external compression before encoding over them, they are there just for the sake of it

# Character set
# 0-9 are '0'-'9' (#30-#39), 10-35 are 'A'-'Z' (#41-#5A), 36-61 are 'a'-'z' (#61-#7A)
# #21 ('!') for 62, #23-#26 ('#$%&') for 63-66, #28-#2B ('()*+') for 67-70, #2D ('-') for 71
# #3B-#40 (';<=>?@') for 72-77, #5E-#60 ('^_`') for 78-80, #7B-#7E ('{|}~') for 81-84

# BATL85 routines are ready to be used as standalone or as Task, you need to create BATL85EncodeTask and BATL85DecodeTask objects if you want one
# They relinquish control at each output stream preallocation, so roughly at each 256 bytes encoded or decoded, but otherwise run as realtime tasks (yielding true)
# Task result will be equal to the encode()/decode() result, task will also copy lastError/lastErrorText/lastDecodePosition to its public resLastError/resLastErrorText/resLastDecodePosition properties

interface IBATL85
{
    # BATL85 encoder/decoder error codes and texts
    const errorOK = 0; # this code is unused
    const errorInvalidCharacter = 1; # this error is only raised if BATL85_STRICT_CHARSET flag is provided to the decoder if some non-BATL85 character is encountered in the stream, last decode position points to erring character
    const errorInvalidCode = 2; # this error is raised if decoder detects invalid code sequence in BATL85 stream (code out of allowed range, wrong code 84 sequence, etc.), last decode position points to start of the code
    const errorInvalidEnd = 3; # this error is raised if decoder detects invalid end of stream (final code out of allowed range or no code after EOS code), last decode position points to start of the erring code or right after EOS code
    const errorNoEOS = 4; # this error is raised when decoder is provided with BATL85_CHECK_EOS_CODE flag and the stream (or last stream with MULTIPLE_STREAMS) does not contain end of stream code, last decode position points to after end of stream
    const errorEmpty = 5; # this error is raised when encoder is provided with empty stream and BATL85_ADD_EOS_CODE flag is not provided so the stream cannot be encoded
    const errorTexts = [
        self::errorOK => 'No error',
        self::errorInvalidCharacter => 'Invalid character',
        self::errorInvalidCode => 'Invalid code',
        self::errorInvalidEnd => 'Invalid end of stream',
        self::errorNoEOS => 'No EOS',
        self::errorEmpty => 'Empty stream',
    ];

    # common to encoder and decoder, preallocating output in 256 byte chunks seems to be the most viable value in terms of performance tradeoff
    const BATL85_STRING_PREALLOCATION_SIZE = 0x100; # do not change this

    # BATL85 encoder flags
    const BATL85_ADD_EOS_CODE     = 0x00000001; # adds end of stream code before last BATL85 code sequence output, allows to pack multiple BASE85 streams into one text or verify stream completeness
    const BATL85_ENCODE_SEQUENCES = 0x00000002; # use sequence encoder to tightly encode sequences commonly occuring in text, decreases performance a bit but adds reduces output by another few % (5-7% on PHP code)
    const BATL85_SEQENC_ALLOW_RLE = 0x00000004; # (reserved, not implemented yet) valid only if sequence encoder (BATL85_ENCODE_SEQUENCES) is enabled, allows RLE encoding for 6 to 89 consecutive bytes of the same value, decreases performance
    const BATL85_SEQENC_SCAN_BACK = 0x00000008; # (reserved, not implemented yet) valid only if sequence encoder (BATL85_ENCODE_SEQUENCES) is enabled, allows to add ~7K sliding window backwards scanning compression, decreases performance, increases RAM usage

    # BATL85 decoder flags
    const BATL85_STRICT_CHARSET   = 0x00000001; # return error on any non-BASE85 character encountered in the input stream (the default is to skip any non-BASE85 characters), except whitespace (#00, #09, #0A, #0D, #20) characters
    const BATL85_NO_WHITESPACE    = 0x00000002; # return error on any whitespace (#09, #0A, #0D, #20) character encountered in the input stream (the default is to skip any whitespace even with BATL85_STRICT_CHARSET)
    const BATL85_CHECK_EOS_CODE   = 0x00000004; # make sure last code before stream ends (before last BATL85 code in the stream) is end of stream code, to be used with BATL85_ADD_EOS_CODE, for BATL85_MULTIPLE_STREAMS, acts on the very last stream
    const BATL85_MULTIPLE_STREAMS = 0x00000008; # continue decoding more BATL85 streams after end of stream code and last BATL85 code sequence is encountered, decoder returns array of streams (one or more), to be used with BATL85_ADD_EOS_CODE
    const BATL85_DECODE_MULTIPLE  = 0x0000000C; # it is wise and recommended to provide this one instead of just BATL85_MULTIPLE_STREAMS so end of stream code is also checked in the last stream unless this is some very special case of encoding
}

trait TBATL85
{
    public static $lastError; # valid only if false is returned by encoder / decoder, contains BATL85 error code
    public static $lastErrorText; # valid only if false is returned by encoder / decoder, contains BATL85 error description
    public static $lastDecodePosition; # after decoding, contains position of the octet next to stream in case of success or to the point error occured at (erring octet / octet that starts the erroneous code / octet after stream) in case of error

    ########
    # Encoding algorithm:
    #   Take 4 byte values (A, B, C, D) from the input stream. If some byte does not exist (end of stream), it is no problem as 2 codes encode 1 byte, 3 codes encode 2 bytes, 4 codes encode 3 bytes and 5 codes encode 4 bytes so length of BATL85 stream matters
    #
    #   The following section is not mandatory to implement in the encoder, or it can be implemented partially, but it MUST be implemented in full in the decoder
    #   ------
    #     Check if start of the sequence is one of the quick sequences, if it is, encode directly into 2 byte sequence instead of 5 bytes and continue from after the encoded part, EXCEPT if this is the last code of the stream
    #       BATL85 quick sequences are sets of 3 and 4 characters that are often encountered in bulk and at the end of the stream
    #       They are encoded as 2-byte sequences starting with code 84, then subcode indicating the sequence and its length (2 groups of 32 subcodes for lengths of 4 and 3 consecutively, effective subcode ranges of 00-31 for length 4 and 32-63 for length 3)
    #       Sequences of length 1-2 are not encoded in that way, it conserves subcode space and is just not effective anyhow nor is worth any extra checking and handling
    #       Base subcodes are: 00 - #00, 01 - #FF, 02 - #20, 03 - #09 (tab), 04 - #0A (LF), 05 - #0D (CR), 06 - #23 ('#'), 07 - #28 ('('), 08 - #29 (')'), 09 - #2A ('*')
    #                          10 - #2B ('+'), 11 - #2D ('-'), 12 - #2E ('.'), 13 - #30 ('0'), 14 - #3B (';'), 15 - #3D ('='), 16 - #5B ('['), 17 - #5D (']'), 18 - #5F ('_'), 19 - #7B ('{')
    #                          20 - #7D ('}')
    #       Subcode 64 indicates 4-character sequence of #0A #0D #0A #0D
    #       Subcode 65 indicates 4-character sequence of #0D #0A #0D #0A
    #       Subcodes 66-76 are not defined and there are no plans to define them in the base implementation
    #       Subcode 77 is used for optional RLE encoding for any arbitrary repeating value for up to 89 consecutive bytes, with a minimum of 6 bytes to be encoded, ineffective otherwise as RLE sequence takes 5 codes
    #         The 1st immediate code after subcode 77 is repeat count minus 6 (0 specifies 6 bytes, 83 specifies 89 bytes), the 2nd and 3rd codes contain encoded byte value to be repeated
    #         With known characters covered by above short codes, base implementation encodes only above a minimum of 9 bytes because two 4-byte sequences of known characters can be encoded into 4 codes
    #       Subcode 78 is used for optional backwards scanning sliding window compression, allowing for already decompressed sequences of up to 89 consecutive bytes, as far as 7061 bytes back, to be reinjected into the output
    #         The 1st and 2nd immediate codes after subcode 78 encode sequence distance (0/0 means 6 bytes back, 83/83 means 7061 bytes back), the 3rd code contains sequence length (0 means 6 bytes, 83 means 89 bytes)
    #         IMPORTANT: For encoder/decoder simplicity, it is dictated here that length MUST NEVER exceed the distance, and decoders MUST verify that length does not exceed the distance, producing error if it does
    #         If RLE encoding is enabled, the base implementation checks both sliding window sequence length and repetition sequence length, preferring what would encode the longest sequence
    #         The base implementation uses very simple hashing algorithm for sliding window, maintaining queue of 7056 4-byte sequences for removal and array of 4-byte sequences scan points for comparison
    #       Subcode 79 is special: it indicates immediate end of stream without any code following and is only used to encode empty streams when BATL85_ADD_EOS_CODE is provided, handy for BATL85_MULTIPLE_STREAMS encoding
    #       Subcodes 80-83 after code 84 indicate the next code will be last in the stream, subcode 80 means 1 byte encoded (2 codes), 81 - 2 bytes (3 codes), 82 - 3 bytes (4 codes max), 83 - normal 4 bytes (5 codes max)
    #         The encoder encodes sequence 84 with subcodes 80-83 if provided with BATL85_ADD_EOS_CODE flag, this flag should be provided along with each stream if you want to join multiple streams together
    #         The decoder honors the end of stream codes and stops decoding after next code following the end of stream code, use BATL85_MULTIPLE_STREAMS flag to decode multiple consecutive streams and return them as array
    #         The decoder can verify presence of end of stream code when finishing decoding stream, provide BATL85_CHECK_EOS_CODE flag to the decoder to do this, with BATL85_MULTIPLE_STREAMS it forces checking last stream to have end of stream code
    #         In case of 3-byte or 4-byte ending stream can still be ending with code 84 sequence instead, in this case only 2 codes will be written for it, that is why 'max' word is added to the final code length in the subcodes decription
    #         In theory, any sequence with subcodes 80-83 followed by code 84 and sequence subcode should do the work of writing intended 3 or 4 bytes, but encoder writes subcodes strictly as defined and decoder validates the final sequence length, take care
    #       Sequences 84 84 (subcode 84 and more subcodes 84) can theoretically be used to extend code 84 encoding in the future and in sub-implementations, but there are no plans to use such sequences in the base implementation
    #   ------
    #
    #   If there is no special sequence or sequence encoder is not enabled/implemented, just take byte values DCBA as one integer (so A as the first and the least significant byte, D is the last and the most significant byte)
    #   Start dividing this integer by 84, then 85, 85, 85 and 85 again and record remainders as resulting codes, if the source is at the end of stream and is 3 bytes, stop at 4 codes, for 2 bytes, stop at 3 codes, for 1 byte, stop at 2 codes
    #   Repeat all of the above until the end of the input stream

    # encode routine wrapper, takes input stream and processing flags, returns encoded stream or false on error
    public static function encode($input, $flags = 0)
    {
        $generator = self::encodeGenerator($input, $flags);
        foreach ($generator as $unused); # encode generator relinquishes control but we do not need it in plain runtime case
        return $generator->getReturn();
    }

    # decode routine wrapper, takes input stream or multiple streams and processing flags, returns decoded stream or false on error
    public static function decode($input, $flags = 0)
    {
        $generator = self::decodeGenerator($input, $flags);
        foreach ($generator as $unused); # decode generator relinquishes control but we do not need it in plain runtime case
        return $generator->getReturn();
    }

    # internal Generator, takes input stream and processing flags, returns encoded stream or false on error
    # relinquishes control at each 256 (or a bit less) source bytes handled to facilitate using in Task code
    protected static function encodeGenerator($input, $flags = 0)
    {
        $position = 0; # input position
        $seqEncoder = (bool) ($flags & self::BATL85_ENCODE_SEQUENCES); # used a lot, so prepared as local variable
        if (($length = strlen($input)) == 0) {
            # special handling for empty streams
            if ($flags & self::BATL85_ADD_EOS_CODE) {
                $output = "\x7E\x5F"; # 84 79
                $oPos = 2;
                goto endEncode;
            } else {
                self::$lastError = self::errorEmpty;
                self::$lastErrorText = self::errorTexts[self::$lastError];
                return false;
            }
        }

        # start encoding, declaring some volatile variables out of loop to prevent checking for declared or not
        $output = '';
        $output[($length >> 2) * 5 + 2] = "\x00"; # as concatenation operation is very slow, preallocate output buffer with 2 byte slack for possible end of stream code, we cut it before returning
        $oPos = 0; # output position
        $ival = 0; # input integer
        $ivalA = 0; # first byte of input integer for sequence encoder
        $seq4 = false; # sequence encoder indicator of when we are encoding 4-byte or 3-byte sequence
        $nCodes = 5; # default code length
        while ($length > 0) {
            switch ($length) { # happens only once during encoding, nCodes indicate
                case 1:
                $nCodes = 2;
                if ($flags & self::BATL85_ADD_EOS_CODE) { $output[$oPos] = "\x7E"; $output[$oPos + 1] = "\x60"; $oPos += 2; } # 84 80
                $ival = ord($input[$position]);
                $position++; $length = 0;
                break;

                case 2:
                $nCodes = 3;
                if ($flags & self::BATL85_ADD_EOS_CODE) { $output[$oPos] = "\x7E"; $output[$oPos + 1] = "\x7B"; $oPos += 2; } # 84 81
                $ival = ord($input[$position]) + (ord($input[$position + 1]) << 8);
                $position += 2; $length = 0;
                break;

                case 3:
                $nCodes = 4;
                if ($flags & self::BATL85_ADD_EOS_CODE) { $output[$oPos] = "\x7E"; $output[$oPos + 1] = "\x7C"; $oPos += 2; } # 84 82
                $ival = ord($input[$position]) + (ord($input[$position + 1]) << 8) + (ord($input[$position + 2]) << 16);
                $position += 3; $length = 0;
                break;

                case 4:
                # everything else results in 5 code encodes, but this is a tiny weensy special occasion where we may need end of stream code
                if ($flags & self::BATL85_ADD_EOS_CODE) { $output[$oPos] = "\x7E"; $output[$oPos + 1] = "\x7D"; $oPos += 2; } # 84 83
                $ival = ord($input[$position]) + (ord($input[$position + 1]) << 8) + (ord($input[$position + 2]) << 16) + (ord($input[$position + 3]) << 24); # avoiding unpack(substr()) costs us two extra ord() but gains NOT creating an array
                $position += 4; $length = 0;
                break;

                default:
                # nothing special on normal 4 byte encode
                $ival = ord($input[$position]) + (ord($input[$position + 1]) << 8) + (ord($input[$position + 2]) << 16) + (ord($input[$position + 3]) << 24); # avoiding unpack(substr()) costs us two extra ord() but gains NOT creating an array
                $position += 4; $length -= 4;
                if (($position & 0xFF) >= 0xFC) yield true; # relinquish control if we encoded another 256 bytes (or a bit less)
                break;
            }

            # sequence encoder (encodes only 4 and 5 codes sequences, so 3 or 4 input bytes)
            if ($seqEncoder && ($nCodes >= 4)) {
                if (
                    (($ivalA = ($ival & 0xFF)) == (($ival >> 8) & 0xFF))
                    && (($ival & 0xFF) == (($ival >> 16) & 0xFF))
                ) {
                    if (($seq4 = ($nCodes == 5)) && (($ival & 0xFF) != (($ival >> 24) & 0xFF))) {
                        # last octet is different, so we need to encode 3-byte sequence in case it exists and leave last byte for the next code sequence
                        # there is a tricky issue here, if this was to be the last code, we will have one more code afterwards, so we need to replace last code marker and restore length
                        $seq4 = false;
                        if (!$length && ($flags & self::BATL85_ADD_EOS_CODE)) $oPos -= 2; # kick our end of stream code out, it will be re-added if we manage to encode
                        $position--; $length++; # last byte will be processed separately
                    }

                    # PHP 7.2+ switch() is blazingly fast (jump table) on integers so use it, this will be extremely slow on PHP <7.2 but who cares
                    switch ($ivalA) {
                        case 0x00: $output[$oPos] = "\x7E"; $output[$oPos + 1] = $seq4 ? "\x30" : "\x57"; $oPos += 2; goto endCodeEncode; # 84 00 for 4 bytes, 84 32 for 3 bytes
                        case 0xFF: $output[$oPos] = "\x7E"; $output[$oPos + 1] = $seq4 ? "\x31" : "\x58"; $oPos += 2; goto endCodeEncode; # 84 01 for 4 bytes, 84 33 for 3 bytes
                        case 0x20: $output[$oPos] = "\x7E"; $output[$oPos + 1] = $seq4 ? "\x32" : "\x59"; $oPos += 2; goto endCodeEncode; # 84 02 for 4 bytes, 84 34 for 3 bytes
                        case 0x09: $output[$oPos] = "\x7E"; $output[$oPos + 1] = $seq4 ? "\x33" : "\x5A"; $oPos += 2; goto endCodeEncode; # 84 03 for 4 bytes, 84 35 for 3 bytes
                        case 0x0A: $output[$oPos] = "\x7E"; $output[$oPos + 1] = $seq4 ? "\x34" : "\x61"; $oPos += 2; goto endCodeEncode; # 84 04 for 4 bytes, 84 36 for 3 bytes
                        case 0x0D: $output[$oPos] = "\x7E"; $output[$oPos + 1] = $seq4 ? "\x35" : "\x62"; $oPos += 2; goto endCodeEncode; # 84 05 for 4 bytes, 84 37 for 3 bytes
                        case 0x23: $output[$oPos] = "\x7E"; $output[$oPos + 1] = $seq4 ? "\x36" : "\x63"; $oPos += 2; goto endCodeEncode; # 84 06 for 4 bytes, 84 38 for 3 bytes
                        case 0x28: $output[$oPos] = "\x7E"; $output[$oPos + 1] = $seq4 ? "\x37" : "\x64"; $oPos += 2; goto endCodeEncode; # 84 07 for 4 bytes, 84 39 for 3 bytes
                        case 0x29: $output[$oPos] = "\x7E"; $output[$oPos + 1] = $seq4 ? "\x38" : "\x65"; $oPos += 2; goto endCodeEncode; # 84 08 for 4 bytes, 84 40 for 3 bytes
                        case 0x2A: $output[$oPos] = "\x7E"; $output[$oPos + 1] = $seq4 ? "\x39" : "\x66"; $oPos += 2; goto endCodeEncode; # 84 09 for 4 bytes, 84 41 for 3 bytes
                        case 0x2B: $output[$oPos] = "\x7E"; $output[$oPos + 1] = $seq4 ? "\x41" : "\x67"; $oPos += 2; goto endCodeEncode; # 84 10 for 4 bytes, 84 42 for 3 bytes
                        case 0x2D: $output[$oPos] = "\x7E"; $output[$oPos + 1] = $seq4 ? "\x42" : "\x68"; $oPos += 2; goto endCodeEncode; # 84 11 for 4 bytes, 84 43 for 3 bytes
                        case 0x2E: $output[$oPos] = "\x7E"; $output[$oPos + 1] = $seq4 ? "\x43" : "\x69"; $oPos += 2; goto endCodeEncode; # 84 12 for 4 bytes, 84 44 for 3 bytes
                        case 0x30: $output[$oPos] = "\x7E"; $output[$oPos + 1] = $seq4 ? "\x44" : "\x6A"; $oPos += 2; goto endCodeEncode; # 84 13 for 4 bytes, 84 45 for 3 bytes
                        case 0x3B: $output[$oPos] = "\x7E"; $output[$oPos + 1] = $seq4 ? "\x45" : "\x6B"; $oPos += 2; goto endCodeEncode; # 84 14 for 4 bytes, 84 46 for 3 bytes
                        case 0x3D: $output[$oPos] = "\x7E"; $output[$oPos + 1] = $seq4 ? "\x46" : "\x6C"; $oPos += 2; goto endCodeEncode; # 84 15 for 4 bytes, 84 47 for 3 bytes
                        case 0x5B: $output[$oPos] = "\x7E"; $output[$oPos + 1] = $seq4 ? "\x47" : "\x6D"; $oPos += 2; goto endCodeEncode; # 84 16 for 4 bytes, 84 48 for 3 bytes
                        case 0x5D: $output[$oPos] = "\x7E"; $output[$oPos + 1] = $seq4 ? "\x48" : "\x6E"; $oPos += 2; goto endCodeEncode; # 84 17 for 4 bytes, 84 49 for 3 bytes
                        case 0x5F: $output[$oPos] = "\x7E"; $output[$oPos + 1] = $seq4 ? "\x49" : "\x6F"; $oPos += 2; goto endCodeEncode; # 84 18 for 4 bytes, 84 50 for 3 bytes
                        case 0x7B: $output[$oPos] = "\x7E"; $output[$oPos + 1] = $seq4 ? "\x4A" : "\x70"; $oPos += 2; goto endCodeEncode; # 84 19 for 4 bytes, 84 51 for 3 bytes
                        case 0x7D: $output[$oPos] = "\x7E"; $output[$oPos + 1] = $seq4 ? "\x4B" : "\x71"; $oPos += 2; goto endCodeEncode; # 84 20 for 4 bytes, 84 52 for 3 bytes
                    }

                    # we could not encode any sequence, and we have few things to roll back here if we attempted to encode 3 byte sequence instead of 4 bytes
                    if (!$seq4 && ($nCodes == 5)) {
                        $position++; $length--; # revert position and length shift, last byte will be processed as normal
                        if (!$length && ($flags & self::BATL85_ADD_EOS_CODE)) $oPos += 2; # welcome our removed end of stream code back
                    }
                } else {
                    switch ($ival) {
                        case 0x0D0A0D0A: $output[$oPos] = "\x7E"; $output[$oPos + 1] = "\x24"; $oPos += 2; goto endCodeEncode; # #0A #0D #0A #0D, 84 64
                        case 0x0A0D0A0D: $output[$oPos] = "\x7E"; $output[$oPos + 1] = "\x25"; $oPos += 2; goto endCodeEncode; # #0D #0A #0D #0A, 84 65
                    }
                }
            }

            # BATL85 final code encoder
            for ($i = 0; $i < $nCodes; $i++) {
                $code = $ival % ($i ? 85 : 84); $ival = intdiv($ival, $i ? 85 : 84); # first code is a bit special, it is div 84, not div 85

                # encode real code character from source code value, we will be using cool fast switch() there as well, PHP <7.2 will be ineffective as hell
                switch ($code) {
                    # codes 00-09, '0' (#30) - '9' (#39), 48 + code
                    case 0: case 1: case 2: case 3: case 4: case 5: case 6: case 7: case 8: case 9:
                    $code += 48; break;

                    # codes 10-35, 'A' (#41) - 'Z' (#5A), 65 - 10 + code
                    case 10: case 11: case 12: case 13: case 14: case 15: case 16: case 17: case 18: case 19:
                    case 20: case 21: case 22: case 23: case 24: case 25: case 26: case 27: case 28: case 29:
                    case 30: case 31: case 32: case 33: case 34: case 35:
                    $code += 55; break;

                    # codes 36-61, 'a' (#61) - 'z' (#7A), 97 - 36 + code
                    case 36: case 37: case 38: case 39:
                    case 40: case 41: case 42: case 43: case 44: case 45: case 46: case 47: case 48: case 49:
                    case 50: case 51: case 52: case 53: case 54: case 55: case 56: case 57: case 58: case 59:
                    case 60: case 61:
                    $code += 61; break;

                    case 62:
                    $code = 0x21; break; # code 62, '!' (#21)

                    case 63: case 64: case 65: case 66:
                    $code = $code - 28; break; # codes 63-66, '#' (#23), '$' (#24), '%' (#25), '&' (#26), 35 - 63 + code

                    case 67: case 68: case 69: case 70:
                    $code -= 27; break; # codes 67-70, '(' (#28), ')' (#29), '*' (#2A), '+' (#2B), 40 - 67 + code

                    case 71:
                    $code = 0x2D; break; # code 71, '-' (#2D)

                    case 72: case 73: case 74: case 75: case 76: case 77:
                    $code -= 13; break; # codes 72-77, ';' (#3B), '<' (#3C), '=' (#3D), '>' (#3E), '?' (#3F), '@' (#40), 59 - 72 + code

                    case 78: case 79: case 80:
                    $code += 16; break; # codes 78-80, '^' (#5E), '_' (#5F), '`' (#60), 94 - 78 + code

                    case 81: case 82: case 83: case 84:
                    $code += 42; break; # codes 81-84, '{' (#7B), '|' (#7C), '}' (#7D), '~' (#7E), 123 - 81 + code
                }

                $output[$oPos] = chr($code);
                $oPos++;
            }

endCodeEncode:
        }

endEncode:
        self::$lastError = self::errorOK;
        self::$lastErrorText = self::errorTexts[self::$lastError];
        return substr($output, 0, $oPos); # trim returned output to its real length
    }

    ########
    # Decoding algorithm:
    #   Take code C1 from the input stream, place into accumulator S
    #     If code C1 is code 84:
    #       Take subcode CX
    #       Handle subcode CX according to what encoder description for code 84 subcodes dictates (write special sequence to the output, or mark next code set as last one, etc.)
    #       If input stream does not end here nor the code 84 sequence is indicated as last stream code, repeat from the beginning (take another code C1 and follow the algorithm again)
    #   Take code C2 from the input stream, multiply by 84 and add to accumulator S
    #     If input stream ends here or it is the last stream code indicated by prior code sequence 84 80 (1 byte length), write accumulator S as 1 byte to the output, least significant byte first
    #   Take code C3 from the input stream, multiply by 84*85 and add to accumulator S
    #     If input stream ends here or it is the last stream code indicated by prior code sequence 84 81 (2 byte length), write accumulator S as 2 bytes to the output, least significant byte first
    #   Take code C4 from the input stream, multiply by 84*85*85 and add to accumulator S
    #     If input stream ends here or it is the last stream code indicated by prior code sequence 84 82 (3 byte length), write accumulator S as 3 bytes to the output, least significant byte first
    #   Take code C5 from the input stream, multiply by 84*85*85*85 and add to accumulator S
    #     If input stream ends here or it is the last stream code indicated by prior code sequence 84 83 (4 byte length), write accumulator S as 4 bytes to the output, least significant byte first
    # Rinse, repeat

    # internal Generator, takes input stream or multiple streams and processing flags, returns decoded stream or false on error
    # relinquishes control at each preallocation block of output written to facilitate using in Task code
    protected static function decodeGenerator($input, $flags = 0)
    {
        $length = strlen($input);
        $position = 0;
        $strict = (bool) ($flags & self::BATL85_STRICT_CHARSET); # may be used a lot, so prepared as local variable
        $noWS = (bool) ($flags & self::BATL85_NO_WHITESPACE); # may be used a lot, so prepared as local variable
        $streams = []; # this may not be needed unless we decode multiple streams, but yeah, initialize name early, it is cheap

        # start decoding BATL85 stream
nextStream:
        $output = '';
        $output[static::BATL85_STRING_PREALLOCATION_SIZE] = "\x00"; # we preallocate output in chunks
        $oPos = 0; # output position
        $ival = 0; # input integer
        $imul = 1; # input multiplier
        $code84 = false; # presence of code 84 to decode
        $code84Char = "\x00"; # character to use for code 84 single character sequences
        $fullStop = 0; # set to fullstop multiplier when we need to stop early
        while ($length > 0) {
            # take next character of the input
            $code = ord($input[$position]);
            $position++; $length--;

            # decode character to real BATL85 code, again we use switch() so PHP <7.2 will crawl, basically a reversal of encode process
            switch ($code) {
                case 0x30: case 0x31: case 0x32: case 0x33: case 0x34: case 0x35: case 0x36: case 0x37: case 0x38: case 0x39:
                $code -= 48; break; # codes 00-09, '0' (#30) - '9' (#39), character - 48

                case 0x41: case 0x42: case 0x43: case 0x44: case 0x45: case 0x46: case 0x47: case 0x48: case 0x49: case 0x4A:
                case 0x4B: case 0x4C: case 0x4D: case 0x4E: case 0x4F: case 0x50: case 0x51: case 0x52: case 0x53: case 0x54:
                case 0x55: case 0x56: case 0x57: case 0x58: case 0x59: case 0x5A:
                $code -= 55; break; # codes 10-35, 'A' (#41) - 'Z' (#5A), character - 65 + 10

                case 0x61: case 0x62: case 0x63: case 0x64: case 0x65: case 0x66: case 0x67: case 0x68: case 0x69: case 0x6A:
                case 0x6B: case 0x6C: case 0x6D: case 0x6E: case 0x6F: case 0x70: case 0x71: case 0x72: case 0x73: case 0x74:
                case 0x75: case 0x76: case 0x77: case 0x78: case 0x79: case 0x7A:
                $code -= 61; break; # codes 36-61, 'a' (#61) - 'z' (#7A), character - 97 + 36

                case 0x21:
                $code = 62; break; # code 62, '!' (#21)

                case 0x23: case 0x24: case 0x25: case 0x26:
                $code += 28; break; # codes 63-66, '#' (#23), '$' (#24), '%' (#25), '&' (#26), character - 35 + 63

                case 0x28: case 0x29: case 0x2A: case 0x2B:
                $code += 27; break; # codes 67-70, '(' (#28), ')' (#29), '*' (#2A), '+' (#2B), character - 40 + 67

                case 0x2D:
                $code = 71; break; # code 71, '-' (#2D)

                case 0x3B: case 0x3C: case 0x3D: case 0x3E: case 0x3F: case 0x40:
                $code += 13; break; # codes 72-77, ';' (#3B), '<' (#3C), '=' (#3D), '>' (#3E), '?' (#3F), '@' (#40), character - 59 + 72

                case 0x5E: case 0x5F: case 0x60:
                $code -= 16; break; # codes 78-80, '^' (#5E), '_' (#5F), '`' (#60), character - 94 + 78

                case 0x7B: case 0x7C: case 0x7D: case 0x7E:
                $code -= 42; break; # codes 81-84, '{' (#7B), '|' (#7C), '}' (#7D), '~' (#7E), character - 123 + 81

                case 0x00: case 0x09: case 0x0A: case 0x0D: case 0x20:
                # whitespace verification
                if ($noWS) return self::setDecodeError($position - 1, self::errorInvalidCharacter); # point to the erring character
                goto endCodeDecode;

                default:
                # invalid character
                if ($strict) return self::setDecodeError($position - 1, self::errorInvalidCharacter); # point to the erring character
                goto endCodeDecode;
            }

            if ($code84) {
                # previous code was code 84, decode special sequence
                $code84 = false;

                # PHP <7.2 sucks here again
                if ($code < 64) {
                    switch ($code & 0x1F) {
                        case 0: $code84Char = "\x00"; break; # #00
                        case 1: $code84Char = "\xFF"; break; # #FF
                        case 2: $code84Char = "\x20"; break; # #20 (space)
                        case 3: $code84Char = "\x09"; break; # #09 (tab)
                        case 4: $code84Char = "\x0A"; break; # #0A (LF)
                        case 5: $code84Char = "\x0D"; break; # #0D (CR)
                        case 6: $code84Char = "\x23"; break; # #23 ('#')
                        case 7: $code84Char = "\x28"; break; # #28 ('(')
                        case 8: $code84Char = "\x29"; break; # #29 (')')
                        case 9: $code84Char = "\x2A"; break; # #2A ('*')
                        case 10: $code84Char = "\x2B"; break; # #2B ('+')
                        case 11: $code84Char = "\x2D"; break; # #2D ('-')
                        case 12: $code84Char = "\x2E"; break; # #2E ('.')
                        case 13: $code84Char = "\x30"; break; # #30 ('0')
                        case 14: $code84Char = "\x3B"; break; # #3B (';')
                        case 15: $code84Char = "\x3D"; break; # #3D ('=')
                        case 16: $code84Char = "\x5B"; break; # #5B ('[')
                        case 17: $code84Char = "\x5D"; break; # #5D (']')
                        case 18: $code84Char = "\x5F"; break; # #5F ('_')
                        case 19: $code84Char = "\x7B"; break; # #7B ('{')
                        case 20: $code84Char = "\x7D"; break; # #7D ('}')

                        default: return self::setDecodeError($position - 2, self::errorInvalidCode); # point to start of code 84
                    }

                    if ($code & 0x20) {
                        # 3 byte sequence
                        if ($fullStop && ($fullStop != 51586500))
                            return self::setDecodeError($position - 2, self::errorInvalidEnd); # unexpected sequence length, point to start of code 84
                        if (($oPos + 3) > strlen($output)) {
                            $output[strlen($output) + ($fullStop ? 3 : static::BATL85_STRING_PREALLOCATION_SIZE)] = "\x00"; # preallocate 3 bytes on full stop requested, another chunk on normal proceedings
                            yield true; # relinquish control at preallocation
                        }
                        $output[$oPos] = $code84Char; $output[$oPos + 1] = $code84Char; $output[$oPos + 2] = $code84Char; $oPos += 3;
                    } else {
                        # 4 byte sequence
                        if ($fullStop && ($fullStop != 4384852500))
                            return self::setDecodeError($position - 2, self::errorInvalidEnd); # unexpected sequence length, point to start of code 84
                        if (($oPos + 4) > strlen($output)) {
                            $output[strlen($output) + ($fullStop ? 4 : static::BATL85_STRING_PREALLOCATION_SIZE)] = "\x00"; # preallocate 4 bytes on full stop requested, another chunk on normal proceedings
                            yield true; # relinquish control at preallocation
                        }
                        $output[$oPos] = $code84Char; $output[$oPos + 1] = $code84Char; $output[$oPos + 2] = $code84Char; $output[$oPos + 3] = $code84Char; $oPos += 4;
                    }
                    if ($fullStop) goto endStreamDecodeNoRemainder; # if we have EOS code pending, end stream decoding, we have already verified the ending sequence length
                    goto endCodeDecode;
                } else {
                    switch ($code) {
                        case 64: case 65:
                        if ($fullStop && ($fullStop != 4384852500))
                            return self::setDecodeError($position - 2, self::errorInvalidEnd); # unexpected sequence length, point to start of code 84
                        if (($oPos + 4) > strlen($output)) {
                            $output[strlen($output) + ($fullStop ? 4 : static::BATL85_STRING_PREALLOCATION_SIZE)] = "\x00"; # preallocate 4 bytes on full stop requested, another chunk on normal proceedings
                            yield true; # relinquish control at preallocation
                        }
                        break;

                        case 79: case 80: case 81: case 82: case 83:
                        if ($fullStop) return self::setDecodeError($position - 2, self::errorInvalidCode); # we cannot have two EOS codes consecutively, point to start of code 84
                        break;
                    }
                    switch ($code) {
                        # special 4-byte sequences
                        case 64:
                        # #0A #0D #0A #0D
                        $output[$oPos] = "\x0A"; $output[$oPos + 1] = "\x0D"; $output[$oPos + 2] = "\x0A"; $output[$oPos + 3] = "\x0D"; $oPos += 4;
                        if ($fullStop) goto endStreamDecodeNoRemainder; # if we have EOS code pending, end stream decoding, we have already verified the ending sequence length
                        goto endCodeDecode;

                        case 65:
                        # #0D #0A #0D #0A
                        $output[$oPos] = "\x0D"; $output[$oPos + 1] = "\x0A"; $output[$oPos + 2] = "\x0D"; $output[$oPos + 3] = "\x0A"; $oPos += 4;
                        if ($fullStop) goto endStreamDecodeNoRemainder; # if we have EOS code pending, end stream decoding, we have already verified the ending sequence length
                        goto endCodeDecode;

                        # end of stream markers
                        case 79:
                        # immediate stop without code, only used to mark an empty stream
                        if ($oPos) return self::setDecodeError($position - 2, self::errorInvalidCode); # oops, the stream is not empty, while semantically valid, we do not allow that to happen in the base implementation with 84 79 sequences
                        $fullStop = 1;
                        goto endStreamDecodeNoRemainder;

                        case 80: $fullStop = 7140; goto endCodeDecode; # stop after next 1 byte encoded (2 codes)
                        case 81: $fullStop = 606900; goto endCodeDecode; # stop after next 2 bytes encoded (3 codes)
                        case 82: $fullStop = 51586500; goto endCodeDecode; # stop after next 3 bytes encoded (4 codes)
                        case 83: $fullStop = 4384852500; goto endCodeDecode; # stop after next 4 bytes encoded (5 codes)
                    }
                }

                # invalid subcode
                return self::setDecodeError($position - 2, self::errorInvalidCode); # point to start of code 84
            } elseif ($code == 84) {
                if ($imul == 1) {
                    # start special handling for code 84 sequences
                    $code84 = true;
                    goto endCodeDecode; # take next character
                }
            }

            # input additional code to input value
            $ival += $code * $imul;
            $imul *= ($imul == 1) ? 84 : 85; # take next code multiplier in sequence (1, 1*84, 1*84*85, 1*84*85*85, 1*84*85*85*85)
            if ($imul == 4384852500) {
                # time to output decoded 4 bytes
                if (($oPos + 4) > strlen($output)) {
                    $output[strlen($output) + static::BATL85_STRING_PREALLOCATION_SIZE] = "\x00"; # preallocate next chunk if we have less than 4 bytes left in the output stream
                    yield true; # relinquish control at preallocation
                }
                $output[$oPos] = chr($ival & 0xFF); $output[$oPos + 1] = chr(($ival >> 8) & 0xFF); $output[$oPos + 2] = chr(($ival >> 16) & 0xFF); $output[$oPos + 3] = chr(($ival >> 24) & 0xFF);
                $oPos += 4;
                if ($fullStop) goto endStreamDecodeNoRemainder; # nothing more to write
                $ival = 0; $imul = 1; # reset input value and multiplier
            } elseif ($imul == $fullStop) {
                # we were requested full stop at specific multiplier, so write out 1/2/3 byte value
                goto endStreamDecode;
            }

endCodeDecode:
        }

        # if we were requested full stop, check if we have read the last code correctly
        if (($fullStop != 0) && ($fullStop != $imul)) {
            # no
            self::$lastDecodePosition = $position;
            switch ($imul) {
                case 7140: self::$lastDecodePosition -= 1; break; # 1 character read, point to start of the code
                case 606900: self::$lastDecodePosition -= 2; break; # 2 characters read, point to start of the code
                case 51586500: self::$lastDecodePosition -= 3; break; # 3 characters read, point to start of the code
                case 4384852500: self::$lastDecodePosition -= 4; break; # 4 characters read, point to start of the code
            }
            return self::setDecodeError(self::$lastDecodePosition, self::errorInvalidEnd);
        }

endStreamDecode:
        # write out last remaining bytes
        switch ($imul) {
            case 1: break; # nothing left to write

            case 7140:
            # 1 byte
            if ($ival > 0xFF) return self::setDecodeError($position - 1, self::errorInvalidEnd); # invalid value
            $output[$oPos] = chr($ival & 0xFF);
            $oPos += 1;
            break;

            case 606900:
            # 2 bytes
            if ($ival > 0xFFFF) return self::setDecodeError($position - 2, self::errorInvalidEnd); # invalid value
            if (($oPos + 2) > strlen($output)) $output[strlen($output) + 2] = "\x00"; # preallocate another 2 bytes if we have less than 2 bytes left in the output stream
            $output[$oPos] = chr($ival & 0xFF); $output[$oPos + 1] = chr(($ival >> 8) & 0xFF);
            $oPos += 2;
            break;

            case 51586500:
            # 3 bytes
            if ($ival > 0xFFFFFF) return self::setDecodeError($position - 3, self::errorInvalidEnd); # invalid value
            if (($oPos + 3) > strlen($output)) $output[strlen($output) + 3] = "\x00"; # preallocate another 3 bytes if we have less than 2 bytes left in the output stream
            $output[$oPos] = chr($ival & 0xFF); $output[$oPos + 1] = chr(($ival >> 8) & 0xFF); $output[$oPos + 2] = chr(($ival >> 16) & 0xFF);
            $oPos += 3;
            break;

            default:
            # stream ends at invalid sequence
            return self::setDecodeError($position, self::errorInvalidEnd);
        }

endStreamDecodeNoRemainder:
        # decoding multiple streams?
        if ($flags & self::BATL85_MULTIPLE_STREAMS) {
            # when decoding multiple streams, there can be a rare occasion where last stream is empty but only technically - no valid code at all
            # in this case, EOS verification and the stream itself should be skipped as this is just string remainder without any real stream
            if (($length == 0) && ($oPos == 0) && !$fullStop) goto decodingComplete; # empty as in empty empty

            # record the decoded stream otherwise
            $streams[] = substr($output, 0, $oPos); # store decoded stream
            if ($length > 0) goto nextStream; # next stream awaits
        }

        # verify if we had end of stream sequence if we were requested to check one
        if (!$fullStop && ($flags & self::BATL85_CHECK_EOS_CODE))
            return self::setDecodeError($position, self::errorNoEOS); # invalid value

decodingComplete:
        # return either decoded stream or set of streams
        self::setDecodeError($position, self::errorOK);
        return ($flags & self::BATL85_MULTIPLE_STREAMS) ? $streams : substr($output, 0, $oPos);
    }

    # this one exists just to shorten the decoder code
    protected static function setDecodeError($position, $errorCode)
    {
        self::$lastDecodePosition = $position;
        self::$lastError = $errorCode;
        self::$lastErrorText = self::errorTexts[self::$lastError];
        return false;
    }
}

class BATL85 implements \ATL\IBATL85 { use \ATL\TBATL85; }

########
# Task wrappers

# Take care static variables are shared between all classes of the type and we rely on them being set right before encode/decode Generator exits, so no static BATL85 methods of the Task may be called directly

class BATL85EncodeTask extends \ATL\SimpleTask implements \ATL\IBATL85
{
    use \ATL\TBATL85;

    public $resLastError;
    public $resLastErrorText;

    # encode routine Task wrapper
    function main($taskObject, $input, $flags = 0)
    {
        $generator = self::encodeGenerator($input, $flags);
        foreach ($generator as $result) yield $result; # encode generator relinquishes control properly
        $this->resLastError = self::$lastError;
        $this->resLastErrorText = self::$lastErrorText;
        return $generator->getReturn();
    }
}

class BATL85DecodeTask extends \ATL\SimpleTask implements \ATL\IBATL85
{
    use \ATL\TBATL85;

    public $resLastError;
    public $resLastErrorText;
    public $resLastDecodePosition;

    # decode routine Task wrapper
    function main($taskObject, $input, $flags = 0)
    {
        $generator = self::decodeGenerator($input, $flags);
        foreach ($generator as $result) yield $result; # encode generator relinquishes control properly
        $this->resLastError = self::$lastError;
        $this->resLastErrorText = self::$lastErrorText;
        $this->resLastDecodePosition = self::$lastDecodePosition;
        return $generator->getReturn();
    }
}
