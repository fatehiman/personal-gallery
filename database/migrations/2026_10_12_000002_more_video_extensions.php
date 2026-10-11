<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Old video formats that are now known (they are converted for the browser): files already listed become videos. */
    public function up(): void
    {
        DB::table('media')->where('type', 'other')
            ->whereIn('ext', ['3g2', 'ogg', 'vob', 'mod', 'tod', 'divx', 'asf', 'f4v', 'rm', 'rmvb', 'dv'])
            ->update(['type' => 'video', 'scanned_at' => null, 'scan_error' => null]);
    }

    public function down(): void {}
};
