<?php

namespace Tests\Feature;

use App\Gallery\Indexer;
use App\Gallery\Scanner;
use App\Gallery\Settings;
use App\Gallery\VideoCache;
use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class VideoConvertTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/pg-vconv-'.uniqid();
        mkdir($this->root.'/V', 0777, true);
        config(['gallery.root' => $this->root, 'gallery.thumb_dir' => $this->root.'-thumbs', 'gallery.url_key' => 'test',
            'gallery.video_cache_dir' => $this->root.'-vcache', 'gallery.video_reserve_mb' => 0, 'gallery.video_spawn' => false]);
        Settings::reset();
        $this->admin = User::create(['name' => 'A', 'username' => 'a'.uniqid(), 'password' => 'password123', 'is_admin' => true]);
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

    /** A 2-second test video made by ffmpeg (or random bytes when $args is null). */
    private function media(string $name, ?array $args = null): Media
    {
        $file = $this->root.'/V/'.$name;
        if ($args === null) {
            file_put_contents($file, random_bytes(2 * 1024 * 1024));
        } else {
            if (! Scanner::ffmpegAvailable()) {
                $this->markTestSkipped('ffmpeg is not installed');
            }
            $p = new Process(array_merge([config('gallery.ffmpeg'), '-v', 'error', '-y', '-f', 'lavfi', '-i', 'testsrc=duration=2:size=320x240:rate=25',
                '-f', 'lavfi', '-i', 'sine=frequency=440:duration=2'], $args, ['-shortest', $file]));
            $p->setTimeout(60)->mustRun();
        }
        app(Indexer::class)->sync('V', true);

        return Media::where('filename', $name)->firstOrFail();
    }

    private function codec(string $file): string
    {
        $p = new Process([config('gallery.ffprobe'), '-v', 'quiet', '-select_streams', 'v:0', '-show_entries', 'stream=codec_name', '-of', 'csv=p=0', $file]);

        return trim($p->mustRun()->getOutput());
    }

    public function test_which_videos_need_a_conversion(): void
    {
        $mp4 = $this->media('a.mp4');
        $mkv = $this->media('b.mkv');
        $this->assertFalse(VideoCache::needsConvert($mp4));
        $this->assertTrue(VideoCache::needsConvert($mkv));
        Media::whereKey($mp4->id)->update(['video_codec' => 'mpeg4']);
        $this->assertTrue(VideoCache::needsConvert($mp4->refresh()));
        Media::whereKey($mp4->id)->update(['video_codec' => 'hevc']);
        $this->assertFalse(VideoCache::needsConvert($mp4->refresh()));

        // the list says it is playable (it will be converted)
        $files = collect($this->actingAs($this->admin)->getJson('/api/list?path=V')->json('files'))->keyBy('name');
        $this->assertTrue($files['b.mkv']['playable']);
    }

    public function test_old_format_is_copied_then_encoded_and_the_copy_is_deleted(): void
    {
        $m = $this->media('old.avi', ['-c:v', 'mpeg4', '-c:a', 'pcm_s16le']);
        $this->actingAs($this->admin)->postJson("/api/media/{$m->id}/video?w=tok12345")->assertOk()->assertJsonPath('convert', true);

        $this->assertTrue(VideoCache::run($m));
        $ready = VideoCache::readyFile($m);
        $this->assertStringEndsWith('.web.mp4', $ready);
        $this->assertSame('h264', $this->codec($ready));
        $this->assertSame([basename($ready)], array_values(array_filter(array_map('basename', glob($this->root.'-vcache/'.$m->id.'-*') ?: []), fn ($f) => ! str_contains($f, '.w-') && ! str_ends_with($f, '.lock'))));

        $r = $this->actingAs($this->admin)->getJson("/api/media/{$m->id}/video")->assertJsonPath('state', 'ready')
            ->assertJsonPath('converted', true)->assertJsonPath('mime', 'video/mp4');
        $url = $r->json('url');
        $this->assertStringEndsWith('.mp4', $url);
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'video/mp4');
        $this->get(substr($url, 0, -3).'avi')->assertNotFound();
    }

    public function test_h264_in_another_container_is_only_repacked(): void
    {
        $m = $this->media('cam.mkv', ['-c:v', 'libx264', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p', '-c:a', 'aac']);
        $this->assertTrue(VideoCache::run($m));
        $ready = VideoCache::readyFile($m);
        $this->assertStringEndsWith('.web.mp4', $ready);
        $this->assertSame('h264', $this->codec($ready));
    }

    public function test_browser_failure_asks_for_a_conversion_of_the_existing_copy(): void
    {
        $m = $this->media('phone.mp4', ['-c:v', 'libx264', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p', '-c:a', 'aac']);
        $this->assertTrue(VideoCache::run($m));
        $this->assertStringEndsWith('.mp4', VideoCache::readyFile($m));
        $this->assertStringEndsNotWith('.web.mp4', VideoCache::readyFile($m));

        $this->actingAs($this->admin)->postJson("/api/media/{$m->id}/video", ['convert' => 1])->assertOk()
            ->assertJsonPath('state', 'none')->assertJsonPath('convert', true);
        $this->assertTrue(VideoCache::run($m)); // converts the copy that is already here (no new copy)
        $this->assertStringEndsWith('.web.mp4', VideoCache::readyFile($m));
        $this->assertFalse(is_file($this->root.'-vcache/'.$m->id.'-'.$m->thumb_v.'.mp4'));
    }

    public function test_space_for_the_converted_file_is_checked_before_the_copy(): void
    {
        $m = $this->media('long.mkv');
        Media::whereKey($m->id)->update(['duration' => 99_999_999, 'width' => 1920, 'height' => 1080, 'video_codec' => 'mpeg2video']);
        $m->refresh();
        $this->actingAs($this->admin)->postJson("/api/media/{$m->id}/video")->assertOk()
            ->assertJsonPath('state', 'error')->assertJsonPath('error', 'video_no_space');
        $this->assertSame([], glob($this->root.'-vcache/*.part') ?: []);
    }

    public function test_files_are_deleted_when_the_last_viewer_leaves(): void
    {
        $m = $this->media('clip.mp4');
        $this->actingAs($this->admin)->postJson("/api/media/{$m->id}/video?w=viewer0001")->assertOk();
        $this->assertTrue(VideoCache::run($m));
        $this->actingAs($this->admin)->getJson("/api/media/{$m->id}/video?w=viewer0002")->assertJsonPath('state', 'ready');

        // one viewer leaves, the other still watches: kept
        $this->actingAs($this->admin)->deleteJson("/api/media/{$m->id}/video?w=viewer0001")->assertOk();
        $this->assertFalse(VideoCache::drop($m));
        $this->assertNotNull(VideoCache::readyFile($m));

        // the last one leaves (page closed: beacon): deleted
        $this->actingAs($this->admin)->post("/api/media/{$m->id}/video/leave", ['w' => 'viewer0002'])->assertOk();
        $this->assertTrue(VideoCache::drop($m));
        $this->assertNull(VideoCache::readyFile($m));

        // a closed tab without a beacon: the viewer file expires, then the cleaner removes the copy
        $this->actingAs($this->admin)->postJson("/api/media/{$m->id}/video?w=viewer0003")->assertOk();
        $this->assertTrue(VideoCache::run($m));
        $this->assertSame(0, VideoCache::clean(2));
        foreach (glob($this->root.'-vcache/*') as $f) {
            touch($f, time() - 600);
        }
        $this->assertSame(1, VideoCache::clean(2));
        $this->assertNull(VideoCache::readyFile($m));
    }
}
