<?php

namespace App\Gallery;

/**
 * A small counting semaphore built on flock(). It limits how many files are read
 * from the (slow) storage box at the same time, by web requests and by the scan job together.
 */
class ReadSlots
{
    /** @var resource|null */
    private $handle = null;

    public function acquire(float $waitSeconds): bool
    {
        $dir = storage_path('app/locks');
        if (! is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        $slots = max(1, (int) config('gallery.read_slots'));
        $deadline = microtime(true) + $waitSeconds;
        do {
            for ($i = 0; $i < $slots; $i++) {
                $h = fopen($dir."/read-slot-$i.lock", 'c');
                if ($h && flock($h, LOCK_EX | LOCK_NB)) {
                    $this->handle = $h;

                    return true;
                }
                if ($h) {
                    fclose($h);
                }
            }
            usleep(200_000);
        } while (microtime(true) < $deadline);

        return false;
    }

    public function release(): void
    {
        if ($this->handle) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
            $this->handle = null;
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
