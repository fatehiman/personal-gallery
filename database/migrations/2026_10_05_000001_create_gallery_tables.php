<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Folders that the admin gives to a normal user. "title" is what the user sees in the root.
        Schema::create('folder_accesses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 150);
            $table->string('path', 1000); // relative to GALLERY_ROOT, no leading/trailing slash
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        // Directories we have seen (from a listing). Used for folder search and cached listings.
        Schema::create('directories', function (Blueprint $table) {
            $table->id();
            $table->char('path_hash', 40)->unique();
            $table->text('path');
            $table->string('name', 500)->index();
            $table->foreignId('parent_id')->nullable()->index();
            $table->timestamp('mtime')->nullable();
            $table->unsignedInteger('dir_count')->nullable();
            $table->unsignedInteger('file_count')->nullable();
            $table->timestamp('listed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('directory_id')->constrained()->cascadeOnDelete();
            $table->char('path_hash', 40)->unique();
            $table->text('path');
            $table->string('filename', 500)->index();
            $table->string('ext', 16)->index();
            $table->string('type', 8)->index(); // image | video | other
            $table->string('mime', 100)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->timestamp('file_mtime')->nullable()->index();
            $table->timestamp('file_ctime')->nullable();

            // Filled by the scanner (reads file content).
            $table->timestamp('scanned_at')->nullable()->index();
            $table->string('scan_error', 255)->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedTinyInteger('orientation')->nullable(); // EXIF orientation 1..8
            $table->dateTime('taken_at')->nullable()->index();
            $table->string('camera_make', 100)->nullable();
            $table->string('camera_model', 150)->nullable();
            $table->string('lens', 150)->nullable();
            $table->string('exposure', 30)->nullable();
            $table->decimal('aperture', 5, 2)->nullable();
            $table->unsignedInteger('iso')->nullable();
            $table->decimal('focal_length', 7, 2)->nullable();
            $table->boolean('flash')->nullable();
            $table->string('software', 150)->nullable();
            $table->decimal('duration', 10, 2)->nullable(); // video seconds
            $table->string('video_codec', 40)->nullable();
            $table->decimal('gps_lat', 10, 7)->nullable();
            $table->decimal('gps_lng', 10, 7)->nullable();
            $table->decimal('gps_alt', 9, 2)->nullable();
            $table->string('geo_status', 10)->nullable()->index(); // pending | done | failed
            $table->string('city_en', 150)->nullable();
            $table->string('city_fa', 150)->nullable();
            $table->string('country_en', 100)->nullable();
            $table->string('country_fa', 100)->nullable();
            $table->string('country_code', 2)->nullable()->index();
            $table->json('exif')->nullable();

            // Thumbnail (WebP, stored in storage/app/thumbs).
            $table->boolean('has_thumb')->default(false)->index();
            $table->unsignedInteger('thumb_v')->default(1);
            $table->unsignedSmallInteger('thumb_w')->nullable();
            $table->unsignedSmallInteger('thumb_h')->nullable();

            // User data.
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('rotation')->default(0); // display rotation 0/90/180/270
            $table->timestamps();
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->timestamps();
        });

        Schema::create('media_tag', function (Blueprint $table) {
            $table->foreignId('media_id')->constrained('media')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->primary(['media_id', 'tag_id']);
        });

        Schema::create('persons', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150)->unique();
            $table->timestamps();
        });

        // A person on a photo, with the face rectangle (0..1, relative to the EXIF-oriented image).
        Schema::create('media_person', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_id')->constrained('media')->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('persons')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('x', 6, 5)->nullable();
            $table->decimal('y', 6, 5)->nullable();
            $table->decimal('w', 6, 5)->nullable();
            $table->decimal('h', 6, 5)->nullable();
            $table->string('source', 10)->default('manual'); // manual | ai (later)
            $table->timestamps();
        });

        Schema::create('favorites', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_id')->constrained('media')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->primary(['user_id', 'media_id']);
        });

        Schema::create('scan_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('path');
            $table->boolean('recursive')->default(true);
            $table->string('status', 10)->index(); // queued | running | done | stopped | failed
            $table->boolean('stop_requested')->default(false);
            $table->json('state')->nullable();
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('processed')->default(0);
            $table->unsignedInteger('errors')->default(0);
            $table->string('current_file', 500)->nullable();
            $table->string('message', 500)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        // Reverse geocoding cache, key = coordinates rounded to 2 decimals (~1 km).
        Schema::create('geo_cache', function (Blueprint $table) {
            $table->id();
            $table->string('geo_key', 24)->unique();
            $table->string('city_en', 150)->nullable();
            $table->string('city_fa', 150)->nullable();
            $table->string('country_en', 100)->nullable();
            $table->string('country_fa', 100)->nullable();
            $table->string('country_code', 2)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['geo_cache', 'scan_jobs', 'favorites', 'media_person', 'persons', 'media_tag', 'tags', 'media', 'directories', 'folder_accesses'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
