<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The folder date (unix time) at the moment of the folder's OWN last listing. Only Indexer::sync() writes it.
        // The crawler compares the real folder date with it. "mtime" can not be used for that: the listing of the
        // parent folder also updates it, so a changed sub folder looked unchanged. Empty = list once again.
        Schema::table('directories', function (Blueprint $table) {
            $table->unsignedBigInteger('listed_mtime')->nullable()->after('listed_at');
        });
    }

    public function down(): void
    {
        Schema::table('directories', function (Blueprint $table) {
            $table->dropColumn('listed_mtime');
        });
    }
};
