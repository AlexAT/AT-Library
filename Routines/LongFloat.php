<?php

namespace ATL;

########
# Long arbitrary floating point number math routines providing high decimal point precision using PHP GMP, will be adjusted to use GMP floats once PHP GMP supports it
# Processing numbers with different maximum precision/rounding is supported, rounding only happens when the maximum precision allowed is exceeded or when printing only
# Internally, numbers are represented as numerator/denominator fractions that are only calculated on printing and on operations that cannot work with fractions
# This gives very high (although a bit unpredictable) internal precision, so the precision may need to be checked after each operation to round the fraction if precision is exceeded
# To do that and process with fixed point like precision, set autoRounding to true, but this may cause rounding errors to accumulate heavily
# The alternative is greatest common denominator companding of the fractions which is enabled by default, set autoCompand to false to disable it
# Take care automatic rounding and companding does not happen on fast and primitive operations, i.e. setting the value or operations not adjusting the denominator, like add/sub with integer argument
# round() call without arguments can be used to force rounding to the target precision
# checkRound() call without arguments can be used to round internal fraction to the target precision if the target precision is exceeded
# similarly, compand() and checkCompand() call can be used to compand the fraction unilaterally or if the target precision is exceeded

class LongFloat
{
    # rounding mode details are demonstrated for 1-digit precision
    const ROUND_MATH = 0; # default rounding mode, 0.00 to 0.04 => 0.0, 0.05 to 0.09 => 0.1, -0.01 to -0.04 => 0.0, -0.05 to -0.09 => -0.1
    const ROUND_TRUNC = 1; # truncation mode, 0.00 to 0.09 => 0.0, -0.01 to -0.09 => 0.0
    const ROUND_FLOOR = 2; # floor mode, 0.00 to 0.09 => 0.0, -0.01 to -0.09 => 0.1
    const ROUND_CEIL = 3; # ceil mode, 0.00 => 0.0, 0.01 to 0.09 => 0.1, -0.01 to -0.09 => 0.0
    const ROUND_SPREAD = 4; # spread mode, 0.00 => 0.0, 0.01 to 0.09 => 0.1, -0.01 to -0.09 => 0.01

    # never ever touch these in runtime, they are public to speed up some operations (methods take too much time to be called)
    public $numerator = 0; # take care this can be either integer or GMP
    public $denominator = 1; # take care this can be either integer or GMP

    # these can be adjusted in runtime, but you need to understand the mechanics and consequences of doing so
    public $maxDecimals;
    public $rounding;
    public $autoRounding;
    public $autoCompand;
    
    # globals
    protected static $multipliers = []; # this one holds GMP floating point multipliers for all precisions noticed

    # these can be changed in runtime (set it once at application startup if necessary)
    public static $defaultDecimals = 20; # default precision for new numbers (take care it's not for numbers created inside operations, these are created at the operating number precision)
    public static $defaultRounding = self::ROUND_MATH; # default rounding mode for new numbers (take care all operations happen at the operating number rounding)
    public static $defaultAutoRounding = false; # default flag for doing rounding on every operation if the target precision is exceeded
    public static $defaultAutoCompand = true; # default flag for companding fractions on every operation if the target precision is exceeded

    # number = integer, float or point-decimal string to convert to LongFloat, can also be another LongFloat number or GMP integer
    # maxDecimals = maximum number of decimals handled on printing, internal precision may be higher
    # rounding mode = one of ROUND_MATH, ROUND_TRUNC, ROUND_FLOOR, ROUND_CEIL, ROUND_SPREAD above
    # roundAlways = for compatibility with fixed precision math, makes us check for internal precision to be exceeded after each and every operation and round if exceeded
    public function __construct($number = 0, $maxDecimals = null, $rounding = null, $autoRounding = null, $autoCompand = null)
    {
        $this->maxDecimals = max(0, $maxDecimals ?? $this::$defaultDecimals);
        $this->rounding = $rounding ?? $this::$defaultRounding;
        $this->autoRounding = $autoRounding ?? $this::$defaultAutoRounding;
        $this->autoCompand = $autoCompand ?? $this::$defaultAutoCompand;
        $this->set($number);
    }

