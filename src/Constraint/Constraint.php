<?php

/*
 * This file is part of composer/semver.
 *
 * (c) Composer <https://github.com/composer>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace Composer\Semver\Constraint;

/**
 * Defines a constraint.
 */
class Constraint implements CompilableConstraintInterface
{
    /* operator integer values */
    const OP_EQ = 0;
    const OP_LT = 1;
    const OP_LE = 2;
    const OP_GT = 3;
    const OP_GE = 4;
    const OP_NE = 5;

    /**
     * Operator to integer translation table.
     *
     * @var array
     */
    private static $transOpStr = array(
        '=' => self::OP_EQ,
        '==' => self::OP_EQ,
        '<' => self::OP_LT,
        '<=' => self::OP_LE,
        '>' => self::OP_GT,
        '>=' => self::OP_GE,
        '<>' => self::OP_NE,
        '!=' => self::OP_NE,
    );

    /**
     * Integer to operator translation table.
     *
     * @var array
     */
    private static $transOpInt = array(
        self::OP_EQ => '==',
        self::OP_LT => '<',
        self::OP_LE => '<=',
        self::OP_GT => '>',
        self::OP_GE => '>=',
        self::OP_NE => '!=',
    );

    /** @var int */
    protected $operator;

    /** @var string */
    protected $version;

    /** @var string|null */
    protected $prettyString;

    /** @var Bound */
    protected $lowerBound;

    /** @var Bound */
    protected $upperBound;

    /**
     * @param ConstraintInterface $provider
     *
     * @return bool
     */
    public function matches(ConstraintInterface $provider)
    {
        if ($provider instanceof $this) {
            return $this->matchSpecific($provider);
        }

        // turn matching around to find a match
        return $provider->matches($this);
    }

    /**
     * @param string|null $prettyString
     */
    public function setPrettyString($prettyString)
    {
        $this->prettyString = $prettyString;
    }

    /**
     * @return string
     */
    public function getPrettyString()
    {
        if ($this->prettyString) {
            return $this->prettyString;
        }

        return $this->__toString();
    }

    /**
     * Get all supported comparison operators.
     *
     * @return array
     */
    public static function getSupportedOperators()
    {
        return array_keys(self::$transOpStr);
    }

