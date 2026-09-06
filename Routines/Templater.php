<?php

namespace ATL;

# Templater configuration must be explicitly set via static setINIConfiguration() globally or via per-object SetConfiguration()
# Throws exceptions on fatal template errors

# When extending this class with your own template methods, avoid defining or using any __TEMPLATER_ constants or __Templater_ functions or variables, these are reserved for internal use and are subject to change
# No __Templater_ methods or variables can be used from template code, internal method call prevention is done explicitly and is not configurable for safety reasons
# When enabling unsafe functions like allowIncludes (enabled by default) or using useUnsafeExpressionEval, take utmost care the template is not user-provided, otherwise your system would be exploited in no time
# If you want to use user-provided bits as template, resort to including user bits from internal template using safeInclude() / safeIncludeFromVariable(), see configuration options for safety measures applicable

# DISCLAIMER: Inspired by Blitz templater idea by Alexey Rybak (https://github.com/alexeyrybak/blitz), but IS NOT a derivative as NONE of the original Blitz code was reused or looked up
# Just the basic idea and basic syntax were taken from authors own experience with Blitz, the code is FULLY written from scratch, template and PHP API are extended, more powerful expression lexer is included, etc.
# Can still be used as Blitz replacement by extending \Blitz class from it, but take utmost care and test thoroughly because Templater WAS NOT intended to be a drop-in Blitz replacement, and is much more flexible
# Bug-to-bug and behavioral/quirk compatibility were not considered and so are not there, take care, while most of existing Blitz templates should work, there are totally no guarantees of compatibility

class Templater
{
    protected static $iniConfiguration = [];

    const __TEMPLATER_TAG_OPEN = 0;
    const __TEMPLATER_TAG_CLOSE = 1;
    const __TEMPLATER_ALT_TAG_OPEN = 2;
    const __TEMPLATER_ALT_TAG_CLOSE = 3;
    const __TEMPLATER_COMMENT_OPEN = 4;
    const __TEMPLATER_COMMENT_CLOSE = 5;

    const __TEMPLATER_EXPRESSION_OPERATOR = 0;
    const __TEMPLATER_EXPRESSION_OPERATOR_ASSOCIATE_LEFT = 1;
    const __TEMPLATER_EXPRESSION_OPERATOR_ASSOCIATE_RIGHT = 2;
    const __TEMPLATER_EXPRESSION_OPERATOR_UNARY = 3;
    const __TEMPLATER_EXPRESSION_OPERATOR_TERNARY = 4;
    const __TEMPLATER_EXPRESSION_BRACKET = 5;
    const __TEMPLATER_EXPRESSION_COMMA = 6;
    const __TEMPLATER_EXPRESSION_QUOTE = 7;
    const __TEMPLATER_EXPRESSION_DOUBLE_QUOTE = 8;

