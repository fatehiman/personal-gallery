<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Small key/value store for admin settings (Telegram, background crawler, crawler position).
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 64)->primary();
            $table->text('value')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        // "On this day" folder flags. One row = an explicit choice for a folder (real path) and its children.
        // The nearest row above a folder (or on it) decides. No row at all = not flagged.
        Schema::create('otd_folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('path_hash', 40);
            $table->text('path');
            $table->boolean('flagged');
            $table->unique(['user_id', 'path_hash']);
        });

        // Null = use the project time zone (GALLERY_TZ, Asia/Tehran by default).
        Schema::table('users', function (Blueprint $table) {
            $table->string('timezone', 64)->nullable()->after('calendar');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('timezone'));
        Schema::dropIfExists('otd_folders');
        Schema::dropIfExists('settings');
    }
};
