<?php

namespace ATL;

########
# this static class implements miscellaneous helpful routines not deserving their own classes

class Routines
{
    # this function runs $proc() until it returns true or until timeout expires, returning true or false
    # $sleepMin / $sleepMax are microseconds to sleep on getting false from $function
    # $timeout is updated by this function so you can use it for consecutive operations
    public static function tryUntilTimeout(&$timeout, $proc, $sleepMin = 100, $sleepMax = 1000)
    {
        $last = microtime(true);
        do {
            if ($proc()) return true; # function succeeded
            if ($timeout == 0) return false; # a special occasion of 'opportunistic' call
            usleep(mt_rand($sleepMin, $sleepMax));
            $now = microtime(true);
            $timeout -= max(0.0000001, $now - $last);
            $last = $now;
        } while ($timeout >= 0); # do not convert to just while() because we need to try at least once even if timeout is zero/negative
        return false;
    }

    ########
    # these functions facilitate universal conversion of dot decimal numbers to fixed point representations and back

    public static function dotDecimalToFixedPoint($value, $intSize, $fracSize, $dot = '.', $allowNegative = true, $pad = true, $throw = false)
    {
        if (($value === null) || ($value === '')) {
            if ($throw) throw new \ErrorException("Empty dot decimal floating point value encountered that cannot be converted to {$intSize}.{$fracSize} fixed point representation");
            return null;
        }
        if (!preg_match('#^(\\-)?(\\d{0,'.$intSize.'})(?:'.preg_quote($dot, '#').'(\\d{1,'.$fracSize.'}))?$#S', (string) $value, $m)) {
            if ($throw) throw new \ErrorException("Invalid dot decimal floating point value `{$value}` encountered that cannot be converted to {$intSize}.{$fracSize} fixed point representation");
            return null;
        }
        if (!$allowNegative && (($m[1] ?? '') !== '')) {
            if ($throw) throw new \ErrorException("Negative dot decimal floating point value `{$value}` encountered that cannot be converted to {$intSize}.{$fracSize} fixed point representation (negative values not allowed)");
            return null;
        }
        return ($m[1] ?? '').($pad ? str_pad($m[2] ?? '0', $intSize, '0', STR_PAD_LEFT) : ($m[2] ?? '0')).str_pad($m[2] ?? '0', $fracSize, '0', STR_PAD_RIGHT);
    }

    public static function fixedPointToDotDecimal($value, $intSize, $fracSize, $dot = '.', $allowNegative = true, $requirePadded = false, $throw = false)
    {
        if (($value === null) || ($value === '')) {
            if ($throw) throw new \ErrorException("Invalid (empty) {$intSize}.{$fracSize} fixed point value passed that cannot be converted to dot decimal representation");
            return null;
        }
        $minus = '';
        if (substr($value, 0, 1) == '-') {
            if (!$allowNegative) {
                if ($throw) throw new \ErrorException("Negative {$intSize}.{$fracSize} fixed point value `{$value}` encountered that cannot be converted to dot decimal representation (negative values not allowed)");
                return null;
            }
            $minus = '-';
            $value = substr($value, 1);
        }
        if ($requirePadded && (strlen($value) !== ($intSize + $fracSize))) {
            if ($throw) throw new \ErrorException("Invalid (too short) {$intSize}.{$fracSize} fixed point value passed that cannot be converted to dot decimal representation (non-padded values not allowed)");
            return null;
        }
        $value = str_pad((string) $value, $fracSize + 1, '0', STR_PAD_LEFT);
        $int = ltrim(substr($value, 0, -$fracSize), '0');
        $frac = rtrim(substr($value, -$fracSize), '0');
        return $minus.(($int !== '') ? $int : '0').(($frac !== '') ? $dot.$frac : '');
    }

    ########
    # multi-level index array helpers inspired by ObjectStore implementation
    # key is array of names comprising multi-level key

    # gets specific multidimension array element by linear key (returning $notFoundReturns value if not found, NULL by default)
    # take care it returns a reference
    public static function getMultiLevelIndexElement($index, $key, $notFoundReturns = null)
    {
        $current = $index;
        foreach ($key as $value) {
            if (!is_array($current) || !array_key_exists($value, $current)) return $notFoundReturns;
            $current = $current[$value];
        }
        return $current;
    }

