<?php

namespace ATL;

########
# Long floating point number math routines providing arbitrary decimal point precision using PHP GMP, will be adjusted to use GMP floats once PHP GMP supports it
# Processing numbers with different maximum precision/rounding is supported, rounding only happens when the maximum precision allowed is exceeded

class LongFloat
{
    # rounding mode details are demonstrated for 1-digit precision
    const ROUND_MATH = 0; # default rounding mode, 0.00 to 0.04 => 0.0, 0.05 to 0.09 => 0.1, -0.01 to -0.04 => 0.0, -0.05 to -0.09 => -0.1
    const ROUND_TRUNC = 1; # truncation mode, 0.00 to 0.09 => 0.0, -0.01 to -0.09 => 0.0
    const ROUND_FLOOR = 2; # floor mode, 0.00 to 0.09 => 0.0, -0.01 to -0.09 => 0.1
    const ROUND_CEIL = 3; # ceil mode, 0.00 => 0.0, 0.01 to 0.09 => 0.1, -0.01 to -0.09 => 0.0
    const ROUND_SPREAD = 4; # spread mode, 0.00 => 0.0, 0.01 to 0.09 => 0.1, -0.01 to -0.09 => 0.01

    # never ever touch these in runtime, they are public to speed up operations (methods take too much time to be called)
    public $gmp;
    public $decimals;
    public $maxDecimals;
    public $rounding;

    protected static $zero; # GMP zero value
    protected static $one; # GMP one value
    protected static $minusOne; # GMP minus one value
    protected static $multipliers = []; # this one holds GMP floating point multipliers for all precisions noticed
    protected static $halfMultipliers = []; # corresponding 1/2s of multipliers for rounding comparison purposes

    public function __construct($number = 0, $maxDecimals = 20, $rounding = self::ROUND_MATH)
    {
        if ($this::$zero === null) {
            # initialize constants
            $this::$zero = gmp_init(0);
            $this::$one = gmp_init(1);
            $this::$minusOne = gmp_init(-1);
        }

        $this->maxDecimals = max(0, $maxDecimals);
        $this->rounding = $rounding;
        $this->gmp = clone $this::$zero;
        $this->decimals = 0;
        $this->set($number);
    }

    # x = arg
    public function set($arg = 0)
    {
        if (is_scalar($arg)) {
            if (is_string($arg)) {
                # take care float conversions are highly inaccurate, it is as PHP string typecast does it
                if (preg_match('#([\\+\\-]?)(\\d*)(?:\\.(\\d+))?(?:E([\\+\\-]?\\d+))?$#iS', $arg, $m)) {
                    if ((($m[3] ?? '') === '') && (($m[4] ?? '') === '') && (strlen($m[2]) <= 18)) return $this->set((int) $arg); # avoid all the string processing for integers below int64

                    if (($m[4] ?? '') !== '') {
                        # E-notation encountered, convert it
                        if ($m[1] === '+') $m[1] = ''; # remove plus signs
                        $string = $m[2].$m[3];
                        if (($dotpos = strlen($m[2]) + (int) $m[4]) > 0) {
                            # left shift, right pad
                            $string = str_pad($string, $dotpos, '0', STR_PAD_RIGHT);
                            $m[2] = substr($string, 0, $dotpos);
                            $m[3] = substr($string, $dotpos);
                        } else { # right shift, left pad
                            $string = str_pad($string, strlen($string) - $dotpos, '0', STR_PAD_LEFT);
                            $m[2] = '';
                            $m[3] = $string;
                        }
                    }

                    $intPart = ltrim($m[2], '0');
                    $decPart = rtrim($m[3], '0');
                    if (($intPart === '') && ($decPart === '')) $intPart = '0'; # corner case for zeros
                    if (($this->decimals = strlen($m[3])) <= $this->maxDecimals) {
                        # fine as it is
                        $this->gmp = gmp_add($this->gmp, gmp_init($m[1].$intPart.$decPart, 10));
                    } else {
                        # whoops, we need to round
                        $this->decimals = $this->maxDecimals;
                        if ((($remainder = (int) substr($decPart, $this->maxDecimals, 1)) != 0) && (($add = $this->getRoundAdd($remainder >= 5, $m[1] !== '', $this->rounding)) !== null))
                            $this->gmp = gmp_add($this->gmp, $add);
                        $this->gmp = gmp_add($this->gmp, gmp_init($m[1].$intPart.substr($decPart, 0, $this->maxDecimals), 10));
                    }
                } else {
                    throw new \InvalidArgumentException("The value supplied cannot be converted to number");
                }
            } elseif (is_integer($arg)) {
                # integers are easy
                $this->decimals = 0;
                $this->gmp = gmp_add($this->gmp, $arg);
            } elseif (is_float($arg)) {
                return $this->set((string) $arg); # convert as string, there is no benefit in processing floats differently
            } else {
                # something else but maybe GMP can handle it
                $this->decimals = 0;
                $this->gmp = gmp_add($this->gmp, $arg);
            }
        } else {
            if ($arg instanceof LongFloat) {
                # just copy it here (and do not forget to round)
                $this->gmp = $arg->gmp;
                $this->decimals = $arg->decimals;
                if ($this->decimals > $this->maxDecimals) $this->round($this->maxDecimals);
            } elseif ($arg instanceof \GMP) {
                # GMP integer value
                $this->decimals = 0;
                $this->gmp = $arg;
            } else {
                throw new \InvalidArgumentException("Unsupported argument type");
            }
        }
        
        return $this;
    }
    
