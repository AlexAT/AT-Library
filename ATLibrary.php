<?php

########
# include this file to start using AT/Library

########
# please keep in mind AT/Library is not a framework, but a subset of useful code snippets that makes your daily life easy
# do not expect exact naming consistency from it, API extensions may be named like myMethod2, myMethod3 or Class2, Class3, etc.
# existing libraries API is guaranteed to be extended with backwards compatible defaults for new parameters though

/*
AT/Library (ATL)

Copyright 2022-2026 Alex/AT (alex@alex-at.net)

Redistribution and use in source and binary forms, with or without modification, are permitted provided that the following conditions are met:
1. Redistributions of source code must retain the above copyright notice, this list of conditions and the following disclaimer.
2. Redistributions in binary form must reproduce the above copyright notice, this list of conditions and the following disclaimer in the documentation and/or other materials provided with the distribution.
3. Neither the name of the copyright holder nor the names of its contributors may be used to endorse or promote products derived from this software without specific prior written permission.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS “AS IS” AND ANY EXPRESS OR IMPLIED WARRANTIES,
INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR
SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY,
WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE
USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
*/

if (defined('HAVE_AT_LIBRARY')) return;
define('HAVE_AT_LIBRARY', 1);
if (!defined('AT_LIBRARY_PATH')) define('AT_LIBRARY_PATH', __DIR__);
if (PHP_INT_SIZE < 8) throw new \ErrorException("AT/Library can only be used on 64-bit platforms and PHP versions and above");

# a little tiny compatibility layer
if (!function_exists('spl_object_id')) {
    function spl_object_id($object) { return spl_object_hash($object); }
}
if (!function_exists('opcache_get_status')) {
    function opcache_get_status(...$args) { return false; }
}
if (!function_exists('hrtime')) {
    function hrtime($as_number = false)
    {
        # slow but safe
        if ($as_number) {
            return floor(microtime(true) * 1000000000);
        } else {
            $time = microtime(true);
            $iTime = floor($time);
            return [$iTime, floor(($time - $iTime) * 1000000000)];
        }
    }
}

# load and initialize class loader
require_once(AT_LIBRARY_PATH.'/Autoloader.php');
if (defined('AT_LIBRARY_GLOBAL_CLASS_PATH_CACHE')) \ATL\Autoloader::setGlobalCache(AT_LIBRARY_GLOBAL_CLASS_PATH_CACHE);
\ATL\Autoloader::addPath('ATL\\', AT_LIBRARY_PATH, defined('AT_LIBRARY_CLASS_PATH_CACHE') ? AT_LIBRARY_CLASS_PATH_CACHE : null, true, false);
\ATL\Autoloader::install();
