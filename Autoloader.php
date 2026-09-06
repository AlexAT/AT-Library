<?php

namespace ATL;

# this static autoloader class is a clever autoloader that you can use in your applications
# you can either attach your classes to existing \ATL\Autoloader class or extend and install secondary autoloaders
# only [a-z][A-Z][0-9] and '_' characters are allowed in class names (namespace separator '\' is also allowed)
# you cannot add multiple paths with the same class naming prefix, you will overwrite one path by other by re-adding same prefix, take care
# in general, do not do anything obscure using the autoloader, it will backfire you soon enough

# there is optional special handling for class names prefixed with I (interface) or T (trait), first the class file without I/T prefix is attempted to be loaded
# there is optional slower underscore ('_') aliasing mode which allows to load classes named like 'One_Two' from either /One/Two.php or /One_Two.php (for legacy compatibility where having 'One' namespace is unfeasible)
# using I/T interface/trait prefix handling and/or underscore aliasing is not recommended without cache (see below) because it incurs multiple recursive search attempts traversing the filesystem

# your class directory needs to be a direct suffix of class naming prefix you specify, like 'ATL\\' for ATL classes, files would be searched after removing the prefix
# i.e. PHPMailer uses 'PHPMailer\PHPMailer' namespace, so you need to pass 'PHPMailer\\PHPMailer\\' prefix for its files directory, or 'PHPMailer\\' prefix if you supply directory where 'PHPMailer' directory resides
# any path add and/or class load errors result in AutoloaderException being thrown

# you can optionally use file-based or APCu based caching which relies on class directories modification times
# it is recommended to use caching if you have large class directory structures, for caching you either specify the cache file name for each path added, or 'apcu:<key>' as file name for APCu caching
# you can also provide your own cache callback instead of file name or 'apcu:<key>', for the callback implementation see fileCacheAccess() and apcuCacheAccess() functions, it should be trivial to implement your own one
# each path should use its own cache storage (file / APCu key), in your callback, you will not have proper cache parameter (it will be your own callback), but you can distinguish paths using path element of the passed path array
# you can also optionally provide the global path cache for all the paths inside autoloader, except for ones implicitly specifying their own cache handler, using setGlobalCache()
# both caches will remember classes already accessed and will update cache as classes load to do less filesystem searches, cache is fully invalidated once any of the cached class directories modification time changes
# take note cache is invalidated only at addPath() calls, if something changes during script run, stale cache will be used unless this is previously uncached class where cache information will be refreshed
# file based cache locks cache file before doing anything so low power thundering herd is possible, but given class directories rarely update it should not be a problem
# APCu based cache does not lock cache at all, so thundering herd may mess with the cache up for a while, overwriting entries added by other processes, but it will not become inconsistent
# APCu TTL is always set to infinite because cache is refreshed each time any of the directories change, Autoloader version is stored inside the cache, so cache will be refreshed if it differs
# both caching types refresh and modify cache file information on any uncached class load, but can use stale cache for doing actual loading inside the single run
# this is a tradeoff between speed and robustness, if you deem the possibility to use stale class name to path cache once to be of any issue, just do not use cache at all
# using your own cache handlers you can optionally provide static class path caches without any invalidation (keeping 'directories' empty) and other stuff you may have in mind

class AutoloaderException extends \Exception { } # specific exception class

interface IAutoloader
{
    const VERSION = 1000; # this may change if we change cache mechanics of autoloader

    public static function addPath($prefix, $path, $cache = null, $interfacesTraitsPrefixHandling = false, $underscoresHandling = false);
    public static function addClass($classes, $file);
    public static function setGlobalCache($cache = null);
    public static function install($prepend = false);
    public static function autoload($class);
}

trait TAutoloader
{
    protected static $paths = [];
    protected static $classMap = [];
    protected static $cacheData = ['version' => self::VERSION, 'classes' => [], 'directories' => [], 'paths' => []];
    protected static $defaultCacheData = ['version' => self::VERSION, 'classes' => [], 'directories' => [], 'paths' => []];
    protected static $globalCache;