    # x = x + arg
    public function add($arg)
    {
        if (!($arg instanceof LongFloat)) {
            # not a longfloat argument, the only special handling here would be the integer
            if (is_integer($arg)) {
                # integer is just pre-scaled and added
                if ($arg == 0) return $this; # this one check for zero seems to be excessive for a corner case but it is almost free compared to what we do later
                $this->gmp = gmp_add($this->gmp, ($this->decimals == 0) ? $arg : gmp_mul($arg, $this::$multipliers[$this->decimals] ?? $this->getMultiplier($this->decimals)));
                return $this;
            } else {
                $arg = new $this($arg, $this->maxDecimals, $this->rounding); # instantiate other numbers as our own type
            }
        }

        # to add two numbers, we may need to pre-scale either us or our argument
        if ($this->decimals == $arg->decimals) {
            # no prescaling necessary, as fun as it is
            $this->gmp = gmp_add($this->gmp, $arg->gmp);
        } elseif ($this->decimals > $arg->decimals) {
            # we need to prescale our argument
            $this->gmp = gmp_add($this->gmp, gmp_mul($arg->gmp, $this::$multipliers[$scale = $this->decimals - $arg->decimals] ?? $this->getMultiplier($scale)));
        } else {
            # we need to prescale us ourselves, this may imply rounding after the operation
            $this->gmp = gmp_add($arg->gmp, gmp_mul($this->gmp, $this::$multipliers[$scale = $arg->decimals - $this->decimals] ?? $this->getMultiplier($scale)));
            $this->decimals = $arg->decimals;
            if ($this->decimals > $this->maxDecimals) $this->round($this->maxDecimals);
        }

        return $this;
    }

    # x = x - arg
    public function sub($arg)
    {
        if (!($arg instanceof LongFloat)) {
            # not a longfloat argument, the only special handling here would be the integer
            if (is_integer($arg)) {
                # integer is just pre-scaled and subtracted
                if ($arg == 0) return $this; # this one check for zero seems to be excessive for a corner case but it is almost free compared to what we do later
                $this->gmp = gmp_sub($this->gmp, ($this->decimals == 0) ? $arg : gmp_mul($arg, $this::$multipliers[$this->decimals] ?? $this->getMultiplier($this->decimals)));
                return $this;
            } else {
                $arg = new $this($arg, $this->maxDecimals, $this->rounding); # instantiate other numbers as our own type
            }
        }

        # to subtract two numbers, we may need to pre-scale either us or our argument
        if ($this->decimals == $arg->decimals) {
            # no prescaling necessary, as fun as it is
            $this->gmp = gmp_sub($this->gmp, $arg->gmp);
        } elseif ($this->decimals > $arg->decimals) {
            # we need to prescale our argument
            $this->gmp = gmp_sub($this->gmp, gmp_mul($arg->gmp, $this::$multipliers[$scale = $this->decimals - $arg->decimals] ?? $this->getMultiplier($scale)));
        } else {
            # we need to prescale us ourselves, this may imply rounding after the operation
            $this->gmp = gmp_sub($arg->gmp, gmp_mul($this->gmp, $this::$multipliers[$scale = $arg->decimals - $this->decimals] ?? $this->getMultiplier($scale)));
            $this->decimals = $arg->decimals;
            if ($this->decimals > $this->maxDecimals) $this->round($this->maxDecimals);
        }

        return $this;
    }

