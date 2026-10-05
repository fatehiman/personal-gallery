<?php

namespace App\Gallery;

use App\Models\User;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Throwable;

/** Profile pictures: uploads, and free avatar services looked up by e-mail. */
class Avatars
{
    private const SIZE = 256;

    /** Save an uploaded image (re-encoded, so no foreign bytes are kept). */
    public function storeUpload(User $user, UploadedFile $file): bool
    {
        $img = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));
        if (! $img) {
            return false;
        }
        $this->save($user, $img, 'upload');

        return true;
    }

    public function remove(User $user): void
    {
        @unlink($user->avatarFile());
        $user->forceFill(['avatar_source' => null, 'avatar_v' => $user->avatar_v + 1])->save();
    }

    /**
     * When the user has no uploaded picture, try to find one by e-mail.
     * Total time is limited to about 10 seconds.
     */
    public function fetchIfMissing(User $user): void
    {
        if ($user->avatar_source === 'upload' && is_file($user->avatarFile())) {
            return;
        }
        $email = strtolower(trim((string) $user->email));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            if ($user->avatar_source && $user->avatar_source !== 'upload') {
                $this->remove($user);
            }

            return;
        }
        $deadline = microtime(true) + 9.5;
        $sources = [
            'gravatar' => 'https://www.gravatar.com/avatar/'.hash('sha256', $email).'?s='.self::SIZE.'&d=404',
            'libravatar' => 'https://seccdn.libravatar.org/avatar/'.hash('sha256', $email).'?s='.self::SIZE.'&d=404',
            'unavatar' => 'https://unavatar.io/'.rawurlencode($email).'?fallback=false',
        ];
        foreach ($sources as $name => $url) {
            $left = $deadline - microtime(true);
            if ($left < 1) {
                break;
            }
            try {
                $r = Http::timeout(min(4, $left))->connectTimeout(min(3, $left))->withHeaders(['User-Agent' => 'PersonalGallery/1.0'])->get($url);
                if ($r->status() === 200 && str_starts_with((string) $r->header('Content-Type'), 'image/') && strlen($r->body()) < 5_000_000) {
                    $img = @imagecreatefromstring($r->body());
                    if ($img) {
                        $this->save($user, $img, $name);

                        return;
                    }
                }
            } catch (Throwable) {
                // try the next service
            }
        }
        if ($user->avatar_source && $user->avatar_source !== 'upload') {
            $this->remove($user); // old picture belonged to an older e-mail
        }
    }

    private function save(User $user, GdImage $img, string $source): void
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $side = min($w, $h);
        $dst = imagecreatetruecolor(self::SIZE, self::SIZE);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $img, 0, 0, (int) (($w - $side) / 2), (int) (($h - $side) / 2), self::SIZE, self::SIZE, $side, $side);
        $file = $user->avatarFile();
        if (! is_dir(dirname($file))) {
            @mkdir(dirname($file), 0775, true);
        }
        imagewebp($dst, $file, 82);
        $user->forceFill(['avatar_source' => $source, 'avatar_v' => $user->avatar_v + 1])->save();
    }
}
