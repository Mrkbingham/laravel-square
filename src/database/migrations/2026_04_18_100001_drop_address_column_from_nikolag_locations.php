<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop the legacy JSON address column now that addresses are stored
     * in the nikolag_addresses table via polymorphic relationship.
     */
    public function up(): void
    {
        Schema::table('nikolag_locations', function (Blueprint $table) {
            $table->dropColumn('address');
        });
    }

    /**
     * Re-add the address column for rollback.
     */
    public function down(): void
    {
        Schema::table('nikolag_locations', function (Blueprint $table) {
            $table->json('address')->nullable()->after('name');
        });
    }
};