    # x = arg - x
    # while this may look excessive, it allows to store the reverse subtraction result as our own, alleviating the need for cloning before subtraction
    public function subFrom($arg)
    {
        if (!($arg instanceof LongFloat)) {
            # not a longfloat argument, the only special handling here would be the integer
            if (is_integer($arg)) {
                # integer is just pre-scaled and subtracted
                if ($arg == 0) return $this; # this one check for zero seems to be excessive for a corner case but it is almost free compared to what we do later
                $this->gmp = gmp_sub($this->gmp, ($this->decimals == 0) ? $arg : gmp_mul($arg, $this::$multipliers[$this->decimals] ?? $this->getMultiplier($this->decimals)));
                return $this;
            } else {
                $arg = new $this($arg, $this->maxDecimals, $this->rounding); # instantiate other numbers as our own type
            }
        }

        # to subtract two numbers, we may need to pre-scale either us or our argument
        if ($this->decimals == $arg->decimals) {
            # no prescaling necessary, as fun as it is
            $this->gmp = gmp_sub($arg->gmp, $this->gmp);
        } elseif ($this->decimals > $arg->decimals) {
            # we need to prescale our argument
            $this->gmp = gmp_sub(gmp_mul($arg->gmp, $this::$multipliers[$scale = $this->decimals - $arg->decimals] ?? $this->getMultiplier($scale)), $this->gmp);
        } else {
            # we need to prescale us ourselves, this may imply rounding after the operation
            $this->gmp = gmp_sub(gmp_mul($this->gmp, $this::$multipliers[$scale = $arg->decimals - $this->decimals] ?? $this->getMultiplier($scale)), $arg->gmp);
            $this->decimals = $arg->decimals;
            if ($this->decimals > $this->maxDecimals) $this->round($this->maxDecimals);
        }

        return $this;
    }
    
    public function mul($arg)
    {
        if (!($arg instanceof LongFloat)) {
            # not a longfloat argument, the only special handling here would be the integer
            if (is_integer($arg)) {
                # integer is just multiplied directly
                $this->gmp = gmp_mul($this->gmp, $arg);
                return $this;
            } else {
                $arg = new $this($arg, $this->maxDecimals, $this->rounding); # instantiate other numbers as our own type
            }
        }
        
        # when we multiply two numbers, the resulting decimal digits is a sum of ones for numbers multiplied
        $this->gmp = gmp_mul($this->gmp, $arg->gmp);
        $this->decimals += $arg->decimals;
        if ($this->decimals > $this->maxDecimals) $this->round($this->maxDecimals);
        return $this;
    }
    
    public function div($arg)
    {
        if (!($arg instanceof LongFloat)) {
            # not a longfloat argument, the only special handling here would be the integer
            if (is_integer($arg)) {
                # division still needs prescaling to N+1 and rounding as we can get just that many decimals out of it
                if ($arg == 0) throw new \RangeException("Division by zero");
                $this->gmp = gmp_div(gmp_mul($this->gmp, $this::$multipliers[$scale = $this->maxDecimals - $this->decimals + 1] ?? $this->getMultiplier($scale)), $arg, GMP_ROUND_ZERO);
                $this->decimals = $this->maxDecimals + 1;
                return $this->round($this->maxDecimals);
            } else {
                $arg = new $this($arg, $this->maxDecimals, $this->rounding); # instantiate other numbers as our own type
            }
        }
        if (gmp_cmp($arg->gmp, 0)) throw new \RangeException("Division by zero");
        
        # for division, we need to prescale us by number of decimals in the argument, but not less than N+1 (our decimals max + 1) as we need to get the target precision
        $decimals = max($this->decimals + $arg->decimals, $this->maxDecimals + 1);
        $this->gmp = gmp_div(gmp_mul($this->gmp, $this::$multipliers[$scale = $decimals - $this->decimals] ?? $this->getMultiplier($scale)), $arg->gmp, GMP_ROUND_ZERO);
        $this->decimals = $decimals - $arg->decimals; # do not forget division descales us by the number of decimals in the argument
        return $this->round($this->maxDecimals);
    }
    
