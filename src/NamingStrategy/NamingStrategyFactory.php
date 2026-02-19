<?php

declare(strict_types=1);

/*
 * This file is part of the ChamberOrchestra package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ChamberOrchestra\FileBundle\NamingStrategy;

use ChamberOrchestra\FileBundle\Exception\InvalidArgumentException;

class NamingStrategyFactory
{
    /** @var array<string, NamingStrategyInterface> */
    private static array $factories = [];

    public static function create(string $class): NamingStrategyInterface
    {
        if (isset(self::$factories[$class])) {
            return self::$factories[$class];
        }

        if (!\class_exists($class)) {
            throw new InvalidArgumentException(\sprintf("Naming Strategy class '%s' does not exist", $class));
        }

        if (!\is_subclass_of($class, NamingStrategyInterface::class)) {
            throw new InvalidArgumentException(\sprintf("Naming Strategy class '%s' must implement '%s'", $class, NamingStrategyInterface::class));
        }

        /** @var NamingStrategyInterface $strategy */
        $strategy = new $class();

        return self::$factories[$class] = $strategy;
    }

    public static function reset(): void
    {
        self::$factories = [];
    }
}
