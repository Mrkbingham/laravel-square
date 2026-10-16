<?php

namespace Nikolag\Square\Utils;

use Illuminate\Database\Eloquent\Relations\Relation;
use InvalidArgumentException;

/**
 * Registry of morph aliases the host application assigns to vendor models.
 *
 * A morph map maps each alias to exactly one class, so a vendor class that
 * shares an alias with a host subclass cannot be found there by reverse lookup.
 *
 * Registering an alias changes the value every vendor relation writes and filters
 * on. Rows already stored with the class name stop matching, so the host must
 * rewrite those type columns to the alias in the same deploy that registers it.
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
     * Each alias must already resolve through the morph map to the class or a
     * subclass of it, so a typo fails here rather than on the first morphTo read.
     *
     * @param array<class-string, string> $classToAlias
     *
     * @throws InvalidArgumentException
     *
     * @return void
     */
    public static function register(array $classToAlias): void
    {
        foreach ($classToAlias as $class => $alias) {
            $mapped = Relation::getMorphedModel($alias);

            if ($mapped === null || !is_a($mapped, $class, true)) {
                throw new InvalidArgumentException("Morph alias [{$alias}] for [{$class}] must map to that class or a subclass of it in the morph map.");
            }
        }

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
     * The value a class writes to polymorphic type columns.
     *
     * A registered alias resolves without building a model; anything else defers
     * to the class's own getMorphClass().
     *
     * @param class-string $class
     *
     * @return string
     */
    public static function morphTypeOf(string $class): string
    {
        return self::for(ltrim($class, '\\')) ?? (new $class())->getMorphClass();
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