    # places specific element into multidimensional array by linear key, can overwrite entire subtree if used for upper level
    # returns true on success, false if operation failed (i.e. attempted to dig down into non-array tree element)
    public static function putMultiLevelIndexElement(&$index, $key, $element)
    {
        $current = &$index;
        $last = array_pop($key);
        foreach ($key as $value) {
            if (!is_array($current)) return false;
            if (!array_key_exists($value, $current)) $current[$value] = [];
            $current = &$current[$value];
        }
        $current[$last] = $element;
        return true;
    }

    # removes specific element from multidimensional array by linear key, can remove entire subtree if used for upper level
    # takes care to remove empty elements on the way if anything was removed
    # returns true on success, false if operation failed (i.e. key not found or attempted to dig down into non-array tree element)
    public static function removeMultiLevelIndexElement(&$index, $key)
    {
        $current = &$index;
        $stack = [];
        foreach ($key as $value) {
            if (!is_array($current) || !array_key_exists($value, $current)) return false; # nothing to remove
            $stack[] = [&$current, $value];
            $current = &$current[$value];
        }
        foreach (array_reverse($stack) as $cv) {
            unset($cv[0][$cv[1]]);
            if (count($cv[0]) > 0) break;
        }
        return true;
    }

    ########
    # these functions provide for extremely useful string deduplication optimization possible with PHP 7 and above at the cost of some CPU time
    # it stores passed strings into array (not storing anything if string already exists), and returns array element corresponding to the string
    # in PHP7, this effectively allows to share repeating strings for copy-on-write, reducing overall memory consumed by the strings
    # if you have lots of repeated strings in the data you are processing, you will get extremely serious memory savings by using this function
    # use it on each string you process as $myString = \ATL\Routines::phpDeduplicateString($stringArray, $myString), with $stringArray prepared as empty array
    # or you can recursively deduplicate any array using \ATL\Routines::phpDeduplicateArrayStrings($stringArray, $myArray) where $myArray is passed by reference and modified

    public static function phpDeduplicateString(&$cacheArray, $string)
    {
        if (!is_string($string)) return $string; # not a string, keep as is
        if (!isset($cacheArray[$string])) $cacheArray[$string] = $string;
        return $cacheArray[$string];
    }

    public static function phpDeduplicateArrayStrings(&$cacheArray, &$array)
    {
        foreach ($array as $k => $v) {
            if (is_array($v)) {
                self::phpDeduplicateArrayStrings($cacheArray, $array[$k]);
            } else {
                $array[$k] = self::phpDeduplicateString($cacheArray, $v);
            }
        }
    }

    ########
    # this function checks for presence of JIT in PHP (presence of JIT effectively changes some optimization strategies)

    protected static $jitEnabled = false;

    public static function isJITEnabled()
    {
        return static::$jitEnabled ?? (static::$jitEnabled = (is_array($status = opcache_get_status()) && ($status['jit']['enabled'] ?? false)));
    }

    ########
    # this function converts specific callable to closure
    # for object array callables mostly effective in PHP 7.x and 8.x without JIT (~25% gain), with JIT is slightly effective (~5% gain)
    # for static class array callables effective in PHP 7.x and 8.x without JIT (~25% gain), with JIT is comparable (~1% loss)
    # for static class string calls, extremely effective both with JIT (~40% gain) and without JIT (~70% gain)
    # for object invocations, effective without JIT (~10% gain), but ineffective with JIT (~25% loss)
    # for simple global function string calls, still effective without JIT (~20% gain), but BREAKS JIT, resulting in huge performace loss (~500% loss)
    # so if you set skipIfIneffective to true, we do not optimize static class array callables, object invocations and global function string calls with JIT, returning the original callable
    # take care that in case skipIfIneffective is set to true, return value may not necessarily be of Closure type, so using this may require extra checking caller side if it depends on callable type
    public static function callableToClosure($callback, $skipIfIneffective = false)
    {
        if ($callback instanceof \Closure) return $callback; # we do not need to convert Closure to Closure

        # PHP 7.1+ has Closure::fromCallable() method that allows to directly convert callable to closure without resorting to the lengthy scroll below
        if (PHP_VERSION_ID >= 70100) {
            if ($skipIfIneffective && (PHP_VERSION_ID >= 80000) && static::isJITEnabled()) {
                # oh man, we have JIT, skip some conditions
                if (is_array($callback) && !is_object(reset($callback))) return $callback; # slightly ineffective on static class array callables
                if (is_object($callback)) return $callback; # ineffective on object invocations
                if (is_string($callback) && (strrpos($callback, '::') === false)) return $callback; # breaks JIT on function invocations
            }
            return \Closure::fromCallable($callback);
        }

        # simulate conversion of callable to closure for PHP <7.1, this has a decent conversion overhead but this one is intended to convert callables we plan to use a lot afterwards, so
        if (is_array($callback)) {
            # normal callable array expected
            if (count($callback) == 2) {
                # getClosure() call here depends on if we have object or static class string supplied as first element
                $reflection = new \ReflectionMethod($object = reset($callback), next($callback));
                return is_object($object) ? $reflection->getClosure($object) : $reflection->getClosure();
            } else {
                throw new \ErrorException("Array callback must have exactly two elements");
            }
        } elseif (is_object($callback)) {
            # object with __invoke method expected
            if (method_exists($callback, '__invoke')) {
                $reflection = new \ReflectionMethod($callback, '__invoke');
                return $reflection->getClosure($callback);
            } else {
                throw new \ErrorException("Object of type ".get_class($callback)." is not callable");
            }
        } elseif (is_string($callback)) {
            # string have dual meaning: it can be either callable function name or can be in form of staticClass::method, we have to distinguish
            if (($sepPos = strrpos($callback, '::')) === false) {
                # simple function
                $reflection = new \ReflectionFunction($callback);
                return $reflection->getClosure();
            } else {
                # static class reference
                $reflection = new \ReflectionMethod(substr($callback, 0, $sepPos), substr($callback, $sepPos + 2));
                return $reflection->getClosure();
            }
        } else {
            throw new \ErrorException("Value of type ".gettype($callback)." is not callable");
        }
    }