    const __TEMPLATER_EXPRESSION_LEXEM_TABLE = [
        ','   => [0x0000, self::__TEMPLATER_EXPRESSION_COMMA, null],
        '('   => [0x0010, self::__TEMPLATER_EXPRESSION_BRACKET, null],
        ')'   => [0x0010, self::__TEMPLATER_EXPRESSION_BRACKET, null],
        "'"   => [0x0020, self::__TEMPLATER_EXPRESSION_QUOTE, null],
        '"'   => [0x0020, self::__TEMPLATER_EXPRESSION_DOUBLE_QUOTE, null],
        '**'  => [0x0100, self::__TEMPLATER_EXPRESSION_OPERATOR_ASSOCIATE_RIGHT, 'opExponent'],
        ' +'  => [0x0200, self::__TEMPLATER_EXPRESSION_OPERATOR_UNARY, 'opUnaryPlus'],
        ' -'  => [0x0200, self::__TEMPLATER_EXPRESSION_OPERATOR_UNARY, 'opUnaryMinus'],
        ' ~'  => [0x0200, self::__TEMPLATER_EXPRESSION_OPERATOR_UNARY, 'opUnaryBitwiseNOT'],
        ' !'  => [0x0300, self::__TEMPLATER_EXPRESSION_OPERATOR_UNARY, 'opUnaryBooleanNOT'],
        '*'   => [0x0400, self::__TEMPLATER_EXPRESSION_OPERATOR_ASSOCIATE_LEFT, 'opMultiply'],
        '/'   => [0x0400, self::__TEMPLATER_EXPRESSION_OPERATOR_ASSOCIATE_LEFT, 'opDivide'],
        '%'   => [0x0400, self::__TEMPLATER_EXPRESSION_OPERATOR_ASSOCIATE_LEFT, 'opModulus'],
        '+'   => [0x0500, self::__TEMPLATER_EXPRESSION_OPERATOR_ASSOCIATE_LEFT, 'opPlus'],
        '-'   => [0x0500, self::__TEMPLATER_EXPRESSION_OPERATOR_ASSOCIATE_LEFT, 'opMinus'],
        '<<'  => [0x0600, self::__TEMPLATER_EXPRESSION_OPERATOR_ASSOCIATE_LEFT, 'opLeftShift'],
        '>>'  => [0x0600, self::__TEMPLATER_EXPRESSION_OPERATOR_ASSOCIATE_LEFT, 'opRightShift'],
        '.'   => [0x0700, self::__TEMPLATER_EXPRESSION_OPERATOR_ASSOCIATE_LEFT, 'opConcatenate'],
        '<'   => [0x0800, self::__TEMPLATER_EXPRESSION_OPERATOR, 'opLower'],
        '<='  => [0x0800, self::__TEMPLATER_EXPRESSION_OPERATOR, 'opLowerOrEqual'],
        '>'   => [0x0800, self::__TEMPLATER_EXPRESSION_OPERATOR, 'opGreater'],
        '>='  => [0x0800, self::__TEMPLATER_EXPRESSION_OPERATOR, 'opGreaterOrEqual'],
        '=='  => [0x0900, self::__TEMPLATER_EXPRESSION_OPERATOR, 'opEqual'],
        '!='  => [0x0900, self::__TEMPLATER_EXPRESSION_OPERATOR, 'opNotEqual'],
        '===' => [0x0900, self::__TEMPLATER_EXPRESSION_OPERATOR, 'opStrictEqual'],
        '!==' => [0x0900, self::__TEMPLATER_EXPRESSION_OPERATOR, 'opStrictNotEqual'],
        '<>'  => [0x0900, self::__TEMPLATER_EXPRESSION_OPERATOR, 'opNotEqual'],
        '<=>' => [0x0900, self::__TEMPLATER_EXPRESSION_OPERATOR, 'opCompare'],
        '&'   => [0x0A00, self::__TEMPLATER_EXPRESSION_OPERATOR_ASSOCIATE_LEFT, 'opBitwiseAND'],
        '^'   => [0x0B00, self::__TEMPLATER_EXPRESSION_OPERATOR_ASSOCIATE_LEFT, 'opBitwiseXOR'],
        '|'   => [0x0C00, self::__TEMPLATER_EXPRESSION_OPERATOR_ASSOCIATE_LEFT, 'opBitwiseOR'],
        '&&'  => [0x0D00, self::__TEMPLATER_EXPRESSION_OPERATOR_ASSOCIATE_LEFT, 'opBooleanAND'],
        '||'  => [0x0E00, self::__TEMPLATER_EXPRESSION_OPERATOR_ASSOCIATE_LEFT, 'opBooleanOR'],
        '??'  => [0x0F00, self::__TEMPLATER_EXPRESSION_OPERATOR_ASSOCIATE_RIGHT, 'opCoalesce'],
        '?'  => [0x1000, self::__TEMPLATER_EXPRESSION_OPERATOR_TERNARY, null],
        ':'  => [0x1000, self::__TEMPLATER_EXPRESSION_OPERATOR_TERNARY, null],
        'and' => [0x1100, self::__TEMPLATER_EXPRESSION_OPERATOR_ASSOCIATE_LEFT, 'opBooleanAND'],
        'xor' => [0x1200, self::__TEMPLATER_EXPRESSION_OPERATOR_ASSOCIATE_LEFT, 'opBooleanXOR'],
        'or'  => [0x1300, self::__TEMPLATER_EXPRESSION_OPERATOR_ASSOCIATE_LEFT, 'opBooleanOR'],
    ];

