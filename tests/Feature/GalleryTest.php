<?php

namespace Tests\Feature;

use App\Gallery\InvalidPathException;
use App\Gallery\Paths;
use App\Gallery\Signer;
use App\Models\FolderAccess;
use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GalleryTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/pg-test-'.uniqid();
        foreach (['A/B', 'A/C', 'D'] as $d) {
            mkdir($this->root.'/'.$d, 0777, true);
        }
        $img = imagecreatetruecolor(800, 600);
        imagejpeg($img, $this->root.'/A/B/photo.jpg');
        imagejpeg($img, $this->root.'/D/other.jpg');
        config(['gallery.root' => $this->root, 'gallery.thumb_dir' => $this->root.'-thumbs', 'gallery.url_key' => 'test']);
    }

    protected function tearDown(): void
    {
        foreach ([$this->root, $this->root.'-thumbs'] as $dir) {
            if (is_dir($dir)) {
                $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($it as $f) {
                    $f->isDir() ? rmdir($f) : unlink($f);
                }
                rmdir($dir);
            }
        }
        parent::tearDown();
    }

    private function user(bool $admin = false): User
    {
        return User::create(['name' => 'U', 'username' => 'u'.uniqid(), 'password' => 'password123', 'is_admin' => $admin]);
    }

    public function test_paths_are_normalized_and_dot_dot_is_rejected(): void
    {
        $this->assertSame('a/b', Paths::normalize('/a//b/./'));
        $this->assertSame('a/b', Paths::normalize('a\\b'));
        $this->expectException(InvalidPathException::class);
        Paths::normalize('a/../../etc');
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/browse')->assertRedirect('/login');
        $this->get('/register')->assertNotFound();
    }

    public function test_user_sees_only_assigned_folders_by_title(): void
    {
        $u = $this->user();
        $f = FolderAccess::create(['user_id' => $u->id, 'title' => 'Holiday', 'path' => 'A/B']);
        $this->actingAs($u)->getJson('/api/list?path=')
            ->assertOk()->assertJsonPath('folders.0.name', 'Holiday')->assertJsonCount(1, 'folders');
        $this->actingAs($u)->getJson('/api/list?path='.$f->id)
            ->assertOk()->assertJsonPath('files.0.name', 'photo.jpg');
        $this->actingAs($u)->getJson('/api/list?path=D')->assertNotFound();
        $this->actingAs($u)->getJson('/api/list?path='.$f->id.'/../../D')->assertNotFound();
    }

    public function test_user_cannot_open_media_outside_their_folders(): void
    {
        $admin = $this->user(true);
        $this->actingAs($admin)->getJson('/api/list?path=D')->assertOk();
        $other = Media::where('filename', 'other.jpg')->firstOrFail();
        $u = $this->user();
        FolderAccess::create(['user_id' => $u->id, 'title' => 'X', 'path' => 'A']);
        $this->flushSession(); // new browser for the second user
        $this->actingAs($u)->getJson('/api/media/'.$other->id)->assertNotFound();
        $this->actingAs($u)->postJson('/api/media/'.$other->id.'/tags', ['name' => 'x'])->assertNotFound();
    }

    public function test_nested_folder_assignment_is_refused(): void
    {
        $admin = $this->user(true);
        $u = $this->user();
        $this->actingAs($admin)->post("/admin/users/{$u->id}/folders", ['title' => 'A', 'path' => 'A'])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post("/admin/users/{$u->id}/folders", ['title' => 'B', 'path' => 'A/B'])->assertSessionHasErrors('path');
        $this->assertSame(1, $u->folders()->count());

        $u2 = $this->user();
        $this->actingAs($admin)->post("/admin/users/{$u2->id}/folders", ['title' => 'B', 'path' => 'A/B'])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post("/admin/users/{$u2->id}/folders", ['title' => 'A', 'path' => 'A'])->assertSessionHasErrors('path');
    }

    public function test_normal_user_cannot_use_admin_api(): void
    {
        $u = $this->user();
        $this->actingAs($u)->getJson('/api/admin/scan')->assertForbidden();
        $this->actingAs($u)->get('/admin/users')->assertForbidden();
    }

    public function test_thumbnail_is_made_on_first_request_and_needs_a_valid_signature(): void
    {
        $admin = $this->user(true);
        $this->actingAs($admin)->getJson('/api/list?path=A/B')->assertOk();
        $m = Media::where('filename', 'photo.jpg')->firstOrFail();
        $this->get(Signer::thumb($m).'x')->assertNotFound();
        $r = $this->get(Signer::thumb($m));
        $r->assertOk();
        $this->assertStringContainsString('max-age=31536000', $r->headers->get('Cache-Control'));
        $this->assertEmpty($r->headers->getCookies());
        $m->refresh();
        $this->assertTrue($m->has_thumb);
        $this->assertSame(800, $m->width);
    }

    public function test_only_one_scan_job_at_a_time(): void
    {
        $admin = $this->user(true);
        $this->actingAs($admin)->postJson('/api/admin/scan', ['path' => 'A'])->assertOk();
        $this->actingAs($admin)->postJson('/api/admin/scan', ['path' => 'D'])->assertStatus(409);
        $this->actingAs($admin)->postJson('/api/admin/scan/stop')->assertOk()->assertJsonPath('active', null);
        $this->actingAs($admin)->postJson('/api/admin/scan', ['path' => 'D'])->assertOk();
    }

    public function test_failed_logins_are_limited_and_fake_forwarded_ip_does_not_help(): void
    {
        User::create(['name' => 'V', 'username' => 'victim', 'password' => 'password123']);
        for ($i = 0; $i < 5; $i++) {
            $this->withHeader('X-Forwarded-For', "10.0.0.$i")->post('/login', ['username' => 'victim', 'password' => 'wrong'])
                ->assertSessionHasErrors('username');
        }
        // The 6th try, even with a new (fake) forwarded IP and the right password, is blocked.
        $this->withHeader('X-Forwarded-For', '10.0.0.99')->post('/login', ['username' => 'victim', 'password' => 'password123']);
        $this->assertGuest();
    }

    public function test_storage_outage_gives_a_clear_answer_and_deletes_nothing(): void
    {
        $admin = $this->user(true);
        $this->actingAs($admin)->getJson('/api/list?path=A')->assertOk();
        $this->actingAs($admin)->getJson('/api/list?path=A/B')->assertOk();
        $before = [\App\Models\Directory::count(), Media::count()];

        rename($this->root, $this->root.'-off'); // storage "disappears"
        \App\Gallery\Health::forget();
        try {
            $this->actingAs($admin)->getJson('/api/list?path=A')->assertOk(); // still browsable from the DB
            $this->actingAs($admin)->getJson('/api/list?path=A/C') // never listed: needs the storage
                ->assertStatus(503)->assertJsonPath('storage', 'down');
            $this->actingAs($admin)->getJson('/api/health?fresh=1')->assertJsonPath('storage', 'down');
            $m = Media::where('filename', 'photo.jpg')->firstOrFail();
            $this->get(Signer::thumb($m))->assertStatus(503); // not marked as a broken file
            $this->assertNull($m->refresh()->scan_error);
            $this->assertSame($before, [\App\Models\Directory::count(), Media::count()]);
        } finally {
            rename($this->root.'-off', $this->root);
            \App\Gallery\Health::forget();
        }
        $this->actingAs($admin)->getJson('/api/health?fresh=1')->assertJsonPath('storage', 'ok');
    }

    public function test_login_in_persian_saves_persian_but_english_does_not_change_it(): void
    {
        $u = User::create(['name' => 'P', 'username' => 'pp', 'password' => 'password123', 'locale' => 'fa']);
        $this->post('/login', ['username' => 'pp', 'password' => 'password123', 'lang' => 'en'])->assertRedirect();
        $this->assertSame('fa', $u->refresh()->locale);
        $this->post('/logout');
        $u->update(['locale' => 'en']);
        $this->post('/login', ['username' => 'pp', 'password' => 'password123', 'lang' => 'fa']);
        $this->assertSame('fa', $u->refresh()->locale);
    }
}
