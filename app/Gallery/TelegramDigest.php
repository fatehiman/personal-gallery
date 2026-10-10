<?php

namespace App\Gallery;

use App\Models\Media;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Sends the "On this day" photos and videos of the admins' flagged folders to a Telegram group or channel.
 * Files are read from the storage box one by one, through the read slots. The bot API accepts uploads up to
 * 50 MB (photos up to 10 MB; bigger photos go as a document). Larger videos are skipped.
 */
class TelegramDigest
{
    public const MAX_UPLOAD = 50 * 1024 * 1024;

    public const MAX_PHOTO = 10 * 1024 * 1024;

    public static function configured(): bool
    {
        return Settings::bool('telegram_enabled') && Settings::get('telegram_token') && Settings::get('telegram_chat');
    }

    /** @return array{images: \Illuminate\Support\Collection, videos: \Illuminate\Support\Collection} */
    public function pick(?Carbon $today = null): array
    {
        $today ??= Carbon::now(config('app.display_timezone'));
        $minYears = max(1, Settings::int('otd_min_years', 1));
        $out = ['images' => collect(), 'videos' => collect()];
        $limits = ['images' => max(0, Settings::int('otd_max_images', 5)), 'videos' => max(0, Settings::int('otd_max_videos', 2))];
        $admins = User::where('is_admin', true)->where('is_active', true)->get();

        foreach (['images' => 'image', 'videos' => 'video'] as $key => $type) {
            if ($limits[$key] === 0) {
                continue;
            }
            $ids = [];
            foreach ($admins as $admin) {
                $flags = OtdFolders::for($admin);
                if (! $flags->hasFlags()) {
                    continue;
                }
                $q = Media::query()->where('media.type', $type)->whereNotNull('taken_at')
                    ->whereMonth('taken_at', $today->month)->whereDay('taken_at', $today->day)
                    ->whereYear('taken_at', '<=', $today->year - $minYears);
                if ($type === 'video') {
                    $q->where('media.size', '<=', self::MAX_UPLOAD);
                }
                $flags->scope($q, 'media.path');
                $ids = array_merge($ids, $q->pluck('media.id')->all());
            }
            $ids = collect($ids)->unique()->shuffle()->take($limits[$key]);
            $out[$key] = Media::whereIn('id', $ids)->orderBy('taken_at')->get();
        }

        return $out;
    }

    /** @return array{sent: int, failed: int, skipped: int} */
    public function send(?Carbon $today = null): array
    {
        $today ??= Carbon::now(config('app.display_timezone'));
        $token = (string) Settings::get('telegram_token');
        $chat = (string) Settings::get('telegram_chat');
        $picked = $this->pick($today);
        $items = $picked['images']->concat($picked['videos']);
        $result = ['sent' => 0, 'failed' => 0, 'skipped' => 0];
        if ($items->isEmpty()) {
            return $result;
        }
        $this->call($token, 'sendMessage', ['chat_id' => $chat, 'text' => '📅 '.$today->format('Y-m-d').' — '.$items->count().' memories']);
        $slots = new ReadSlots;
        foreach ($items as $m) {
            sleep(3); // Telegram allows about 20 messages per minute in a group
            if (! is_file(Paths::abs($m->path)) || ! $slots->acquire(30)) {
                $result['skipped']++;

                continue;
            }
            try {
                $ok = $this->upload($token, $chat, $m, $today);
            } catch (Throwable $e) {
                report($e);
                $ok = false;
            } finally {
                $slots->release();
            }
            $ok ? $result['sent']++ : $result['failed']++;
        }

        return $result;
    }

    private function caption(Media $m, Carbon $today): string
    {
        $years = $today->year - (int) $m->taken_at->format('Y');
        $folder = Paths::parent($m->path) ?? '';
        $text = '🕰 '.$years.' year'.($years === 1 ? '' : 's').' ago · '.$m->taken_at->format('Y-m-d');
        if ($m->description) {
            $text .= "\n".$m->description;
        }
        $text .= "\n📁 ".($folder === '' ? '/' : $folder);

        return mb_substr($text, 0, 1000);
    }

    private function upload(string $token, string $chat, Media $m, Carbon $today): bool
    {
        $abs = Paths::abs($m->path);
        $size = (int) @filesize($abs);
        if ($size <= 0 || $size > self::MAX_UPLOAD) {
            return false;
        }
        [$method, $field] = $m->type === 'video' ? ['sendVideo', 'video']
            : ($size <= self::MAX_PHOTO ? ['sendPhoto', 'photo'] : ['sendDocument', 'document']);
        $params = ['chat_id' => $chat, 'caption' => $this->caption($m, $today)];
        if ($m->type === 'video') {
            $params['supports_streaming'] = 'true';
        }
        $fh = fopen($abs, 'rb');
        if (! $fh) {
            return false;
        }
        try {
            $ok = $this->call($token, $method, $params, [$field, $fh, $m->filename]);
            if (! $ok && $method === 'sendPhoto') {
                // Telegram refuses some photos (very large dimensions): send the same file as a document.
                rewind($fh);
                unset($params['supports_streaming']);
                $ok = $this->call($token, 'sendDocument', $params, ['document', $fh, $m->filename]);
            }

            return $ok;
        } finally {
            fclose($fh);
        }
    }

    public function call(string $token, string $method, array $params, ?array $file = null): bool
    {
        $req = Http::timeout(180)->connectTimeout(15)->asMultipart();
        if ($file) {
            $req = $req->attach($file[0], $file[1], $file[2]);
        }
        $res = $req->post("https://api.telegram.org/bot{$token}/{$method}", array_map('strval', $params));
        if (! $res->ok()) {
            // Never log the token (it is part of the URL).
            logger()->warning('telegram '.$method.' failed', ['status' => $res->status(), 'body' => mb_substr($res->body(), 0, 300)]);
        }

        return $res->ok();
    }
}