    /**
     * Sets operator and version to compare with.
     *
     * @param string $operator
     * @param string $version
     *
     * @throws \InvalidArgumentException if invalid operator is given.
     */
    public function __construct($operator, $version)
    {
        if (!isset(self::$transOpStr[$operator])) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid operator "%s" given, expected one of: %s',
                $operator,
                implode(', ', self::getSupportedOperators())
            ));
        }

        $this->operator = self::$transOpStr[$operator];
        $this->version = $version;
    }

    /**
     * @param string $a
     * @param string $b
     * @param string $operator
     * @param bool   $compareBranches
     *
     * @throws \InvalidArgumentException if invalid operator is given.
     *
     * @return bool
     */
    public function versionCompare($a, $b, $operator, $compareBranches = false)
    {
        if (!isset(self::$transOpStr[$operator])) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid operator "%s" given, expected one of: %s',
                $operator,
                implode(', ', self::getSupportedOperators())
            ));
        }

        $aIsBranch = 'dev-' === substr($a, 0, 4);
        $bIsBranch = 'dev-' === substr($b, 0, 4);

        if ($aIsBranch && $bIsBranch) {
            return $operator === '==' && $a === $b;
        }

        // when branches are not comparable, we make sure dev branches never match anything
        if (!$compareBranches && ($aIsBranch || $bIsBranch)) {
            return false;
        }

        return \version_compare($a, $b, $operator);
    }

    public function compile($otherOperator) {
        $isBranch = $this->version[0] === 'd' && 'dev-' === substr($this->version, 0, 4);

        if (self::OP_NE === $this->operator) {
            if ($isBranch) {
                if ($otherOperator === self::OP_EQ) {
                    return 'false';
                }

                return 'true';
            }

            if ($otherOperator === self::OP_EQ) {
                return sprintf('!$b && \version_compare($v, %s, \'!=\')', \var_export($this->version, true));
            }

            return 'true';
        } elseif (self::OP_EQ === $this->operator) {
            if ($isBranch) {
                if (\in_array($otherOperator, array(self::OP_LT, self::OP_GT, self::OP_NE), true)) {
                    return 'false';
                }

                return sprintf('$b && $v === %s', \var_export($this->version, true));
            }

            if ($otherOperator === self::OP_NE) {
                return sprintf('(!$b && \version_compare($v, %s, \'!=\'))', \var_export($this->version, true));
            } elseif (\in_array($otherOperator, array(self::OP_LT, self::OP_GT), true)) {
                return 'false';
            }

            return sprintf('\version_compare($v, %s, \'==\')', \var_export($this->version, true));
        }

        if (\in_array($this->operator, array(self::OP_LT, self::OP_LE), true)) {
            if (\in_array($otherOperator, array(self::OP_LT, self::OP_LE), true)) {
                return 'true';
            }
        } elseif (\in_array($otherOperator, array(self::OP_GT, self::OP_GE), true)) {
            return 'true';
        }

        if ($otherOperator === self::OP_NE) {
            return 'true';
        }

        if ($isBranch) {
            return 'false';
        }

        $codeComparison = sprintf('\version_compare($v, %s, \'%s\')', \var_export($this->version, true), self::$transOpInt[$this->operator]);
        if ($this->operator === self::OP_LE) {
            if ($otherOperator === self::OP_GT) {
                return sprintf('!$b && \version_compare($v, %s, \'!=\') && ', \var_export($this->version, true)) . $codeComparison;
            }
        } elseif ($this->operator === self::OP_GE) {
            if ($otherOperator === self::OP_LT) {
                return sprintf('!$b && \version_compare($v, %s, \'!=\') && ', \var_export($this->version, true)) . $codeComparison;
            }
        }

        return sprintf('!$b && %s', $codeComparison);
    }

    /**
     * @param Constraint $provider
     * @param bool       $compareBranches
     *
     * @return bool
     */
    public function matchSpecific(Constraint $provider, $compareBranches = false)
    {
        $noEqualOp = str_replace('=', '', self::$transOpInt[$this->operator]);
        $providerNoEqualOp = str_replace('=', '', self::$transOpInt[$provider->operator]);

        $isEqualOp = self::OP_EQ === $this->operator;
        $isNonEqualOp = self::OP_NE === $this->operator;
        $isProviderEqualOp = self::OP_EQ === $provider->operator;
        $isProviderNonEqualOp = self::OP_NE === $provider->operator;

        // '!=' operator is match when other operator is not '==' operator or version is not match
        // these kinds of comparisons always have a solution
        if ($isNonEqualOp || $isProviderNonEqualOp) {
            return (!$isEqualOp && !$isProviderEqualOp)
                || $this->versionCompare($provider->version, $this->version, '!=', $compareBranches);
        }

        // an example for the condition is <= 2.0 & < 1.0
        // these kinds of comparisons always have a solution
        if ($this->operator !== self::OP_EQ && $noEqualOp === $providerNoEqualOp) {
            return true;
        }

        if ($this->versionCompare($provider->version, $this->version, self::$transOpInt[$this->operator], $compareBranches)) {
            // special case, e.g. require >= 1.0 and provide < 1.0
            // 1.0 >= 1.0 but 1.0 is outside of the provided interval
            return !(\version_compare($provider->version, $this->version, '==')
                && self::$transOpInt[$provider->operator] === $providerNoEqualOp
                && self::$transOpInt[$this->operator] !== $noEqualOp);
        }

        return false;
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return self::$transOpInt[$this->operator] . ' ' . $this->version;
    }

    /**
     * {@inheritDoc}
     */
    public function getLowerBound()
    {
        $this->extractBounds();

        return $this->lowerBound;
    }

    /**
     * {@inheritDoc}
     */
    public function getUpperBound()
    {
        $this->extractBounds();

        return $this->upperBound;
    }

    private function extractBounds()
    {
        if (null !== $this->lowerBound) {
            return;
        }

        // Branches
        if (strpos($this->version, 'dev-') === 0) {
            $this->lowerBound = Bound::zero();
            $this->upperBound = Bound::positiveInfinity();

            return;
        }

        switch ($this->operator) {
            case self::OP_EQ:
                $this->lowerBound = new Bound($this->version, true);
                $this->upperBound = new Bound($this->version, true);
                break;
            case self::OP_LT:
                $this->lowerBound = Bound::zero();
                $this->upperBound = new Bound($this->version, false);
                break;
            case self::OP_LE:
                $this->lowerBound = Bound::zero();
                $this->upperBound = new Bound($this->version, true);
                break;
            case self::OP_GT:
                $this->lowerBound = new Bound($this->version, false);
                $this->upperBound = Bound::positiveInfinity();
                break;
            case self::OP_GE:
                $this->lowerBound = new Bound($this->version, true);
                $this->upperBound = Bound::positiveInfinity();
                break;
            case self::OP_NE:
                $this->lowerBound = Bound::zero();
                $this->upperBound = Bound::positiveInfinity();
                break;
        }
    }
}
