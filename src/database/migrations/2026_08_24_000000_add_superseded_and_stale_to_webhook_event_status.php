<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    /**
     * The status values the column carried before this migration.
     *
     * @var array<int, string>
     */
    private const ORIGINAL_STATUSES = ['pending', 'processed', 'failed'];

    /**
     * The status values the column carries after this migration.
     *
     * @var array<int, string>
     */
    private const WIDENED_STATUSES = ['pending', 'processed', 'failed', 'superseded', 'stale'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('nikolag_webhook_events', function (Blueprint $table) {
            $table->enum('status', self::WIDENED_STATUSES)->default('pending')->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * Rows must leave the doomed values before the column stops accepting them. Both
     * new statuses mean "no processor will consume this", which is what processed
     * already meant to every reader of the narrower vocabulary.
     */
    public function down(): void
    {
        DB::table('nikolag_webhook_events')
            ->whereIn('status', array_diff(self::WIDENED_STATUSES, self::ORIGINAL_STATUSES))
            ->update(['status' => 'processed']);

        Schema::table('nikolag_webhook_events', function (Blueprint $table) {
            $table->enum('status', self::ORIGINAL_STATUSES)->default('pending')->change();
        });
    }
};
