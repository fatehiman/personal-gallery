<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Indexes for the list, folderless and search queries (measured on a copy of the production table, 60,000 rows):
 *
 *  - media_hidden_at_index was harmful: only ~10 rows have hidden_at set, but for "hidden_at IS NULL" (the global
 *    scope on every query) MySQL chose it, read ALL rows through it and sorted them: 260-420 ms per query.
 *    It is replaced by (hidden_at, sort_at). "Deleted items" (hidden_at IS NOT NULL) still uses it (left prefix).
 *  - sort_at = COALESCE(taken_at, file_mtime), the date every list is sorted by. A VIRTUAL generated column with an
 *    index: the optimizer uses it by itself when a query has exactly this expression, so no query had to change.
 *    "Whole gallery, newest first" 259 ms -> 1 ms, a user with 2 folders 361 ms -> 1 ms.
 *  - path(191): a prefix index for "path LIKE 'folder/%'" (a TEXT column can not be indexed in full).
 *    A small sub folder 328 ms -> 12 ms. The same for directories.path.
 *
 * MySQL only (the tests use SQLite and do not need them). Every step is INPLACE with LOCK=NONE: writes of the
 * crawler continue, the whole migration takes a few seconds. Every step checks first, so it can be run again.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }
        if (! Schema::hasColumn('media', 'sort_at')) {
            DB::statement('ALTER TABLE media ADD COLUMN sort_at DATETIME GENERATED ALWAYS AS (COALESCE(taken_at, file_mtime)) VIRTUAL, ALGORITHM=INPLACE, LOCK=NONE');
        }
        if (! Schema::hasIndex('media', 'media_visible_sort')) {
            DB::statement('ALTER TABLE media ADD INDEX media_visible_sort (hidden_at, sort_at), ALGORITHM=INPLACE, LOCK=NONE');
        }
        if (! Schema::hasIndex('media', 'media_path_prefix')) {
            DB::statement('ALTER TABLE media ADD INDEX media_path_prefix (path(191)), ALGORITHM=INPLACE, LOCK=NONE');
        }
        if (! Schema::hasIndex('directories', 'directories_path_prefix')) {
            DB::statement('ALTER TABLE directories ADD INDEX directories_path_prefix (path(191)), ALGORITHM=INPLACE, LOCK=NONE');
        }
        // Last, so there is never a moment without a usable index on hidden_at.
        if (Schema::hasIndex('media', 'media_hidden_at_index')) {
            DB::statement('ALTER TABLE media DROP INDEX media_hidden_at_index, ALGORITHM=INPLACE, LOCK=NONE');
        }
        DB::statement('ANALYZE TABLE media, directories');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }
        if (! Schema::hasIndex('media', 'media_hidden_at_index')) {
            DB::statement('ALTER TABLE media ADD INDEX media_hidden_at_index (hidden_at), ALGORITHM=INPLACE, LOCK=NONE');
        }
        foreach (['media_visible_sort', 'media_path_prefix'] as $index) {
            if (Schema::hasIndex('media', $index)) {
                DB::statement("ALTER TABLE media DROP INDEX $index, ALGORITHM=INPLACE, LOCK=NONE");
            }
        }
        if (Schema::hasColumn('media', 'sort_at')) {
            DB::statement('ALTER TABLE media DROP COLUMN sort_at, ALGORITHM=INPLACE, LOCK=NONE');
        }
        if (Schema::hasIndex('directories', 'directories_path_prefix')) {
            DB::statement('ALTER TABLE directories DROP INDEX directories_path_prefix, ALGORITHM=INPLACE, LOCK=NONE');
        }
    }
};
