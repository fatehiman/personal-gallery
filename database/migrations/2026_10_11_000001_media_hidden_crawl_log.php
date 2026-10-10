<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // "Deleted" by a user = hidden for everybody. The file on the storage box is not touched.
        // (Later: the main admin may delete hidden files for real.)
        Schema::table('media', function (Blueprint $table) {
            $table->timestamp('hidden_at')->nullable()->index();
            $table->unsignedBigInteger('hidden_by')->nullable();
        });

        // One line per run of the background scan (kept 10 days).
        Schema::create('crawl_logs', function (Blueprint $table) {
            $table->id();
            $table->timestamp('started_at')->index();
            $table->decimal('seconds', 6, 1)->default(0);
            $table->text('from_path')->nullable();
            $table->text('to_path')->nullable();
            $table->unsignedInteger('folders')->default(0);   // folders checked
            $table->unsignedInteger('listed')->default(0);    // folders read again (date changed or never listed)
            $table->unsignedInteger('new_files')->default(0); // new files found by the listings
            $table->unsignedInteger('scanned')->default(0);   // files read (thumbnail + info)
            $table->unsignedInteger('errors')->default(0);
            $table->string('note', 150)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crawl_logs');
        Schema::table('media', fn (Blueprint $t) => $t->dropColumn(['hidden_at', 'hidden_by']));
    }
};