    public function divArg($arg)
    {
        if (gmp_cmp($this->gmp, 0)) throw new \RangeException("Division by zero");
        if (!($arg instanceof LongFloat)) $arg = new $this($arg, $this->maxDecimals, $this->rounding); # there are no better ways here as we divide the argument and not us

        # for inverse division, we need to prescale argument by number of our decimals, but not less than N+1 (our decimals max + 1) as we need to get the target precision
        $decimals = max($this->decimals + $arg->decimals, $this->maxDecimals + 1);
        $this->gmp = gmp_div(gmp_mul($arg->gmp, $this::$multipliers[$scale = $decimals - $arg->decimals] ?? $this->getMultiplier($scale)), $this->gmp, GMP_ROUND_ZERO);
        $this->decimals = $decimals - $this->decimals; # do not forget division descales us by the previous number of decimals
        return $this->round($this->maxDecimals);
    }
    
    public function sign()
    {
        return gmp_sign($this->gmp);
    }
    
    public function cmp($arg, $decimals = null, $rounding = null)
    {
        if (!($arg instanceof LongFloat)) {
            # not a longfloat argument, the only special handling here would be the integer
            if (is_integer($arg)) {
                # to compare with integer, we just prescale integer to minimum of given or our own precision
                $decimals = min($decimals, $this->decimals);
                return gmp_cmp($this->roundGMP($this->gmp, $this->decimals, $decimals, $rounding ?? $this->rounding), ($decimals == 0) ? $arg : gmp_mul($arg, $this::$multipliers[$decimals] ?? $this->getMultiplier($decimals)));
            } else {
                $arg = new $this($arg, $this->maxDecimals, $this->rounding); # instantiate other numbers as our own type, keeping target comparison precision in mind
            }
        }

        $arg = ($arg instanceof LongFloat) ? clone $arg : new $this($arg, $this->maxDecimals, $this->rounding); # there are no better ways here as we may need to alter argument precision
        
        # comparison is tricky precision wide: we always compare at the given precision or our own / available maximal precision
        # so to compare, we need to prescale or round both of our arguments to maximum of given precision and minimum of our own maximum precision / numbers own maximum available precision
        # if rounding override is not given, both arguments are rounded by their own respective rounding, otherwise rounding overrides both
        $decimals = max($decimals ?? $this->maxDecimals, min($this->maxDecimals, max($this->decimals, $arg->decimals)));
        return gmp_cmp(
            ($this->decimals == $decimals) ?
                $this->gmp
                : (($this->decimals < $decimals) ?
                    gmp_mul($this->gmp, $this::$multipliers[$scale = $decimals - $this->decimals] ?? $this->getMultiplier($scale))
                    : $this->roundGMP($this->gmp, $this->decimals, $decimals, $rounding ?? $this->rounding)
                ),
            ($arg->decimals == $decimals) ?
                $arg->gmp
                : (($arg->decimals < $decimals) ?
                    gmp_mul($arg->gmp, $arg::$multipliers[$scale = $decimals - $arg->decimals] ?? $arg->getMultiplier($scale))
                    : $arg->roundGMP($arg->gmp, $arg->decimals, $decimals, $rounding ?? $this->rounding)
                )
        );
    }
    
    public function cmpRev($arg, $decimals = null, $rounding = null)
    {
        if (!($arg instanceof LongFloat)) {
            # not a longfloat argument, the only special handling here would be the integer
            if (is_integer($arg)) {
                # to compare with integer, we just prescale integer to minimum of given or our own precision
                $decimals = min($decimals, $this->decimals);
                return gmp_cmp(($decimals == 0) ? $arg : gmp_mul($arg, $this::$multipliers[$decimals] ?? $this->getMultiplier($decimals)), $this->roundGMP($this->gmp, $this->decimals, $decimals, $rounding ?? $this->rounding));
            } else {
                $arg = new $this($arg, $this->maxDecimals, $this->rounding); # instantiate other numbers as our own type, keeping target comparison precision in mind
            }
        }

        # reverse comparison is tricky precision wide: we always compare at the given precision or *our own* maximal precision
        # so to compare, we need to prescale or round both of our arguments to maximum of given precision and minimum of our own maximum precision / numbers own maximum available precision
        # if rounding override is not given, both arguments are rounded by their own respective rounding, otherwise rounding overrides both
        $decimals = max($decimals ?? $this->maxDecimals, min($this->maxDecimals, max($this->decimals, $arg->decimals)));
        return gmp_cmp(
            ($arg->decimals == $decimals) ?
                $arg->gmp
                : (($arg->decimals < $decimals) ?
                    gmp_mul($arg->gmp, $arg::$multipliers[$scale = $decimals - $arg->decimals] ?? $arg->getMultiplier($scale))
                    : $arg->roundGMP($arg->gmp, $arg->decimals, $decimals, $rounding ?? $this->rounding)
                ),
            ($this->decimals == $decimals) ?
                $this->gmp
                : (($this->decimals < $decimals) ?
                    gmp_mul($this->gmp, $this::$multipliers[$scale = $decimals - $this->decimals] ?? $this->getMultiplier($scale))
                    : $this->roundGMP($this->gmp, $this->decimals, $decimals, $rounding ?? $this->rounding)
                )
        );
    }
    
