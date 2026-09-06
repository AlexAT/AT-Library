<?php

namespace ATL;

interface IPermissionsTree extends \ArrayAccess
{
    public function check($path, $cache = true);
    public function insert($path, $value);
    public function checkMulti($paths, $cache = true);
}

trait TPermissionsTree
{
    public $paths = ['/' => null];

    ########
    # Public API

    public function check($path, $cache = true)
    {
        $path = $this->canonizePath($path);

        # have direct path value remembered?
        if (isset($this->paths[$path])) return $this->paths[$path];

        # nope, go down
        $backtrack = [];
        if ($cache) $backtrack[] = $path; # we may want not to cache values down the path if we process lots of entries or if we want a dynamic tree

        $value = null;
        do {
            if (($path = $this->decPath($path)) != '/') {
                if (isset($this->paths[$path.'/*'])) {
                    $value = $this->paths[$path.'/*'];
                    break;
                }
            } elseif (isset($this->paths['/*'])) {
                $value = $this->paths['/*'];
                break;
            }
            $backtrack[] = $path;
        } while ($path != '/');

        foreach ($backtrack as $btpath) {
            if ($btpath != '/') {
                if (!isset($this->paths[$btpath])) $this->paths[$btpath] = $value;
                if (!isset($this->paths[$btpath.'/*'])) $this->paths[$btpath.'/*'] = $value;
            } else {
                if (!isset($this->paths['/'])) $this->paths['/'] = $value;
                if (!isset($this->paths['/*'])) $this->paths['/*'] = $value;
            }
        }

        return $value;
    }

    public function insert($path, $value)
    {
        $this->paths[$path = $this->canonizePath($path)] = $value;
    }

    ########
    # This function checks multiple paths and returns false if something along these paths is denied, returns null if completely nothing is allowed, returns true otherwise
    public function checkMulti($paths, $cache = true)
    {
        $result = null;
        foreach ($paths as $path) {
            $check = $this->check($path, $cache);
            if ($check === false) return false; # deny encountered
            if ($check === true) $result = true; # ok, something allowed
        }
        return $result;
    }

    ########
    # Internal functions

    protected function canonizePath($path)
    {
        return '/'.trim(preg_replace('#/+#', '/', $path), '/');
    }

    protected function decPath($path)
    {
        return $this->canonizePath(preg_replace('#^(\\/(?:[^\\/]*\\/)*)[^\\/]*$#', '$1', $path));
    }

    ########
    # ArrayAccess API
    # Partially implemented for get() and insert(), isset/unset have no meaning

    public function offsetGet($k) { return $this->check($k, true); }
    public function offsetSet($k, $v) { $this->insert($k, $v); }
    public function offsetExists($k) { throw new \ErrorException('isset() API is not implemented'); }
    public function offsetUnset($k) { throw new \ErrorException('unset() API is not implemented'); }
}

class PermissionsTree implements \ATL\IPermissionsTree { use \ATL\TPermissionsTree; }
