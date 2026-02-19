<?php

declare(strict_types=1);

namespace Dev\FileBundle\NamingStrategy;

use Dev\FileBundle\Exception\InvalidArgumentException;

class NamingStrategyFactory
{
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
            throw new InvalidArgumentException(\sprintf(
                "Naming Strategy class '%s' must implement '%s'",
                $class,
                NamingStrategyInterface::class
            ));
        }

        return self::$factories[$class] = new $class();
    }
}