    public function round($decimals, $rounding = null)
    {
        if ($this->decimals <= $decimals) return $this; # nothing to do, this is excessive but better safe than sorry

        if (gmp_sign($this->gmp) == 0) { # rounding ourselves is the companding occasion, we compand zeros fast here
            $this->gmp = clone $this::$zero;
            $this->decimals = 0;
            return $this;
        }
       
        $this->gmp = $this->roundGMP($this->gmp, $this->decimals, $decimals, $rounding ?? $this->rounding);
        $this->decimals = $decimals;
        return $this;
    }
   
    public function asString($maxDecimals = null, $rounding = null)
    {
        if (($maxDecimals === null) || ($this->decimals <= $maxDecimals)) {
            # no rounding
            $str = str_pad(gmp_strval(gmp_abs($this->gmp), 10), $this->decimals + 1, '0', STR_PAD_LEFT);
            return ((gmp_sign($this->gmp) < 0) ? '-' : '').substr($str, 0, $intLen = strlen($str) - $this->decimals).rtrim('.'.substr($str, $intLen), '0.');
        } else {
            # pre-rounding required
            $str = str_pad(gmp_strval(gmp_abs($val = $this->roundGMP($this->gmp, $this->decimals, $maxDecimals, $rounding ?? $this->rounding)), 10), $maxDecimals + 1, '0', STR_PAD_LEFT);
            return ((gmp_sign($val) < 0) ? '-' : '').substr($str, 0, $intLen = strlen($str) - $maxDecimals).rtrim('.'.substr($str, $intLen), '0.');
        }
    }
    
    public function __toString()
    {
        return $this->asString();
    }
    
    protected function roundGMP($gmp, $decimals, $maxDecimals, $rounding)
    {
        if ($decimals <= $maxDecimals) return $gmp; # no rounding necessary, this is excessive but better safe than sorry
        
        $multiplier = $this::$multipliers[$mulwidth = $decimals - $maxDecimals] ?? $this->getMultiplier($mulwidth);
        list($result, $remainder) = gmp_div_qr($gmp, $multiplier, GMP_ROUND_ZERO);

        if ($mulwidth > 1) {
            if (gmp_cmp($remainder, $this::$zero)) # with non-zero remainder, we need to round
                if (($add = $this->getRoundAdd(gmp_cmp(gmp_abs($remainder), $this::$halfMultipliers[$mulwidth]) >= 0, gmp_sign($gmp) < 0, $rounding)) !== null)
                    $result = gmp_add($result, $add);
        } else {
            # simple small remainder that we can just process as integer
            if (($last = abs(gmp_intval($remainder))) != 0) 
                if (($add = $this->getRoundAdd($last >= 5, gmp_sign($gmp) < 0, $rounding)) !== null)
                    $result = gmp_add($result, $add);
        }

        return $result;
    }

    # take care: this one assumes rounded fraction is non-zero
    protected function getRoundAdd($isHalf, $isNegative, $rounding)
    {
       switch ($rounding) {
            case $this::ROUND_MATH: return $isHalf ? ($isNegative ? $this::$minusOne : $this::$one) : null;
            case $this::ROUND_TRUNC: return null;
            case $this::ROUND_FLOOR: return $isNegative ? $this::$minusOne : null;
            case $this::ROUND_CEIL: return $isNegative ? null : $this::$one;
            case $this::ROUND_SPREAD: return $isNegative ? $this::$minusOne : $this::$one;
            default: return $isHalf ? ($isNegative ? $this::$minusOne : $this::$one) : null;
        }
    }

    protected function getMultiplier($decimals)
    {
        if (($multiplier = ($this::$multipliers[$decimals] ?? null)) !== null) return $multiplier;
        $this::$halfMultipliers[$decimals] = gmp_div(gmp_pow(10, $decimals), 2, GMP_ROUND_ZERO); # initialize new half multiplier
        return $this::$multipliers[$decimals] = gmp_pow(10, $decimals); # initialize new decimal multiplier
   }
}