    protected $__Templater_openingTags = ['{{']; # opening sequence(s) for template tags, you can specify multiple as alternatives
    protected $__Templater_closingTags = ['}}']; # closing sequence(s) for template tags, you can specify multiple as alternatives
                                                 # sequence count must equal openingTags count, each closing sequence explicitly matches corresponding opening sequence that must precede it
    protected $__Templater_commentOpeningTags = ['/*']; # opening sequence(s) for template comments, can be empty array or you can specify multiple sequences as alternatives
    protected $__Templater_commentClosingTags = ['*/']; # closing sequence(s) for template comments, can be empty array or you can specify multiple sequences as alternatives
                                                        # sequence count must equal commentOpeningTags count, each closing sequence explicitly matches corresponding opening sequence that must precede it
    protected $__Templater_defaultPath = ''; # this path is prepended to file names in files passed to constructor and load() API or to include() in templates, if the filename does not start with slash (/)
    protected $__Templater_mandatoryPath = ''; # this path is prepended to file names in files passed to constructor and load() API or to include() in templates mandatorily
                                               # this path is still prepended if the defaultPath prepend is done to path without starting slash, defaultPath prepend is done first in this case
    protected $__Templater_trimContextTags = true; # makes sure extra whitespace around context-creating tags (BEGIN, END, IF, UNLESS, ELSEIF, ELSE) is removed
    protected $__Templater_mergeScopes = true; # merges scope variables down to global for the visible scope, explicit parent scope lookups will reference merged parent scopes, improves variable lookup performance
    protected $__Templater_scopeLookupDepth = null; # limits variables lookup to that much scopes down to improve performance when mergeScopes is disabled, null for unlimited lookup depth, global scope is always looked up

    protected $__Templater_limitPHPCalls = null; # SAFETY: null for no limits or array of names of PHP functions that are allowed to be called from template, does not apply to internal methods like q(), date(), include() and others
    protected $__Templater_limitSelfCalls = null; # SAFETY: null for no limits or array of names of self class methods that are allowed to be called from template, does not apply to internal methods like q(), date(), include() and others
    protected $__Templater_limitClassCalls = null; # SAFETY: null for no limit or array of class names that can be used to call methods from template, intersects (dual-verified) with limit_class_methods if both are defined
    protected $__Templater_limitClassMethodCalls = null; # SAFETY: null for no limit, or array of arrays of names of methods that can be called from specific classes, outer array keys are class names, intersects with limit_classes if both are defined

    protected $__Templater_enableInclude = true; # enables include(), safeInclude(), includeFromVariable(), safeIncludeFromVariable() functions, disabled from inside safeInclude() / safeIncludeFromVariable() functions
    protected $__Templater_unsafeExpressionEval = false; # EXTREMELY UNSAFE, DO NOT USE: enables eval() to create fast expression closures, can squeeze last bit of performance when complex expressions are repeated in a loop

    # the following do work inside safeInclude() and safeIncludeFromVariable() code, by default no method calls are allowed at all
    protected $__Templater_safeIncludeLimitPHPCalls = []; # SAFETY: null for no limits or array of names of PHP functions that are allowed to be called from template, does not apply to internal methods like q(), date(), include() and others
    protected $__Templater_safeIncludeLimitSelfCalls = []; # SAFETY: null for no limits or array of names of self class methods that are allowed to be called from template, does not apply to internal methods like q(), date(), include() and others
    protected $__Templater_safeIncludeLimitClassCalls = null; # SAFETY: null for no limit or array of class names that can be used to call methods from template, intersects (dual-verified) with limit_class_methods if both are defined
    protected $__Templater_safeIncludeLimitClassMethodCalls = []; # SAFETY: null for no limit, or array of arrays of names of methods that can be called from specific classes, outer array keys are class names, intersects with limit_classes if both are defined

    protected $__Templater_text = '';
    protected $__Templater_globals = [];
    protected $__Templater_vars = [];

    protected $__Templater_preprocessed = null;

    public function __construct($file = null)
    {
        # load global 'INI' configuration
        $this->setConfiguration(static::$iniConfiguration);

        # if file is supplied, load it
        if ($file !== null) $this->load(file_get_contents($file));
    }

    public static function setINIConfiguration($array)
    {
        static::$iniConfiguration = $array;
    }