    public static function addPath($prefix, $path, $cache = null, $interfacesTraitsPrefixHandling = false, $underscoresHandling = false)
    {
        $path = [
            'prefix' => $prefix,
            'path' => $path,
            'cache' => $cache,
            'cacheParam' => $cache,
            'interfacesTraitsPrefixHandling' => $interfacesTraitsPrefixHandling,
            'underscoresHandling' => $underscoresHandling
        ];

        if (!@is_dir($path['path'])) throw new \ErrorException("Class directory `{$path['path']}` to be added for class prefix `{$prefix}` does not exist");

        # load class map file and add its contents to class map list
        if (@is_file($path['path'].'/_ClassMap.php'))
            if (is_array($map = require_once($path['path'].'/_ClassMap.php')))
                static::addClass(array_map(function ($file) use ($path) { return $path['path'].'/'.$file; }, $map));

        if ($cache === null) $cache = static::$globalCache;
        if ($cache === false) $cache = null; # this is deliberately not documented, but you can use false as cache designation to avoid global cache as well (BUT WHY?)
        if (($cache !== null) && !is_callable($cache)) {
            if (!preg_match('#^apcu\\:(.+)$#i', $cache, $m)) {
                $path['cache'] = function ($path, $callback) { return static::fileCacheAccess($path, $callback); };
                $path['cacheParam'] = $cache;
            } else {
                $path['cache'] = function ($path, $callback) { return static::apcuCacheAccess($path, $callback); };
                $path['cacheParam'] = $m[1];
            }
        }

        if ($path['cache'] !== null) {
            static::$cacheData = ($path['cache'])($path, function ($cacheData) use ($path) {
                $doUpdate = false;
                if (!is_array($cacheData) || !isset($cacheData['version']) || ($cacheData['version'] != static::VERSION)) return static::$defaultCacheData;
                if (!isset($cacheData['paths'][$path['prefix']])) {
                    # new class path added, so invalidate load list and directories list but add the path
                    $cacheData['paths'][$path['prefix']] = $path['path'];
                    $cacheData['classes'] = [];
                    $cacheData['directories'] = [];
                    $doUpdate = true;
                }
                if (strcmp($cacheData['paths'][$path['prefix']], $path['path'])) return static::$defaultCacheData; # class path changed, invalidate the cache
                foreach ($cacheData['directories'] as $directory => $mtime)
                    if (!strncmp($path['path'], $directory, strlen($path['path'])))
                        if (!@is_dir($directory) || (@filemtime($directory.'/.') !== $mtime)) return static::$defaultCacheData;
                return $doUpdate ? $cacheData : null;
            });
        }

        static::$paths[strlen($prefix)][$prefix] = $path;
        krsort(static::$paths, SORT_NUMERIC); # this ensures longest prefixes match first
    }

    # this one allows to directly map some classes to specific files, inhibiting the search and overriding any possible search results
    # particularly useful to alias some specific supplementary classes to single file which contains them all, reducing file count to load
    # passing array allows to alias multiple classes at once, if file is null, array is considered to be key => value where key is class, and value is file, if file is not null, array values (classes) are all aliased to file
    # consider undocumented / not recommended: calling twice overrides the alias, passing null as file removes the alias and allows the normal class name search to work again
    public static function addClass($classes, $file = null)
    {
        if (!is_array($classes)) {
            # single class aliasing
            if ($file !== null) { static::$classMap[$classes] = $file; } else { unset(static::$classMap[$classes]); }
        } elseif ($file !== null) {
            # array of classes, file is specified separately
            if ($file !== null) {
                foreach ($classes as $class) static::$classMap[$class] = $file;
            } else {
                foreach ($classes as $class) unset(static::$classMap[$class]);
            }
        } else {
            # array of class => file
            foreach ($classes as $class => $file)
                if ($file !== null) { static::$classMap[$class] = $file; } else { unset(static::$classMap[$class]); }
        }
    }

    public static function setGlobalCache($cache = null)
    {
        static::$globalCache = $cache;
    }

    public static function install($prepend = false)
    {
        spl_autoload_register([static::class, 'autoload'], true, $prepend);
    }

