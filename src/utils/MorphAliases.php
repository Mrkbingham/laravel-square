<?php

namespace Nikolag\Square\Utils;

/**
 * Registry of morph aliases the host application assigns to vendor models.
 *
 * A morph map maps each alias to exactly one class, so a vendor class that
 * shares an alias with a host subclass cannot be found there by reverse lookup.
 */
final class MorphAliases
{
    /**
     * @var array<class-string, string>
     */
    private static array $aliases = [];

    /**
     * Register aliases, merging them over any already registered.
     *
     * @param array<class-string, string> $classToAlias
     *
     * @return void
     */
    public static function register(array $classToAlias): void
    {
        self::$aliases = array_merge(self::$aliases, $classToAlias);
    }

    /**
     * The alias registered for a class, if any.
     *
     * @param string $class
     *
     * @return string|null
     */
    public static function for(string $class): ?string
    {
        return self::$aliases[$class] ?? null;
    }

    /**
     * Forget every registered alias.
     *
     * @return void
     */
    public static function flush(): void
    {
        self::$aliases = [];
    }
}