    ########
    # object ID functions
    # some objects in ATL like BindableObject which use object IDs can be cacheable (serialized and stored)
    # if you plan on caching cacheable ATL objects, call \ATL\Routines\useCacheableObjectIDs() at the very application start
    # it will then generate unique cacheable object IDs that may be reloaded without clashing with new spl_object_id() IDs on any run and unique between servers, given they have different hostnames

    protected static $cacheableObjectsPrefix = null;

    # enables cacheable object IDs for getUniqueObjectID()
    public static function useCacheableObjectIDs()
    {
        if (static::$cacheableObjectsPrefix !== null) return static::$cacheableObjectPrefix; # make sure calling twice does not change the ID prefix
        return static::$cacheableObjectsPrefix = ($_SERVER['HOSTNAME'] ?? php_uname('n')).'-'.($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
    }

    # returns object ID unique to the current script run, basically spl_object_id() (can be spl_object_hash() value for PHP <7.3), must not be used for cacheable objects
    public static function getUniqueObjectID($object)
    {
        return spl_object_id($object);
    }

    # if useCacheableObjectIDs() was not called, just uses spl_object_id(), otherwise returns unique cacheable object ID that is unique between servers and runs
    public static function getCacheableObjectID($object)
    {
        return (static::$cacheableObjectsPrefix === null) ? spl_object_id($object) : static::$cacheableObjectsPrefix.'-'.spl_object_id($object);
    }

    ########
    # dynamically includes PHP code from internal pseudo stream wrapper
    public static function includeCode($code)
    {
        stream_wrapper_register('tempatlbase64data', '\\ATL\\StreamBase64Data');
        include('tempatlbase64data://'.base64_encode($code));
        stream_wrapper_unregister('tempatlbase64data');
    }

    ########
    # small and clean HOTP and TOTP implementation

    public static function HOTP($key, $counter, $length = 6, $algo = 'sha1')
    {
        # pack counter big-endian and get HMAC hash
        $hCounter = pack('J*', $counter);
        $hash = hash_hmac($algo, $hCounter, $key, true);

        # obtain byte offset from 4 last bits of the hash and retrieve 31-bit value from the byte offset
        $offset = ord($hash[strlen($hash) - 1]) & 0xF;
        $hVal = ((ord($hash[$offset]) & 0x7F) << 24) + (ord($hash[$offset + 1]) << 16) + (ord($hash[$offset + 2]) << 8) + ord($hash[$offset + 3]);

        # voila
        return substr(str_pad($hVal, $length, '0', STR_PAD_LEFT), -$length);
    }

    public static function TOTP($key, $time = null, $length = 6, $algo = 'sha1', $step = 30, $start = 0)
    {
        if ($time === null) $time = time();
        return static::HOTP($key, intdiv($time - $start, $step), $length, $algo);
    }
}