    public function setConfiguration($array)
    {
        foreach ($array as $k => $v) {
            switch ($k) {
                case 'variable_prefix': case 'var_prefix': $this->__Templater_var_prefix = $v; break;
                case 'open_tag': case 'tag_open': $this->__Templater_tag_open = $v; break;
                case 'close_tag': case 'tag_close': $this->__Templater_tag_close = $v; break;
                case 'alt_tag': case 'tag_open_alt': $this->__Templater_tag_open_alt = $v; break;
                case 'alt_tag_close': case 'tag_close_alt': $this->__Templater_tag_close_alt = $v; break;
                case 'open_comment': case 'comment_open': $this->__Templater_comment_open = $v; break;
                case 'close_comment': case 'comment_close': $this->__Templater_comment_close = $v; break;
                case 'alt_tags': case 'use_alt_tags': case 'enable_alternative_tags': $this->__Templater_enable_alternative_tags = (bool) $v; break;
                case 'comments': case 'enable_comments': $this->__Templater_enable_comments = (bool) $v; break;
                case 'path': case 'path_prepend': $this->__Templater_path = $v; break;
                case 'no_includes': case 'disable_include': $this->__Templater_disable_include = (bool) $v; break;
                case 'includes': case 'allow_include': $this->__Templater_disable_include = !((bool) $v); break;
                case 'trim_context_tags': case 'trim_contexts': case 'remove_spaces_around_context_tags': $this->__Templater_remove_spaces_around_context_tags = (bool) $v; break;
                case 'variable_lookup_depth': case 'var_lookup_depth': case 'scope_lookup_limit': $this->__Templater_scope_lookup_limit = (int) $v; break;
            }
        }
    }

    public function load($text)
    {
        $this->__Templater_text = $text;
        $this->__Templater_preprocessed = null;
    }

    public function clean()
    {
        $this->__Templater_vars = [];
    }

    public function cleanGlobals()
    {
        $this->__Templater_globals = [];
    }

    public function set($vars)
    {
        $this->__Templater_setNested($this->__Templater_vars, $vars);
    }

    protected function __Templater_setNested(&$target, $vars)
    {
        foreach ($vars as $k => $v) {
            if (!is_array($v)) {
                $target[$k] = $v;
            } else {
                if (!isset($target[$k])) $target[$k] = [];
                $this->__Templater_setNested($target[$k], $v);
            }
        }
    }

    public function setGlobal($vars)
    {
        $this->__Templater_setNested($this->globals, $vars);
    }

    public function setGlobals($vars)
    {
        $this->__Templater_setNested($this->globals, $vars);
    }

    public function getGlobals()
    {
        return $this->__Templater_globals;
    }

    public function block($path, $vars, $allowNotExistingBlocks = false)
    {

    }

    public function display()
    {
        if ($this->__Templater_preprocessed === null)
            $this->__Templater_preprocessed = $this->__Templater_preprocessTemplate($this->__Templater_text);
    }

