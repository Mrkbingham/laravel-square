<?php

namespace Nikolag\Square\Traits;

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
        return MorphAliases::for(static::class) ?? parent::getMorphClass();
    }
}
