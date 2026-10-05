<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class GalleryUser extends Command
{
    protected $signature = 'gallery:user {username} {--name=} {--admin} {--password-stdin : read the password from STDIN}';

    protected $description = 'Create a user, or reset the password of an existing one';

    public function handle(): int
    {
        $password = $this->option('password-stdin')
            ? trim((string) stream_get_contents(STDIN))
            : (string) $this->secret('Password');
        if (strlen($password) < 8) {
            $this->error('Password must have at least 8 characters.');

            return self::FAILURE;
        }
        $user = User::firstOrNew(['username' => $this->argument('username')]);
        $user->name = $this->option('name') ?: ($user->name ?: $this->argument('username'));
        $user->password = $password;
        if ($this->option('admin')) {
            $user->is_admin = true;
        }
        $user->is_active = true;
        $user->save();
        $this->info(($user->wasRecentlyCreated ? 'Created' : 'Updated')." user {$user->username}".($user->is_admin ? ' (admin)' : ''));

        return self::SUCCESS;
    }
}