    # general template parser, returns preprocessed template structure for fast structured output
    protected function __Templater_preprocessTemplate($text, $target = null)
    {
        # scan template for delimiters
        $delimiterSet = [
            $this->__Templater_tag_open => $this::__TEMPLATER_TAG_OPEN,
            $this->__Templater_tag_close => $this::__TEMPLATER_TAG_CLOSE,
        ];
        if ($this->__Templater_enable_alternative_tags) {
            $delimiterSet[$this->__Templater_tag_open_alt] = $this::__TEMPLATER_ALT_TAG_OPEN;
            $delimiterSet[$this->__Templater_tag_close_alt] = $this::__TEMPLATER_ALT_TAG_CLOSE;
        }
        if ($this->__Templater_enable_comments) {
            $delimiterSet[$this->__Templater_comment_open] = $this::__TEMPLATER_COMMENT_OPEN;
            $delimiterSet[$this->__Templater_comment_close] = $this::__TEMPLATER_COMMENT_CLOSE;
        }
        preg_match_all('#'.implode('|', array_map(function ($v) { return preg_quote($v, '#'); }, array_keys($delimiterSet))).'#S', $text, $delimPtrs, PREG_OFFSET_CAPTURE | PREG_PATTERN_ORDER);

        # split the template into sequenced parts, creating contexts
        $this->__Templater_preprocessed = [];
        if ($target === null) $target = &$this->__Templater_preprocessed;
        $varRegex = '#^'.preg_quote($this->__Templater_var_prefix, '#').'[a-zA-Z\\_\\x80-\\xff][a-zA-Z0-9\\_\\x80-\\xff]*$#S';
        $line = 0;
        $contexts = []; $lines = [];
        $lastPtr = 0;
        $delimPtrs = $delimPtrs[0];
        $delimPtrs = array_reverse($delimPtrs);
        header('Content-Type: text/plain');
        while (!empty($delimPtrs)) {
            $delimData = array_pop($delimPtrs);
            # we need delimiterSet reference to avoid non-strict string comparisons
            switch ($delimiterSet[$delimData[0]]) {
                case $this::__TEMPLATER_TAG_OPEN:
                case $this::__TEMPLATER_ALT_TAG_OPEN:
                case $this::__TEMPLATER_COMMENT_OPEN:
                # tag opened, scan for closing
                $openedBy = $delimData[0];
                $scanFor = null;
                switch ($delimiterSet[$delimData[0]]) {
                    case $this::__TEMPLATER_TAG_OPEN:
                    $scanFor = $this->__Templater_tag_close;
                    $hasContent = true; $isTag = true; $isComment = false;
                    break;

                    case $this::__TEMPLATER_ALT_TAG_OPEN:
                    $scanFor = $this->__Templater_tag_close_alt;
                    $hasContent = true; $isTag = true; $isComment = false;
                    break;

                    case $this::__TEMPLATER_COMMENT_OPEN:
                    $scanFor = $this->__Templater_comment_close;
                    $hasContent = false; $isTag = true; $isComment = false;
                    break;
                }

                # if we have text preceding the delimiter, add it first
                if ($lastPtr != $delimData[1])
                    $target[$line++] = substr($text, $lastPtr, $delimData[1] - $lastPtr);
                $lastPtr = $delimData[1] + strlen($delimData[0]); # move pointer after delimiter

                # scan
                $content = ''; $found = false;
                while (!empty($delimPtrs)) {
                    $delimData = array_pop($delimPtrs);
                    if (!strcmp($delimData[0], $scanFor)) {
                        # closing delimiter found
                        if ($hasContent && ($lastPtr != $delimData[1]))
                            $content .= substr($text, $lastPtr, $delimData[1] - $lastPtr); # we have text preceding the delimiter, add it to content
                        $found = true;
                        break;
                    } elseif ($hasContent) {
                        # add delimiter and preceding text to content
                        $content .= substr($text, $lastPtr, $delimData[1] + strlen($delimData[0]) - $lastPtr);
                    }
                    $lastPtr = $delimData[1] + strlen($delimData[0]); # move pointer after delimiter
                }

                if ($isTag) {
                    if (!$found) throw new \ATL\TemplaterException("Tag opened by `{$openedBy}` but never closed with `{$scanFor}` until end of template");
                    $content = trim($content);
                    $target[$line++] = ['type' => 'tag', 'content' => $content];

                    if (preg_match($varRegex, $content, $vData)) { # check if tag is direct variable definition
                        $target[$line - 1]['varData'] = $vData;
                    } elseif (preg_match('#^([a-zA-Z\\_\\x80-\\xff][a-zA-Z0-9\\_\\x80-\\xff]*\\:\\:)?([a-zA-Z\\_\\x80-\\xff][a-zA-Z0-9\\_\\x80-\\xff]*)\\((.+)\\)$#S', $content, $mData)) { # check if the tag is in form of method(...)
                        $target[$line - 1]['methodData'] = $mData;
                    } elseif (preg_match('#^(IF|ELSEIF|ELSE|UNLESS|BEGIN|END)(\\s+.+$|$)#iS', $content, $tData)) { # check if tag is one of the special IF, ELSEIF, ELSE, UNLESS, BEGIN, END tags
                        $target[$line - 1]['tagData'] = $tData;
                    } else {
                        # endgame, the contents may be a valid expression to output
                    }
                }

                if ($isComment) {
                    if (!$found) $lastPtr = strlen($text); # if comment was not closed by the last delimiter, ignore the rest of text as well
                }
                break;

                default:
                # closing delimiter encountered before opening, ignore the condition
                $target[$line++] = substr($text, $lastPtr, $delimData[1] + strlen($delimData[0]) - $lastPtr); # we have text preceding the delimiter, add it first
                break;
            }
            $lastPtr = $delimData[1] + strlen($delimData[0]); # move pointer after delimiter
        }
        if ($lastPtr != strlen($text))
            $target[$line++] = substr($text, $lastPtr); # we have text after the last delimiter, add it

        print_r($this->__Templater_preprocessed);
    }
}

class TemplaterException extends \Exception { }
