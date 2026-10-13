<?php

namespace Tests\Feature;

use App\Gallery\Activity;
use App\Gallery\Crawler;
use App\Gallery\OtdFolders;
use App\Gallery\Settings;
use App\Gallery\TelegramDigest;
use App\Models\Directory;
use App\Models\FolderAccess;
use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class FeaturesTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/pg-feat-'.uniqid();
        foreach (['A/B/C', 'A/D', 'E'] as $d) {
            mkdir($this->root.'/'.$d, 0777, true);
        }
        $img = imagecreatetruecolor(100, 80);
        foreach (['A/B/C/deep.jpg', 'A/B/mid.jpg', 'A/top.jpg', 'E/other.jpg'] as $f) {
            imagejpeg($img, $this->root.'/'.$f);
        }
        config(['gallery.root' => $this->root, 'gallery.thumb_dir' => $this->root.'-thumbs', 'gallery.url_key' => 'test']);
        Settings::reset();
    }

    protected function tearDown(): void
    {
        foreach ([$this->root, $this->root.'-thumbs', $this->root.'-vcache'] as $dir) {
            if (is_dir($dir)) {
                $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($it as $f) {
                    $f->isDir() ? rmdir($f) : unlink($f);
                }
                rmdir($dir);
            }
        }
        Settings::reset();
        parent::tearDown();
    }

    private function user(bool $admin = false): User
    {
        return User::create(['name' => 'U', 'username' => 'u'.uniqid(), 'password' => 'password123', 'is_admin' => $admin]);
    }

    public function test_otd_flags_are_recursive_and_always_overwrite_children(): void
    {
        $u = $this->user();
        $f = OtdFolders::for($u);
        $this->assertFalse($f->effective('A/B'));

        $f->set('A', true);
        $this->assertTrue($f->effective('A/B/C'));
        $this->assertFalse($f->effective('E'));

        $f->set('A/B', false); // un-flag a sub folder: its children follow
        $this->assertTrue($f->effective('A'));
        $this->assertTrue($f->effective('A/D'));
        $this->assertFalse($f->effective('A/B'));
        $this->assertFalse($f->effective('A/B/C'));

        $f->set('A/B/C', true); // flag again deeper
        $this->assertTrue($f->effective('A/B/C'));
        $this->assertFalse($f->effective('A/B'));

        $f->set('A', false); // un-flag the parent: everything below is un-flagged, old flags are gone
        $this->assertFalse($f->effective('A/D'));
        $this->assertFalse($f->effective('A/B/C'));
        $this->assertSame(0, \DB::table('otd_folders')->count());

        $f->set('A/B/C', true);
        $f->set('A', true); // flag the parent: the old override below is overwritten
        $this->assertTrue($f->effective('A/B/C'));
        $this->assertSame(1, \DB::table('otd_folders')->count());
    }

    public function test_otd_scope_follows_the_nearest_flag(): void
    {
        $admin = $this->user(true);
        $this->actingAs($admin)->getJson('/api/folderless?path=')->assertOk();
        foreach (['A', 'A/B', 'A/B/C'] as $p) {
            $this->actingAs($admin)->getJson('/api/list?path='.$p)->assertOk();
        }
        $f = OtdFolders::for($admin);
        $names = fn () => $f->scope(Media::query(), 'media.path')->orderBy('filename')->pluck('filename')->all();

        $this->assertSame([], $names());
        $f->set('A', true);
        $f->set('A/B', false);
        $f->set('A/B/C', true);
        $this->assertSame(['deep.jpg', 'top.jpg'], $names());
    }

    public function test_on_this_day_is_empty_until_a_folder_is_flagged(): void
    {
        $admin = $this->user(true);
        $this->actingAs($admin)->getJson('/api/list?path=A')->assertOk();
        $today = Carbon::now($admin->tz());
        Media::where('filename', 'top.jpg')->update(['taken_at' => $today->copy()->subYears(2)->format('Y-m-d 10:00:00')]);

        $this->actingAs($admin)->getJson('/api/on-this-day')->assertOk()->assertJsonCount(0, 'files');
        $this->actingAs($admin)->postJson('/api/otd-folder', ['path' => 'A', 'on' => true])->assertOk()->assertJsonPath('on', true);
        $this->actingAs($admin)->getJson('/api/on-this-day')->assertJsonCount(1, 'files')->assertJsonPath('files.0.name', 'top.jpg');
        $this->actingAs($admin)->getJson('/api/list?path=A')->assertJsonPath('folders.0.otd', true);
        $this->actingAs($admin)->postJson('/api/otd-folder', ['path' => 'A', 'on' => false])->assertJsonPath('on', false);
        $this->actingAs($admin)->getJson('/api/on-this-day')->assertJsonCount(0, 'files');
    }

    public function test_user_can_flag_only_inside_their_folders(): void
    {
        $u = $this->user();
        $fa = FolderAccess::create(['user_id' => $u->id, 'title' => 'Mine', 'path' => 'A']);
        $this->actingAs($u)->postJson('/api/otd-folder', ['path' => $fa->id.'/B', 'on' => true])->assertOk();
        $this->assertSame('A/B', \DB::table('otd_folders')->where('user_id', $u->id)->value('path'));
        $this->actingAs($u)->postJson('/api/otd-folder', ['path' => '', 'on' => true])->assertStatus(422);
        $this->actingAs($u)->postJson('/api/otd-folder', ['path' => '99/B', 'on' => true])->assertNotFound();
    }

    public function test_folderless_lists_all_known_files_below_a_folder(): void
    {
        $admin = $this->user(true);
        $crawler = app(Crawler::class);
        $crawler->run(10);
        $r = $this->actingAs($admin)->getJson('/api/folderless?path=A/B')->assertOk();
        $this->assertEqualsCanonicalizing(['deep.jpg', 'mid.jpg'], array_column($r->json('files'), 'name'));
        $this->assertSame(0, $r->json('unlisted'));

        $u = $this->user();
        $fa = FolderAccess::create(['user_id' => $u->id, 'title' => 'Mine', 'path' => 'A']);
        $this->flushSession(); // new browser for the second user
        $r = $this->actingAs($u)->getJson('/api/folderless?path='.$fa->id)->assertOk();
        $this->assertCount(3, $r->json('files'));
        $this->actingAs($u)->getJson('/api/folderless?path=')->assertOk()->assertJsonCount(3, 'files'); // never E
    }

    public function test_crawler_walks_all_folders_scans_files_and_starts_again(): void
    {
        $this->assertSame(0, Media::whereNotNull('scanned_at')->count());
        app(Crawler::class)->run(15);
        $this->assertSame(4, Media::whereNotNull('scanned_at')->count());
        $this->assertSame(6, Directory::count()); // root, A, A/B, A/B/C, A/D, E
        $this->assertSame(1, Settings::int('crawl_cycles', 0));
        $this->assertSame('', (string) Settings::get(Crawler::POS, ''));

        // A new file in a folder that was already walked is found in the next round (the folder date changed).
        sleep(1);
        imagejpeg(imagecreatetruecolor(50, 50), $this->root.'/E/new.jpg');
        touch($this->root.'/E', time() + 5);
        app(Crawler::class)->run(15);
        $this->assertTrue((bool) Media::where('filename', 'new.jpg')->whereNotNull('scanned_at')->exists());
        $this->assertSame(2, Settings::int('crawl_cycles', 0));
    }

    public function test_crawler_finds_new_files_in_a_sub_folder_after_the_parent_was_listed(): void
    {
        app(Crawler::class)->run(15);
        $this->assertSame(1, Settings::int('crawl_cycles', 0));

        // New file in A/B (only the date of A/B changes), then the parent A is listed (a user opens it):
        // that listing also saves the new date of A/B. The crawler must still list A/B again.
        imagejpeg(imagecreatetruecolor(50, 50), $this->root.'/A/B/new.jpg');
        $newDate = time() + 5;
        touch($this->root.'/A/B', $newDate);
        app(\App\Gallery\Indexer::class)->sync('A', true);
        $this->assertSame($newDate, Directory::findByPath('A/B')->mtime->timestamp);

        app(Crawler::class)->run(15);
        $this->assertTrue((bool) Media::where('path', 'A/B/new.jpg')->whereNotNull('scanned_at')->exists());
    }

    public function test_crawler_lists_a_folder_again_after_some_days_to_find_overwritten_files(): void
    {
        app(Crawler::class)->run(15);
        $m = Media::where('path', 'A/top.jpg')->firstOrFail();
        [$oldSize, $oldV] = [(int) $m->size, (int) $m->thumb_v];
        $mtimeA = filemtime($this->root.'/A');

        file_put_contents($this->root.'/A/top.jpg', 'x', FILE_APPEND); // same name, new size, folder date unchanged
        touch($this->root.'/A', $mtimeA);
        app(Crawler::class)->run(15);
        $this->assertSame($oldSize, (int) $m->refresh()->size); // folder date unchanged: not listed

        Directory::where('path', 'A')->update(['listed_at' => now()->subDays(Crawler::RECHECK_DAYS + 1)]);
        app(Crawler::class)->run(15);
        $m->refresh();
        $this->assertSame($oldSize + 1, (int) $m->size);
        $this->assertSame($oldV + 1, (int) $m->thumb_v);
        $this->assertNotNull($m->scanned_at); // read again in the same run
    }

    public function test_long_video_reads_stop_at_the_deadline_or_when_a_user_starts_browsing(): void
    {
        $scanner = app(\App\Gallery\Scanner::class);
        $run = new \ReflectionMethod($scanner, 'runProcess');
        $set = function (?float $deadline, ?\Closure $abort) use ($scanner) {
            (new \ReflectionProperty($scanner, 'deadline'))->setValue($scanner, $deadline);
            (new \ReflectionProperty($scanner, 'abort'))->setValue($scanner, $abort);
        };
        $sleep = fn () => new \Symfony\Component\Process\Process([PHP_BINARY, '-r', 'sleep(20);']);

        $set(microtime(true) + 2, null);
        $t = microtime(true);
        try {
            $run->invoke($scanner, $sleep(), 60);
            $this->fail('no timeout');
        } catch (\Symfony\Component\Process\Exception\ProcessTimedOutException) {
        }
        $this->assertLessThan(6, microtime(true) - $t);

        $set(microtime(true) + 0.5, null); // less than 1 s left: do not start at all
        try {
            $run->invoke($scanner, $sleep(), 60);
            $this->fail('started without time');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('no time left', $e->getMessage());
        }

        $set(null, fn () => true);
        $t = microtime(true);
        $this->expectException(\App\Gallery\ScanAbortedException::class);
        try {
            $run->invoke($scanner, $sleep(), 60);
        } finally {
            $this->assertLessThan(6, microtime(true) - $t);
        }
    }

    public function test_telegram_upload_works_and_falls_back_to_a_document(): void
    {
        $admin = $this->user(true);
        $this->actingAs($admin)->getJson('/api/list?path=A')->assertOk();
        $day = Carbon::now(config('app.display_timezone'));
        Media::where('path', 'A/top.jpg')->update(['taken_at' => $day->copy()->subYears(3)->format('Y-m-d 12:00:00')]);
        OtdFolders::for($admin)->set('A', true);
        Settings::set('otd_min_years', 2);
        Settings::set('telegram_token', '123:abc');
        Settings::set('telegram_chat', '-100');
        \Illuminate\Support\Facades\Http::fake([
            'api.telegram.org/*/sendPhoto' => \Illuminate\Support\Facades\Http::response(['ok' => false], 400),
            'api.telegram.org/*' => \Illuminate\Support\Facades\Http::response(['ok' => true]),
        ]);

        $r = app(TelegramDigest::class)->send($day);
        $this->assertSame(['sent' => 1, 'failed' => 0, 'skipped' => 0], $r);
        \Illuminate\Support\Facades\Http::assertSent(fn ($req) => str_ends_with($req->url(), '/sendDocument'));
    }

    public function test_scan_page_does_not_count_files_and_only_the_admin_can_use_the_report(): void
    {
        $admin = $this->user(true);
        \DB::enableQueryLog();
        $this->actingAs($admin)->get('/admin/scans')->assertOk()
            ->assertSee('data-stats-load', false)->assertSee('data-has-report="0"', false)->assertSee(__('ui.stats_placeholder'));
        foreach (\DB::getQueryLog() as $q) {
            $this->assertDoesNotMatchRegularExpression('/from\s+["`]?media["`]?(\s|$)/i', $q['query'], 'the page must not count files');
        }
        $this->actingAs($admin)->getJson('/api/admin/scan/stats')->assertOk()->assertJsonPath('report', null);

        $this->flushSession();
        $user = $this->user();
        $this->actingAs($user)->getJson('/api/admin/scan/stats')->assertForbidden();
        $this->actingAs($user)->postJson('/api/admin/scan/stats')->assertForbidden();
        $this->assertSame(0, \DB::table('scan_reports')->count());
    }

    public function test_scan_report_is_saved_with_its_time_and_shown_again_without_counting(): void
    {
        $admin = $this->user(true);
        $made = $this->actingAs($admin)->postJson('/api/admin/scan/stats')->assertOk()->json('report');
        $this->assertSame(1, \DB::table('scan_reports')->count());
        $this->assertSame($admin->id, (int) \DB::table('scan_reports')->value('user_id'));
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $made['generated_at']);

        // The page knows there is a report (and shows no "not generated" text); reading it runs no count.
        $this->actingAs($admin)->get('/admin/scans')->assertOk()
            ->assertSee('data-has-report="1"', false)->assertDontSee(__('ui.stats_placeholder'));
        \DB::enableQueryLog();
        $this->actingAs($admin)->getJson('/api/admin/scan/stats')->assertOk()->assertJsonPath('report', $made);
        foreach (\DB::getQueryLog() as $q) {
            $this->assertDoesNotMatchRegularExpression('/from\s+["`]?media["`]?(\s|$)/i', $q['query']);
        }

        // A new report is added, and the newest one is shown. Only the last 100 are kept.
        \DB::table('scan_reports')->insert(array_map(
            fn ($i) => ['user_id' => null, 'data' => json_encode(['generated_at' => 'old']), 'created_at' => now()->subDays(5)],
            range(1, 104)
        ));
        $newest = $this->actingAs($admin)->postJson('/api/admin/scan/stats')->assertOk()->json('report');
        $this->assertSame(100, \DB::table('scan_reports')->count());
        $this->actingAs($admin)->getJson('/api/admin/scan/stats')->assertJsonPath('report', $newest);
    }

    public function test_scan_stats_count_only_what_is_in_the_database(): void
    {
        file_put_contents($this->root.'/E/notes.txt', 'x');
        app(Crawler::class)->run(15); // 4 photos read, 6 folders listed
        Media::where('path', 'A/top.jpg')->update(['hidden_at' => now()]);
        Media::where('path', 'E/other.jpg')->update(['scan_error' => 'broken']);
        Media::where('path', 'A/B/mid.jpg')->update(['gps_lat' => 1.5, 'gps_lng' => 2.5]);

        $admin = $this->user(true);
        $r = $this->actingAs($admin)->postJson('/api/admin/scan/stats')->assertOk();
        $r->assertJsonPath('report.folders', ['total' => 6, 'listed' => 6])
            ->assertJsonPath('report.files.total', 4)       // 3 visible photos + notes.txt; the hidden one is not counted
            ->assertJsonPath('report.files.hidden', 1)
            ->assertJsonPath('report.files.gps', 1)
            ->assertJsonPath('report.types.0.count', 3)     // image
            ->assertJsonPath('report.types.1.count', 0)     // video
            ->assertJsonPath('report.types.2.count', 1)     // other
            ->assertJsonPath('report.read', ['read' => 2, 'pending' => 0, 'errors' => 1]); // "other" files are never read
        $this->assertSame(['jpg' => 3, 'txt' => 1], collect($r->json('report.exts'))->pluck('count', 'ext')->all());
        $this->assertSame((int) Media::sum('size'), (int) $r->json('report.files.size'));
    }

    public function test_crawler_stops_when_time_is_over_and_continues_in_the_same_place(): void
    {
        app(Crawler::class)->run(0);
        $this->assertSame(0, Media::whereNotNull('scanned_at')->count());
        app(Crawler::class)->run(15);
        $this->assertSame(4, Media::whereNotNull('scanned_at')->count());
    }

    public function test_crawler_waits_for_an_active_admin_scan(): void
    {
        $admin = $this->user(true);
        $this->actingAs($admin)->postJson('/api/admin/scan', ['path' => 'A'])->assertOk();
        app(Crawler::class)->run(10);
        $this->assertSame(0, Directory::count());
    }

    public function test_settings_are_admin_only_and_the_token_is_stored_encrypted(): void
    {
        $this->actingAs($this->user())->get('/admin/settings')->assertForbidden();
        $admin = $this->user(true);
        $this->flushSession();
        $token = '123456789:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';
        $this->actingAs($admin)->post('/admin/settings', [
            'telegram_enabled' => 1, 'telegram_token' => $token, 'telegram_chat' => '-1001234567890',
            'otd_min_years' => 2, 'otd_max_images' => 7, 'otd_max_videos' => 3, 'crawl_seconds' => 20, 'crawl_enabled' => 1,
        ])->assertSessionHasNoErrors();
        Settings::reset();
        $this->assertSame($token, Settings::get('telegram_token'));
        $this->assertStringNotContainsString('AAAAAAAA', (string) \DB::table('settings')->where('key', 'telegram_token')->value('value'));
        $this->assertTrue(TelegramDigest::configured());
        $this->actingAs($admin)->get('/admin/settings')->assertOk()->assertDontSee($token, false);
        // empty token field keeps the saved token
        $this->actingAs($admin)->post('/admin/settings', [
            'telegram_chat' => '-1001234567890', 'otd_min_years' => 2, 'otd_max_images' => 7, 'otd_max_videos' => 3, 'crawl_seconds' => 20,
        ])->assertSessionHasNoErrors();
        Settings::reset();
        $this->assertSame($token, Settings::get('telegram_token'));
        $this->assertFalse(TelegramDigest::configured()); // not enabled any more
    }

    public function test_telegram_digest_picks_flagged_old_files_and_skips_big_videos(): void
    {
        $admin = $this->user(true);
        $this->actingAs($admin)->getJson('/api/list?path=A')->assertOk();
        $d = Directory::findByPath('A');
        $day = Carbon::now(config('app.display_timezone'));
        $mk = fn (string $name, string $type, int $size, int $yearsAgo) => Media::create([
            'directory_id' => $d->id, 'path_hash' => sha1('A/'.$name), 'path' => 'A/'.$name, 'filename' => $name, 'ext' => $type === 'video' ? 'mp4' : 'jpg',
            'type' => $type, 'size' => $size, 'taken_at' => $day->copy()->subYears($yearsAgo)->format('Y-m-d 12:00:00'),
        ]);
        $mk('old.jpg', 'image', 1000, 3);
        $mk('recent.jpg', 'image', 1000, 1);
        $mk('small.mp4', 'video', 1000, 3);
        $mk('big.mp4', 'video', 80 * 1024 * 1024, 3);
        Settings::set('otd_min_years', 2);
        Settings::set('otd_max_images', 10);
        Settings::set('otd_max_videos', 10);

        $this->assertTrue(app(TelegramDigest::class)->pick($day)['images']->isEmpty()); // nothing flagged
        OtdFolders::for($admin)->set('A', true);
        $p = app(TelegramDigest::class)->pick($day);
        $this->assertSame(['old.jpg'], $p['images']->pluck('filename')->all());
        $this->assertSame(['small.mp4'], $p['videos']->pluck('filename')->all());
    }

    public function test_user_time_zone_falls_back_to_the_project_zone(): void
    {
        $u = $this->user();
        $this->assertSame('Asia/Tehran', $u->tz());
        $u->update(['timezone' => 'Europe/Berlin']);
        $this->assertSame('Europe/Berlin', $u->refresh()->tz());
        $u->update(['timezone' => 'Nope/Zone']);
        $this->assertSame('Asia/Tehran', $u->refresh()->tz());
    }

    public function test_default_sort_is_newest_first_and_prefs_are_saved_on_the_user(): void
    {
        $u = $this->user();
        $this->actingAs($u)->postJson('/api/prefs', ['sort' => 'size', 'flat' => true])->assertOk();
        $this->assertSame('size', $u->refresh()->pref('sort'));
        $this->assertTrue($u->pref('flat'));
    }

    public function test_same_size_is_never_read_again_but_a_new_size_is(): void
    {
        $admin = $this->user(true);
        $this->actingAs($admin)->getJson('/api/list?path=E')->assertOk();
        $m = Media::where('filename', 'other.jpg')->firstOrFail();
        app(\App\Gallery\Scanner::class)->scan($m);
        $this->assertNotNull($m->refresh()->scanned_at);

        touch($this->root.'/E/other.jpg', time() + 100); // only the date changes
        app(\App\Gallery\Indexer::class)->sync('E', true);
        $m->refresh();
        $this->assertNotNull($m->scanned_at);
        $this->assertTrue($m->has_thumb);
        $this->assertSame(time() + 100, $m->file_mtime->timestamp);

        file_put_contents($this->root.'/E/other.jpg', 'x', FILE_APPEND); // size changes
        app(\App\Gallery\Indexer::class)->sync('E', true);
        $this->assertNull($m->refresh()->scanned_at);
    }

    public function test_hide_by_id_and_by_folder_is_immediate_and_respects_access(): void
    {
        $admin = $this->user(true);
        $this->actingAs($admin)->getJson('/api/list?path=A')->assertOk();
        $top = Media::where('filename', 'top.jpg')->firstOrFail();
        $other = Media::where('filename', 'other.jpg')->first();

        $u = $this->user();
        $fa = FolderAccess::create(['user_id' => $u->id, 'title' => 'Mine', 'path' => 'A']);
        $this->flushSession();
        $this->actingAs($u)->postJson('/api/media/hide', ['ids' => [$top->id]])->assertOk()->assertJsonPath('hidden', 1);
        $this->actingAs($u)->getJson('/api/list?path='.$fa->id)->assertJsonCount(0, 'files');
        $this->actingAs($u)->getJson('/api/media/'.$top->id)->assertNotFound();
        $this->assertSame($u->id, (int) \DB::table('media')->where('id', $top->id)->value('hidden_by'));
        // a relisting does not bring it back
        app(\App\Gallery\Indexer::class)->sync('A', true);
        $this->actingAs($u)->getJson('/api/list?path='.$fa->id)->assertJsonCount(0, 'files');
        $this->assertSame(1, \DB::table('media')->where('path', 'A/top.jpg')->count());

        // folder: also lists sub folders that were never read, then hides everything below
        $this->actingAs($u)->postJson('/api/media/hide', ['paths' => [$fa->id.'/B']])->assertOk()->assertJsonPath('hidden', 2);
        $this->assertSame(0, Media::where('path', 'like', 'A/B/%')->count());
        $this->actingAs($u)->postJson('/api/media/hide', ['paths' => ['']])->assertStatus(422);
        $this->actingAs($u)->postJson('/api/media/hide', ['paths' => ['77/B']])->assertNotFound();
        if ($other) {
            $this->actingAs($u)->postJson('/api/media/hide', ['ids' => [$other->id]])->assertJsonPath('hidden', 0);
        }
    }

    public function test_empty_folders_are_not_listed(): void
    {
        mkdir($this->root.'/Empty/Inner', 0777, true);
        mkdir($this->root.'/OnlyOther', 0777, true);
        file_put_contents($this->root.'/OnlyOther/notes.txt', 'x');
        $admin = $this->user(true);
        $r = $this->actingAs($admin)->getJson('/api/list?path=')->assertOk();
        $names = array_column($r->json('folders'), 'name');
        $this->assertContains('Empty', $names); // not listed yet: unknown, still shown
        $this->actingAs($admin)->getJson('/api/list?path=Empty')->assertOk();
        $this->actingAs($admin)->getJson('/api/list?path=Empty/Inner')->assertOk();
        $this->actingAs($admin)->getJson('/api/list?path=OnlyOther')->assertOk();
        $names = array_column($this->actingAs($admin)->getJson('/api/list?path=')->json('folders'), 'name');
        $this->assertNotContains('OnlyOther', $names);
        $this->assertContains('A', $names);
        $this->assertContains('Empty', $names); // has a sub folder (which is empty itself)
        $this->assertSame([], $this->actingAs($admin)->getJson('/api/list?path=Empty')->json('folders'));
    }

    public function test_crawler_writes_one_log_line_per_run_and_prunes_old_ones(): void
    {
        \DB::table('crawl_logs')->insert(['started_at' => now()->subDays(11), 'note' => 'old']);
        app(Crawler::class)->run(15);
        $this->assertSame(1, \DB::table('crawl_logs')->count());
        $log = \DB::table('crawl_logs')->first();
        $this->assertSame(4, (int) $log->new_files);
        $this->assertSame(4, (int) $log->scanned);
        $this->assertStringContainsString('round finished', $log->note);

        $admin = $this->user(true);
        $this->actingAs($admin)->get('/admin/scans')->assertOk()->assertSee('round finished');
    }

    public function test_crawler_waits_while_a_user_is_browsing_and_continues_after_5_minutes(): void
    {
        $admin = $this->user(true);
        $this->actingAs($admin)->getJson('/api/list?path=A')->assertOk(); // browsing = activity
        $this->assertTrue(Activity::recent());
        Directory::query()->delete();
        app(Crawler::class)->run(10);
        $this->assertSame(0, Media::whereNotNull('scanned_at')->count());
        $this->assertSame('paused: a user is browsing ('.$admin->username.')', \DB::table('crawl_logs')->value('note'));

        // Polls (storage retry, scan status), profile pictures and the admin pages (e.g. Scan jobs) are not activity.
        Settings::set(Activity::KEY, time() - 6 * 60);
        $this->actingAs($admin)->getJson('/api/health')->assertOk();
        $this->actingAs($admin)->getJson('/api/admin/scan')->assertOk();
        $this->actingAs($admin)->get('/admin/scans')->assertOk();
        $this->actingAs($admin)->get('/admin/settings')->assertOk();
        $this->actingAs($admin)->get('/admin/users')->assertOk();
        $this->assertFalse(Activity::recent());
        app(Crawler::class)->run(15);
        $this->assertSame(4, Media::whereNotNull('scanned_at')->count());
    }

    public function test_search_is_limited_to_the_folder_and_returns_its_breadcrumbs(): void
    {
        $admin = $this->user(true);
        app(Crawler::class)->run(15);
        $all = $this->actingAs($admin)->getJson('/api/search?q=jpg')->assertOk();
        $this->assertCount(4, $all->json('files'));
        $r = $this->actingAs($admin)->getJson('/api/search?q=jpg&in=A/B')->assertOk();
        $this->assertEqualsCanonicalizing(['deep.jpg', 'mid.jpg'], array_column($r->json('files'), 'name'));
        $this->assertSame('A/B', $r->json('inPath'));
        $this->assertSame(['A', 'B'], array_column($r->json('crumbs'), 'name'));

        $u = $this->user();
        $fa = FolderAccess::create(['user_id' => $u->id, 'title' => 'Mine', 'path' => 'A']);
        $this->flushSession();
        $r = $this->actingAs($u)->getJson('/api/search?q=jpg&in='.$fa->id)->assertOk();
        $this->assertCount(3, $r->json('files')); // never E
        $this->assertSame(['Mine'], array_column($r->json('crumbs'), 'name'));
    }

    private function videoSetup(): Media
    {
        file_put_contents($this->root.'/E/clip.mp4', random_bytes(3 * 1024 * 1024 + 123));
        config(['gallery.video_cache_dir' => $this->root.'-vcache', 'gallery.video_reserve_mb' => 0, 'gallery.video_spawn' => false]);
        $admin = $this->user(true);
        $this->actingAs($admin)->getJson('/api/list?path=E')->assertOk();

        return Media::where('filename', 'clip.mp4')->firstOrFail();
    }

    public function test_video_is_copied_to_the_local_folder_and_played_from_there(): void
    {
        $m = $this->videoSetup();
        $admin = User::where('is_admin', true)->first();
        $this->actingAs($admin)->getJson("/api/media/{$m->id}/video")->assertJsonPath('state', 'none');
        $this->actingAs($admin)->postJson("/api/media/{$m->id}/video")->assertOk()->assertJsonPath('total', $m->size);

        $this->assertTrue(\App\Gallery\VideoCache::run($m)); // the background process does this
        $r = $this->actingAs($admin)->getJson("/api/media/{$m->id}/video")->assertJsonPath('state', 'ready');
        $url = $r->json('url');
        $this->assertSame(file_get_contents($this->root.'/E/clip.mp4'), file_get_contents(\App\Gallery\VideoCache::readyFile($m)));
        $this->get($url)->assertOk();
        $this->get($url, ['Range' => 'bytes=0-99'])->assertStatus(206);
        $this->get($url.'x')->assertNotFound();

        // not used for a while: deleted
        touch(\App\Gallery\VideoCache::readyFile($m), time() - 3600);
        $this->assertSame(1, \App\Gallery\VideoCache::clean(15));
        $this->get($url)->assertNotFound();
        $this->actingAs($admin)->getJson("/api/media/{$m->id}/video")->assertJsonPath('state', 'none');
    }

    public function test_video_is_not_copied_without_free_disk_space(): void
    {
        $m = $this->videoSetup();
        config(['gallery.video_reserve_mb' => 999_999_999]); // more than any disk has
        $admin = User::where('is_admin', true)->first();
        $this->actingAs($admin)->postJson("/api/media/{$m->id}/video")->assertOk()
            ->assertJsonPath('state', 'error')->assertJsonPath('error', 'video_no_space');
        $this->assertFalse(\App\Gallery\VideoCache::run($m));
        $this->assertNull(\App\Gallery\VideoCache::readyFile($m));
        $this->assertSame([], glob($this->root.'-vcache/*.part') ?: []);
    }

    public function test_video_copy_can_be_cancelled_and_needs_access(): void
    {
        $m = $this->videoSetup();
        $u = $this->user();
        FolderAccess::create(['user_id' => $u->id, 'title' => 'A only', 'path' => 'A']);
        $this->flushSession();
        $this->actingAs($u)->postJson("/api/media/{$m->id}/video")->assertNotFound();
        $this->actingAs($u)->getJson('/m/v/'.$m->id.'/1/aaaaaaaaaaaaaaaaaaaaaa.mp4')->assertNotFound();
    }

    public function test_any_user_with_access_can_set_the_folder_image(): void
    {
        $admin = $this->user(true);
        $this->actingAs($admin)->getJson('/api/list?path=A/B')->assertOk();
        $this->actingAs($admin)->getJson('/api/list?path=A')->assertOk();
        $mid = Media::where('filename', 'mid.jpg')->firstOrFail();
        $cover = fn ($u, $path, $folder) => collect($this->actingAs($u)->getJson('/api/list?path='.$path)->json('folders'))->firstWhere('name', $folder)['cover'];

        $this->actingAs($admin)->postJson("/api/media/{$mid->id}/cover")->assertOk();
        $this->assertSame(\App\Gallery\Signer::thumb($mid->refresh()), $cover($admin, 'A', 'B'));

        $u = $this->user();
        $fa = FolderAccess::create(['user_id' => $u->id, 'title' => 'Mine', 'path' => 'A']);
        $u2 = $this->user();
        $this->flushSession();
        $this->actingAs($u)->postJson("/api/media/{$mid->id}/cover")->assertOk(); // overwrite by another user: same image
        $this->assertSame(\App\Gallery\Signer::thumb($mid), $cover($u, (string) $fa->id, 'B'));
        $this->flushSession();
        $this->actingAs($u2)->postJson("/api/media/{$mid->id}/cover")->assertNotFound(); // no access
        $this->assertSame(1, \DB::table('folder_covers')->count());

        // hidden cover: the folder falls back to a normal picture
        $this->flushSession();
        $this->actingAs($u)->postJson('/api/media/hide', ['ids' => [$mid->id]])->assertOk();
        $this->assertNotSame(\App\Gallery\Signer::thumb($mid), $cover($u, (string) $fa->id, 'B'));
        $this->actingAs($u)->postJson("/api/media/{$mid->id}/cover")->assertNotFound();
    }

    public function test_admin_sees_deleted_items_in_the_real_structure_and_can_restore(): void
    {
        $admin = $this->user(true);
        foreach (['A', 'A/B'] as $p) {
            $this->actingAs($admin)->getJson('/api/list?path='.$p)->assertOk();
        }
        $mid = Media::where('filename', 'mid.jpg')->firstOrFail();
        $top = Media::where('filename', 'top.jpg')->firstOrFail();
        $oldThumb = \App\Gallery\Signer::thumb($mid);
        $u = $this->user();
        $fa = FolderAccess::create(['user_id' => $u->id, 'title' => 'Alias', 'path' => 'A']);
        $this->flushSession();
        $this->actingAs($u)->postJson('/api/media/hide', ['ids' => [$mid->id, $top->id]])->assertOk();
        $this->actingAs($u)->getJson('/api/list?path=~deleted')->assertNotFound();
        $this->actingAs($u)->postJson('/api/admin/restore', ['ids' => [$mid->id]])->assertForbidden();

        $this->flushSession();
        $root = $this->actingAs($admin)->getJson('/api/list?path=')->assertOk();
        $del = collect($root->json('folders'))->firstWhere('special', 'deleted');
        $this->assertSame(2, $del['count']);
        $this->assertSame('~deleted', $del['path']);
        $top1 = $this->actingAs($admin)->getJson('/api/list?path=~deleted')->assertOk();
        $this->assertSame(['A'], array_column($top1->json('folders'), 'name'));
        $this->assertSame('Deleted items', $top1->json('crumbs.0.name'));
        $a = $this->actingAs($admin)->getJson('/api/list?path=~deleted/A')->assertOk();
        $this->assertSame(['top.jpg'], array_column($a->json('files'), 'name'));
        $this->assertSame(['B'], array_column($a->json('folders'), 'name'));
        $b = $this->actingAs($admin)->getJson('/api/list?path=~deleted/A/B')->assertOk();
        $this->assertSame(['mid.jpg'], array_column($b->json('files'), 'name'));
        $this->assertSame(1, $this->actingAs($admin)->getJson('/api/folderless?path=~deleted/A/B')->json('files.0.hidden') ? 1 : 0);

        // admin can see the deleted file's thumbnail with the new link; the old public link no longer works
        $thumb = $b->json('files.0.thumb');
        $this->assertNotSame($oldThumb, $thumb);
        $this->get($thumb)->assertOk();
        $this->get($oldThumb)->assertNotFound();
        $this->actingAs($admin)->getJson('/api/media/'.$mid->id)->assertOk();

        // restore one folder by path, the other file by id
        $this->actingAs($admin)->postJson('/api/admin/restore', ['paths' => ['~deleted/A/B']])->assertOk()->assertJsonPath('restored', 1);
        $this->actingAs($admin)->postJson('/api/admin/restore', ['ids' => [$top->id]])->assertOk()->assertJsonPath('restored', 1);
        $this->actingAs($admin)->postJson('/api/admin/restore', ['paths' => ['~deleted']])->assertStatus(422);
        $this->assertSame(0, Media::withoutGlobalScopes()->whereNotNull('hidden_at')->count());
        $this->flushSession();
        $this->actingAs($u)->getJson('/api/list?path='.$fa->id.'/B')->assertOk()->assertJsonPath('files.0.name', 'mid.jpg');
    }
}
