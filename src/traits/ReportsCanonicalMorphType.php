<?php

namespace Nikolag\Square\Traits;

use Illuminate\Database\ClassMorphViolationException;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\Relations\Relation;
use Nikolag\Square\Utils\MorphAliases;

trait ReportsCanonicalMorphType
{
    /**
     * Vendor instances must report the host app's alias for polymorphic type columns: the
     * morph map maps one class per alias, so a vendor class sharing an alias with a host
     * subclass is invisible to its reverse lookup. Otherwise this is Laravel's resolution.
     *
     * @return string
     */
    public function getMorphClass()
    {
        $alias = MorphAliases::for(static::class);

        if ($alias !== null) {
            return $alias;
        }

        $morphMap = Relation::morphMap();

        if (!empty($morphMap) && in_array(static::class, $morphMap)) {
            return array_search(static::class, $morphMap, true);
        }

        if (static::class === Pivot::class) {
            return static::class;
        }

        if (Relation::requiresMorphMap()) {
            throw new ClassMorphViolationException($this);
        }

        return static::class;
    }
}