    # x = arg, where arg is arbitrary convertible type (integer, float, GMP integer, other LongFloat or string representing integer/float, E-notation is supported)
    public function set($arg)
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
                    $this->numerator = gmp_init($m[1].$intPart.$decPart, 10);
                    $this->denominator = $this::$multipliers[$decimals = strlen($decPart)] ?? $this->getMultiplier($decimals);
                } else {
                    throw new \InvalidArgumentException("The value supplied cannot be converted to number");
                }
            } elseif (is_integer($arg)) {
                # integers are easy
                $this->numerator = gmp_init($arg);
                $this->denominator = 1;
            } elseif (is_float($arg)) {
                return $this->set((string) $arg); # convert as string, there is no benefit in processing floats differently
            } else {
                throw new \InvalidArgumentException("Unsupported argument type");
            }
        } else {
            if ($arg instanceof LongFloat) {
                # just copy it here (and do not forget to round)
                $this->numerator = $arg->numerator;
                $this->denominator = $arg->denominator;
            } elseif ($arg instanceof \GMP) {
                # GMP integer value
                $this->numerator = $arg;
                $this->denominator = 1;
            } else {
                throw new \InvalidArgumentException("Unsupported argument type");
            }
        }

        return $this;
    }
    
    # x = arg1 / arg2, arg1 and arg2 can only be integers or GMP integers, arg2 cannot be negative or zero
    public function setFraction($arg1, $arg2)
    {
        if (!is_integer($arg1) && !($arg1 instanceof \GMP)) throw new \InvalidArgumentException("Unsupported argument type");
        if (!is_integer($arg2) && !($arg2 instanceof \GMP)) throw new \InvalidArgumentException("Unsupported argument type");
        if (is_integer($arg2)) {
            if ($arg2 <= 0) throw new \RangeException("Denominator argument cannot be negative or zero");
        } else {
            if (gmp_sign($arg2) <= 0) throw new \RangeException("Denominator argument cannot be negative or zero");
        }
        $this->numerator = $arg1;
        $this->denominator = $arg2;
        return $this;
    }

    # x = x + arg
    public function add($arg)
    {
        if (!$arg instanceof LongFloat) {
            # integer handling is easy, the integer is pre-scaled to our denominator
            if (is_integer($arg) || ($arg instanceof \GMP)) {
                $this->numerator = gmp_add($this->numerator, gmp_mul($this->denominator, $arg));
                return $this;
            } else {
                $arg = new $this($arg, $this->maxDecimals, $this->rounding, $this->autoRounding, $this->autoCompand); # convert to our own type
            }
        }
        
        # to add two numbers, we multiply their denominators together and multiply each numerator by opposite denominator
        $this->numerator = gmp_add(gmp_mul($this->numerator, $arg->denominator), gmp_mul($arg->numerator, $this->denominator));
        $this->denominator = gmp_mul($this->denominator, $arg->denominator);

        return $this->autoRounding ? $this->checkRound() : ($this->autoCompand ? $this->checkCompand() : $this);
    }

    # x = x - arg
    public function sub($arg)
    {
        if (!$arg instanceof LongFloat) {
            # integer handling is easy, the integer is pre-scaled to our denominator
            if (is_integer($arg) || ($arg instanceof \GMP)) {
                $this->numerator = gmp_sub($this->numerator, gmp_mul($this->denominator, $arg));
                return $this;
            } else {
                $arg = new $this($arg, $this->maxDecimals, $this->rounding, $this->autoRounding, $this->autoCompand); # convert to our own type
            }
        }
        
        # to subtract two numbers, we multiply their denominators together and multiply each numerator by opposite denominator
        $this->numerator = gmp_sub(gmp_mul($this->numerator, $arg->denominator), gmp_mul($arg->numerator, $this->denominator));
        $this->denominator = gmp_mul($this->denominator, $arg->denominator);

        return $this->autoRounding ? $this->checkRound() : ($this->autoCompand ? $this->checkCompand() : $this);
    }

    # x = arg - x
    public function subRev($arg)
    {
        if (!$arg instanceof LongFloat) {
            # integer handling is easy, the integer is pre-scaled to our denominator
            if (is_integer($arg) || ($arg instanceof \GMP)) {
                $this->numerator = gmp_sub(gmp_mul($this->denominator, $arg), $this->numerator);
                return $this;
            } else {
                $arg = new $this($arg, $this->maxDecimals, $this->rounding, $this->autoRounding, $this->autoCompand); # convert to our own type
            }
        }
        
        # to subtract two numbers, we multiply their denominators together and multiply each numerator by opposite denominator
        $this->numerator = gmp_sub(gmp_mul($arg->numerator, $this->denominator), gmp_mul($this->numerator, $arg->denominator));
        $this->denominator = gmp_mul($this->denominator, $arg->denominator);

        return $this->autoRounding ? $this->checkRound() : ($this->autoCompand ? $this->checkCompand() : $this);
    }

    public function mul($arg)
    {
        if (!$arg instanceof LongFloat) {
            # integer handling is easy, we just multiply our numerator and that is it
            if (is_integer($arg) || ($arg instanceof \GMP)) {
                $this->numerator = gmp_mul($this->numerator, $arg);
                return $this;
            } else {
                $arg = new $this($arg, $this->maxDecimals, $this->rounding, $this->autoRounding, $this->autoCompand); # convert to our own type
            }
        }

        # when we multiply two numbers, we just multiply their numerators and denominators
        $this->numerator = gmp_mul($this->numerator, $arg->numerator);
        $this->denominator = gmp_mul($this->denominator, $arg->denominator);

        return $this->autoRounding ? $this->checkRound() : ($this->autoCompand ? $this->checkCompand() : $this);
    }

    public function div($arg)
    {
        if (!$arg instanceof LongFloat) {
            # integer handling is easy, we just multiply our denominator and that is it, the slightly separate handling here is because we need to check for zero
            if (is_integer($arg)) {
                if ($arg == 0) throw new \RangeException("Division by zero");
                $this->denominator = gmp_mul($this->denominator, $arg);
                return $this;
            } elseif ($arg instanceof \GMP) {
                if (!gmp_sign($arg)) throw new \RangeException("Division by zero");
                $this->denominator = gmp_mul($this->denominator, $arg);
                return $this;
            } else {
                $arg = new $this($arg, $this->maxDecimals, $this->rounding, $this->autoRounding, $this->autoCompand); # convert to our own type
            }
        }

        if (!gmp_sign($arg->numerator)) throw new \RangeException("Division by zero"); # check for zero

        # when we divide two numbers, we just cross-multiply our numerator by remote denominator as new numerator and our denominator by remote numerator as new denominator
        # take care we need to store $arg->numerator as $arg can be us ourselves and so we can destroy it in the process
        $argNumerator = $arg->numerator;
        $this->numerator = gmp_mul($this->numerator, $arg->denominator);
        $this->denominator = gmp_mul($this->denominator, $argNumerator);
        if (gmp_sign($this->denominator) < 0) {
            # invert signs so denominator is positive
            $this->numerator = gmp_neg($this->numerator);
            $this->denominator = gmp_neg($this->denominator);
        }
        return $this->autoRounding ? $this->checkRound() : ($this->autoCompand ? $this->checkCompand() : $this);
    }

    public function divRev($arg)
    {
        if (!gmp_sign($this->numerator)) throw new \RangeException("Division by zero");
        
        if (!$arg instanceof LongFloat) {
            # integer handling is easy, we swap our numerator and denominator and then multiply the numerator
            # for case of us ourselves, we do not need to store anything as oldDenominator is already stored
            if (is_integer($arg) || ($arg instanceof \GMP)) {
                $oldDenominator = $this->denominator;
                $this->denominator = $this->numerator;
                $this->numerator = gmp_mul($oldDenominator, $arg);
                return $this;
            } else {
                $arg = new $this($arg, $this->maxDecimals, $this->rounding, $this->autoRounding, $this->autoCompand); # convert to our own type
            }
        }
        
        # for reverse division, we just cross-multiply our denominator by remote numerator as new numerator and our numerator by remote denominator as new denumerator
        # this requires saving old numerator during the operation, here we do not need to store anything else for case of us ourselves as oldNumerator is already stored
        $oldNumerator = $this->numerator;
        $this->numerator = gmp_mul($this->denominator, $arg->numerator);
        $this->denominator = gmp_mul($oldNumerator, $arg->denominator);
        if (gmp_sign($this->denominator) < 0) {
            # invert signs so denominator is positive
            $this->numerator = gmp_neg($this->numerator);
            $this->denominator = gmp_neg($this->denominator);
        }
        return $this->autoRounding ? $this->checkRound() : ($this->autoCompand ? $this->checkCompand() : $this);
    }
    
    public function neg()
    {
        $this->numerator = gmp_neg($this->numerator);
        return $this;
    }
    
    public function inv()
    {
        $numerator = $this->numerator;
        $this->numerator = $this->denominator;
        $this->denominator = $numerator;
        if (gmp_sign($this->denominator) < 0) {
            # invert signs so denominator is positive
            $this->numerator = gmp_neg($this->numerator);
            $this->denominator = gmp_neg($this->denominator);
        }
        return $this;
    }

    # returns -1 if we are negative, 0 if we are zero, 1 if we are positive
    public function sign()
    {
        return gmp_sign($this->numerator);
    }

    # compare operation can operate at specific precision and rounding type if needed
    # if precision or rounding is specified, it will use rounding to the given precision (if only rounding is specified, at our own precision)
    # otherwise it will just compare absolute values, both at the denominator precision they are currently at (by pre-multiplying the values)
    # returns -1 if we are lower, 0 if we are equal, or 1 if we are higher
    public function cmp($arg, $decimals = null, $rounding = null)
    {
        if (!$arg instanceof LongFloat) {
            # integer handling is easy, we just multiply by our denominator and compare, but this can only be done if no rounding is given
            if (($decimals === null) && ($rounding === null) && (is_integer($arg) || ($arg instanceof \GMP))) {
                return gmp_cmp($this->numerator, gmp_mul($arg, $this->denominator));
            } else {
                $arg = new $this($arg, $this->maxDecimals, $this->rounding, $this->autoRounding, $this->autoCompand); # convert to our own type
            }
        }
        
        # if forced rounding is not specified, just compare multiplies of each argument by the remote denominator
        if (($decimals === null) && ($rounding === null))
            return gmp_cmp(gmp_mul($this->numerator, $arg->denominator), gmp_mul($arg->numerator, $this->denominator));
        
        # otherwise, we need to process comparing rounding for both us and the argument
        return gmp_cmp(
            $this->getRoundedValue($this->numerator, $this->denominator, $decimals = $decimals ?? $this->maxDecimals, $rounding = $rounding ?? $this->rounding), 
            $this->getRoundedValue($arg->numerator, $arg->denominator, $decimals, $rounding)
        );
    }

    public function cmpRev($arg, $decimals = null, $rounding = null)
    {
        # reverse compare is almost totally equal to general compare, just reverses the arguments and uses our own precision if rounding is specified without precision

        if (!$arg instanceof LongFloat) {
            # integer handling is easy, we just multiply by our denominator and compare, but this can only be done if no rounding is given
            if (($decimals === null) && ($rounding === null) && (is_integer($arg) || ($arg instanceof \GMP))) {
                return gmp_cmp(gmp_mul($arg, $this->denominator), $this->numerator);
            } else {
                $arg = new $this($arg, $this->maxDecimals, $this->rounding, $this->autoRounding, $this->autoCompand); # convert to our own type
            }
        }
        
        # if forced rounding is not specified, just compare multiplies of each argument by the remote denominator
        if (($decimals === null) && ($rounding === null))
            return gmp_cmp(gmp_mul($arg->numerator, $this->denominator), gmp_mul($this->numerator, $arg->denominator));
        
        # otherwise, we need to process comparing rounding for both us and the argument
        return gmp_cmp(
            $this->getRoundedValue($arg->numerator, $arg->denominator, $decimals = $decimals ?? $this->maxDecimals, $rounding = $rounding ?? $this->rounding),
            $this->getRoundedValue($this->numerator, $this->denominator, $decimals, $rounding)
        );
    }
    
    public function pow($arg)
    {
        if (!is_integer($arg)) {
            if (!$arg instanceof LongFloat) {
                # integer handling is easy, we just take powers of both our numerator and denominator and that is it, the slightly separate handling here is because we need to check for zero and sign
                if ($arg instanceof \GMP) {
                    # provide integer GMP argument directly as numerator with denominator 1
                    $numerator = $arg; 
                    $denominator = 1;
                } else {
                    $arg = new $this($arg, $this->maxDecimals, $this->rounding, $this->autoRounding, $this->autoCompand);
                }
            }
        
            if ($arg instanceof LongFloat) {
                # provide numerator and denominator from the argument
                $numerator = $arg->numerator;
                $denominator = $arg->denominator;
            }

            # taking fractional powers is possible but tricky (argument denominator level root of value in argument numerator power)
            if (gmp_cmp($denominator, 1)) {
                # as auto-rounding can affect the result precision, we disable it for the time of operation, also we take care root must come second as it loses arbitrary precision
                $autoRounding = $this->autoRounding;
                $this->autoRounding = false;
                $this->pow($numerator);
                $this->autoRounding = $autoRounding;
                return $this->root($denominator);
            }

            # convert argument to integer
            if ((gmp_cmp($numerator, PHP_INT_MAX) > 0) || (gmp_cmp($numerator, PHP_INT_MIN) < 0)) throw new \RangeException("The power is too powerful");
            $arg = gmp_intval($numerator);
        }

        # now take the power of integer
        if ($arg == 0) {
            # anything in power of 0 is 1
            $this->numerator = 1;
            $this->denominator = 1;
            return $this;
        }
      
        if ($arg == 1) return $this; # anything in power of 1 is unchanged value

        if ($arg > 0) {
            $this->numerator = gmp_pow($this->numerator, $arg);
            $this->denominator = gmp_pow($this->denominator, $arg);
        } else {
            # for negative powers, we need to just swap our numerator and denominator, but another corner case is negative power of 0
            if (!gmp_sign($this->numerator)) throw new \RangeException("Taking negative power of zero is not possible");
            $arg = -$arg;
            $numerator = $this->numerator;
            $this->numerator = $this->denominator;
            $this->numerator = gmp_pow($this->denominator, $arg);
            $this->denominator = gmp_pow($numerator, $arg);
            if (gmp_sign($this->denominator) < 0) {
                # invert signs so denominator is positive
                $this->numerator = gmp_neg($this->numerator);
                $this->denominator = gmp_neg($this->denominator);
            }
        }

        return $this->autoRounding ? $this->checkRound() : ($this->autoCompand ? $this->checkCompand() : $this);
    }
    
    # alias for root(2), all root() implications apply
    public function sqrt()
    {
        return $this->root(2);
    }
    
    # taking root is one of operations that cannot guarantee arbitrary precision, it is always performed at most at maxDecimals+1 precision
    public function root($arg)
    {
        if (!is_integer($arg)) {
            if (!$arg instanceof LongFloat) {
                if ($arg instanceof \GMP) {
                    # provide integer GMP argument directly as numerator with denominator 1
                    $numerator = $arg; 
                    $denominator = 1;
                } else {
                    $arg = new $this($arg, $this->maxDecimals, $this->rounding, $this->autoRounding, $this->autoCompand);
                }
            }
        
            if ($arg instanceof LongFloat) {
                # provide numerator and denominator from the argument
                $numerator = $arg->numerator;
                $denominator = $arg->denominator;
            }

            # taking fractional power roots is possible but tricky (argument numerator level root of value in argument denominator power)
            if (gmp_cmp($denominator, 1)) {
                # as auto-rounding can affect the result precision, we disable it for the time of operation, also we take care root must come second as it loses arbitrary precision
                $autoRounding = $this->autoRounding;
                $this->autoRounding = false;
                $this->pow($denominator);
                $this->autoRounding = $autoRounding;
                return $this->root($numerator);
            }

            # taking fractional powers is possible (argument denominator level root of value in argument numerator power)is not yet possible
            if (gmp_cmp($denominator, 1)) throw new \RangeException("Taking fractional power roots is not possible");

            # convert argument to integer
            if ((gmp_cmp($numerator, PHP_INT_MAX) > 0) || (gmp_cmp($numerator, PHP_INT_MIN) < 0)) throw new \RangeException("The root power is too powerful");
            $arg = gmp_intval($numerator);
        }

        # now take the root of integer
        if ($arg == 0) throw new \RangeException("Taking root of zero power is not possible");
        if ($arg == 1) return $this; # power 1 root of everything is unchanged value
        
        # signs are tricky, if root power is even, negative numbers do not have any roots
        if ((!($arg & 1)) && (gmp_sign($this->numerator) < 0)) throw new \RangeException("Taking even power roots from negative numbers is not possible");
        
        if ($arg > 0) {
            # here is the precision issue, pre-multiply both our numerator and denominator by maxDecimals+1 in the given power to ensure there is no precision loss below maxDecimals
            $this->numerator = gmp_mul($this->numerator, gmp_pow($this::$multipliers[$precision = $this->maxDecimals + 1] ?? $this->getMultiplier($precision), $arg));
            $this->denominator = gmp_mul($this->denominator, gmp_pow($this::$multipliers[$precision], $arg));
            
            if ($arg == 2) {
                # the square root is optimized
                $this->numerator = gmp_sqrt($this->numerator);
                $this->denominator = gmp_sqrt($this->denominator);
            } else {
                $this->numerator = gmp_root($this->numerator, $arg);
                $this->denominator = gmp_root($this->denominator, $arg);
            }
        } else {
            # for negative root powers, we need to just swap our numerator and denominator, but another corner case is root of negative power from 0
            if (!gmp_sign($this->numerator)) throw new \RangeException("Taking negative power root of zero is not possible");
            $arg = -$arg;

            # here is the precision issue, pre-multiply both our numerator and denominator by maxDecimals+1 in the given power to ensure there is no precision loss below maxDecimals
            $this->numerator = gmp_mul($this->numerator, gmp_pow($this::$multipliers[$precision = $this->maxDecimals + 1] ?? $this->getMultiplier($precision), $arg));
            $this->denominator = gmp_mul($this->denominator, gmp_pow($this::$multipliers[$precision], $arg));

            $numerator = $this->numerator;
            if ($arg == 2) {
                # again, the square root is optimized
                $this->numerator = gmp_sqrt($this->denominator);
                $this->denominator = gmp_sqrt($numerator);
            } else {
                $this->numerator = gmp_root($this->denominator, $arg);
                $this->denominator = gmp_root($numerator, $arg);
            }
            if (gmp_sign($this->denominator) < 0) {
                # invert signs so denominator is positive
                $this->numerator = gmp_neg($this->numerator);
                $this->denominator = gmp_neg($this->denominator);
            }
        }

        return $this->autoRounding ? $this->checkRound() : ($this->autoCompand ? $this->checkCompand() : $this);
    }

    public function round($decimals = null, $rounding = null)
    {
        # this one really rounds us to the given precision, disregarding any precision we are actually at
        # the exceptional cases are when we are at zero or integer precision, we don't do anything then (may look tad costly per se, but it saves us the whole run below)
        if (!gmp_cmp($this->denominator, 1)) return $this;
        if (!gmp_sign($this->numerator)) {
            # also clear the denominator in case we are zero
            $this->denominator = 1;
            return $this;
        }
        
        # rounding is also simple: we multiply our numerator by the target precision required * 10, and then divide by existing denominator * 10
        # to see if the rounding is necessary, we compare remainder of the operation to 0 (nothing to round) and then to existing denominator multiplied by 5 to check if we are at the half of the range
        $this->numerator = $this->getRoundedValue($this->numerator, $this->denominator, $decimals = $decimals ?? $this->maxDecimals, $rounding ?? $this->rounding);
        $this->denominator = $this::$multipliers[$decimals] ?? $this->getMultiplier($decimals);

        # the rounding was simple, now we need to also try to compand if autocompand is enabled
        return $this->autoCompand ? $this->compand() : $this;
    }

    public function checkRound($decimals = null, $rounding = null)
    {
        # if our denominator is less than or equal to the precision required, we do nothing, otherwise we do the rounding
        return (gmp_cmp($this->denominator, $this::$multipliers[$decimals = $decimals ?? $this->maxDecimals] ?? $this->getMultiplier($decimals)) <= 0) ? $this : $this->round($decimals, $rounding);
    }
   
    public function compand()
    {
        # saves us gcd, cmp and two divs in quite not an improbable case we are zero
        if (!gmp_sign($this->numerator)) {
            # clear the denominator in case we are zero
            $this->denominator = 1;
            return $this;
        }

        # compand us by the greatest common integer denominator of numerator and denominator, if any >1, to prevent overgrowth
        $gcd = gmp_gcd($this->numerator, $this->denominator);
        if (gmp_cmp($gcd, 1) > 0) {
            $this->numerator = gmp_div($this->numerator, $gcd, GMP_ROUND_ZERO);
            $this->denominator = gmp_div($this->denominator, $gcd, GMP_ROUND_ZERO);
        }
        return $this;
    }
    
    public function checkCompand()
    {
        # if our denominator is less than or equal to the precision required, we do nothing, otherwise we do the companding
        return (gmp_cmp($this->denominator, $this::$multipliers[$decimals = $decimals ?? $this->maxDecimals] ?? $this->getMultiplier($decimals)) <= 0) ? $this : $this->compand();
    }
    
    # like compare operation, this can operate at specific precision and rounding type if needed
    # if precision or rounding is specified, it will use rounding to the given precision (if only rounding is specified, at our own precision)
    public function isInteger($decimals = null, $rounding = null)
    {
        if (($decimals === null) && ($rounding === null)) {
            # a tricky form of compand
            if (!gmp_sign($this->numerator)) {
                # clear the denominator in case we are zero
                $this->denominator = 1;
                return true;
            }
            
            if (!gmp_cmp($this->denominator, 1)) return true;

            $gcd = gmp_gcd($this->numerator, $this->denominator);
            if (!gmp_cmp($gcd, $this->denominator)) {
                $this->numerator = gmp_div($this->numerator, $gcd, GMP_ROUND_ZERO);
                $this->denominator = 1;
                return true;
            }

            return false;
        } else {
            # check at the precision specified
            $value = $this->getRoundedValue($this->numerator, $this->denominator, $decimals = $decimals ?? $this->maxDecimals, $rounding ?? $this->rounding);
            return !gmp_sign(gmp_div_r($value, $this::$multipliers[$decimals] ?? $this->getMultiplier($decimals)));
        }
    }
    
    public function isZero()
    {
        return !gmp_sign($this->numerator);
    }
    
    public function isPositive()
    {
        return (gmp_sign($this->numerator) > 0);
    }
    
    public function isNegative()
    {
        return (gmp_sign($this->numerator) < 0);
    }

    public function toString($maxDecimals = null, $rounding = null)
    {
        # this one works much like round() but does not store the result nor compands it
        # the same exceptional cases are when we are at zero or integer precision, it saves us the whole run
        if (!gmp_cmp($this->denominator, 1)) return gmp_strval($this->numerator, 10);
        if (!gmp_sign($this->numerator)) return '0';
       
        # do the one-time rounding
        $value = $this->getRoundedValue($this->numerator, $this->denominator, $maxDecimals = $maxDecimals ?? $this->maxDecimals, $rounding ?? $this->rounding);
        
        # print, split and truncate the resulting value
        $str = str_pad(gmp_strval(gmp_abs($value), 10), $maxDecimals + 1, '0', STR_PAD_LEFT);
        return ((gmp_sign($value) < 0) ? '-' : '').substr($str, 0, $intLen = strlen($str) - $maxDecimals).rtrim('.'.substr($str, $intLen), '0.');
    }

    public function asString($maxDecimals = null, $rounding = null)
    {
        return $this->toString($maxDecimals, $rounding);
    }

    public function __toString()
    {
        return $this->toString();
    }
    
    public function pi()
    {
        # PI calculation (Gauss-Legendre) with current max decimals
        $decimals = $this->maxDecimals * 2; # do twice as much to prevent rounding errors
        $a = new $this(1, $decimals, $this::ROUND_MATH, false, true);
        $b = (new $this(2, $decimals, $this::ROUND_MATH, false, true))->sqrt()->divRev(1);
        $t = new $this("0.25", $decimals, $this::ROUND_MATH, false, true);
        $p = new $this(1, $decimals, $this::ROUND_MATH, false, true);

        $loops = ceil(log($decimals, 2));
        for ($i = 0; $i < $loops; $i++) {
            $aPrev = clone $a; # aPrev = a
            $a->add($b)->div(2); # a = (a + b) / 2
            $b->mul($aPrev)->sqrt(); # b = sqrt(aPrev * b);
            $t->sub($aPrev->sub($a)->mul($aPrev)->mul($p)); # t = t - (p * ((aPrev - a) ^ 2))
            $p->mul(2); # p = p * 2
        }
        
        # approximate PI as (a + b) ^ 2 / (t * 4)
        $a->add($b)->mul($a)->div($t->mul(4));
        
        # round and applaud
        return $this->set($a)->round();
    }
    
    # this one gets rounded numerator value for specific decimal precision and rounding
    protected function getRoundedValue($numerator, $denominator, $decimals, $rounding)
    {
        list($numerator, $remainder) = gmp_div_qr(gmp_mul($numerator, $this::$multipliers[$precision = $decimals + 1] ?? $this->getMultiplier($precision)), gmp_mul($denominator, 10), GMP_ROUND_ZERO);
        if (gmp_sign($remainder = gmp_abs($remainder))) {
            switch ($rounding) {
                case $this::ROUND_MATH: $add = (gmp_cmp($remainder, gmp_mul($denominator, 5)) >= 0) ? ((gmp_sign($numerator) >= 0) ? 1 : -1) : null; break;
                case $this::ROUND_TRUNC: $add = null; break;
                case $this::ROUND_FLOOR: $add = (gmp_sign($numerator) >= 0) ? null : -1; break;
                case $this::ROUND_CEIL: $add = (gmp_sign($numerator) >= 0) ? 1 : null; break;
                case $this::ROUND_SPREAD: $add = (gmp_sign($numerator) >= 0) ? 1 : -1; break;
                default: $add = (gmp_cmp($remainder, gmp_mul($denominator, 5)) >= 0) ? ((gmp_sign($numerator) >= 0) ? 1 : -1) : null; break;
            }
            if ($add !== null) return gmp_add($numerator, $add); # compensate if necessary
        }
        return $numerator;
    }

    protected function getMultiplier($decimals)
    {
        if (($multiplier = ($this::$multipliers[$decimals] ?? null)) !== null) return $multiplier;
        return $this::$multipliers[$decimals] = ($decimals <= 18) ? pow(10, $decimals) : gmp_pow(10, $decimals); # initialize new decimal multiplier, integer for <10E18 (64-bit)
    }

    public function __debugInfo()
    {
        $out = [
            'value' => $this->toString(),
            'numerator' => gmp_strval($this->numerator, 10),
            'denominator' => gmp_strval($this->denominator, 10),
            'maxDecimals' => $this->maxDecimals,
            'rounding' => $this->rounding,
            'autoRounding' => $this->autoRounding ? 'Y' : 'N',
            'autoCompand' => $this->autoCompand ? 'Y' : 'N',
        ];

        switch ($this->rounding) {
            case $this::ROUND_MATH: $out['rounding'] = 'MATH'; break;
            case $this::ROUND_TRUNC: $out['rounding'] = 'TRUNC'; break;
            case $this::ROUND_FLOOR: $out['rounding'] = 'FLOOR'; break;
            case $this::ROUND_CEIL: $out['rounding'] = 'CEIL'; break;
            case $this::ROUND_SPREAD: $out['rounding'] = 'SPREAD'; break;
        }

        return $out;
    }
}