    public static function autoload($class)
    {
        if (!preg_match('#^[0-9a-z\\_]+(?:\\\\[0-9a-z\\_]+)*$#iS', $class)) throw new \ErrorException("Tried to autoload invalid class `{$class}`");

        if (isset(static::$classMap[$class])) {
            # directly load from aliased file if it exists
            if (@is_file(static::$classMap[$class])) {
                require_once(static::$classMap[$class]);
                return true;
            }
        }

        $target = null;
        foreach (static::$paths as $prefixLength => $prefixes) {
            $path = $prefixes[$prefix = substr($class, 0, $prefixLength)] ?? null;
            if (is_array($path)) {
                if ($path['cache'] !== null) {
                    # attempt target search using the cache
                    if (array_key_exists($class, static::$cacheData['classes'])) {
                        # found in local cache copy
                        $target = static::$cacheData['classes'][$class] ?? null;
                    }

                    # in case of negative cache we still retry, spending a little time is not bad there: it is error anyways if not found and it is better than failing until cache expires
                    if ($target === null) {
                        # not cached, need to find and add
                        static::$cacheData = ($path['cache'])($path, function ($cacheData) use ($class, $prefix, $path, &$target) {
                            if (!is_array($cacheData) || !isset($cacheData['version']) || ($cacheData['version'] != static::VERSION))
                                $cacheData = static::$defaultCacheData;
                            if (array_key_exists($class, $cacheData['classes']) && ($cacheData['classes'][$class] !== null)) {
                                # found in the newer cache
                                $target = $cacheData['classes'][$class];
                                return null;
                            }

                            # not found in the newer cache, attempt to find file
                            $traversal = [];
                            $classPath = preg_split($path['underscoresHandling'] ? '#[_\\\\]#' : '#\\\\#', substr($class, strlen($prefix)));
                            if (!@is_dir($path['path'])) throw new \ErrorException("Class directory `{$path['path']}` for class prefix `{$prefix}` does not exist");
                            $target = static::searchFile($classPath, $path['path'], $path['interfacesTraitsPrefixHandling'], $path['underscoresHandling'], $traversal);

                            # file found, store information into cache and return (negative accesses are also stored)
                            $cacheData['classes'][$class] = $target;
                            if ($target !== null) {
                                # add information about traversed directories as well, but do not overwrite (invalidation will not work properly otherwise)
                                foreach ($traversal as $directory)
                                    if (!isset($cacheData['directories'][$directory]))
                                        $cacheData['directories'][$directory] = @filemtime($directory.'/.');
                            }
                            return $cacheData;
                        });
                    }
                } else {
                    # no cache, just do the normal search
                    $classPath = preg_split($path['underscoresHandling'] ? '#[_\\\\]#' : '#\\\\#', substr($class, strlen($prefix)));
                    $traversal = [];
                    if (!@is_dir($path['path'])) throw new \ErrorException("Class directory `{$path['path']}` for class prefix `{$prefix}` does not exist");
                    $target = static::searchFile($classPath, $path['path'], $path['interfacesTraitsPrefixHandling'], $path['underscoresHandling'], $traversal);
                }

                if ($target !== null) {
                    # load class file and return
                    require_once($target);
                    return true;
                }
            }
        }
        return false; # not found, allow other autoloaders to run
    }

    protected static function searchFile($classPath, $path, $interfacesTraitsPrefixHandling, $underscoresHandling, &$traversal)
    {
        $name = array_shift($classPath);
        if (@is_dir($path)) $traversal[$path] = $path;
        if (count($classPath) > 0) {
            if (($file = static::searchFile($classPath, $path.'/'.$name, $interfacesTraitsPrefixHandling, $underscoresHandling, $traversal)) !== null) return $file;
            if ($underscoresHandling && (($file = static::searchFile($classPath, $path.'_'.$name)) !== null)) return $file;
        } else {
            if (@is_file($file = $path.'/'.$name.'.php')) return $file;
            if ($underscoresHandling && @is_file($file = $path.'_'.$name.'.php')) return $file;
            if ($interfacesTraitsPrefixHandling) {
                # interfaces and traits get a bit special handling: they can be contained in base files
                if ((substr($name, 0, 1) == 'I') || (substr($name, 0, 1) == 'T')) {
                    if (@is_file($file = $path.'/'.substr($name, 1).'.php')) return $file;
                    if (@is_file($file = $path.'_'.substr($name, 1).'.php')) return $file;
                }
            }
        }
        return null;
    }

    protected static function fileCacheAccess($pathData, $callback)
    {
        if (!($fh = fopen($pathData['cacheParam'], 'ab+'))) throw new \ATL\AutoloaderException("Cannot open cache file `{$pathData['cacheParam']}`");
        if (!flock($fh, LOCK_EX)) throw new \ATL\AutoloaderException("Cannot open cache file `{$pathData['cacheParam']}`");
        $oldCacheData = @unserialize(stream_get_contents($fh, -1, 0));
        if (($cacheData = $callback($oldCacheData)) !== null) {
            fseek($fh, 0, SEEK_SET);
            ftruncate($fh, 0);
            fwrite($fh, serialize($cacheData));
        }
        flock($fh, LOCK_UN);
        fclose($fh);
        return $cacheData ?? $oldCacheData;
    }

    protected static function apcuCacheAccess($pathData, $callback)
    {
        $oldCacheData = @unserialize(apcu_fetch($pathData['cacheParam']));
        if (($cacheData = $callback($oldCacheData)) !== null) apcu_store($pathData['cacheParam'], serialize($cacheData));
        return $cacheData ?? $oldCacheData;
    }
}

class AutoLoader implements \ATL\IAutoloader { use \ATL\TAutoloader; }
