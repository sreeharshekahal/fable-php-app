<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;

class EncryptExistingNames extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:encrypt-names';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Encrypt plaintext names in the auth_user table';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $users = DB::table('auth_user')->get();
        $count = 0;

        $this->info("Found " . count($users) . " users. Checking for plaintext names...");

        foreach ($users as $user) {
            // Check if name is already encrypted (Laravel encryption starts with 'eyJpdiI6')
            if (!empty($user->first_name) && !str_contains($user->first_name, 'eyJpdiI6')) {
                try {
                    DB::table('auth_user')->where('id', $user->id)->update([
                        'first_name' => Crypt::encryptString($user->first_name),
                        'last_name' => !empty($user->last_name) ? Crypt::encryptString($user->last_name) : '',
                    ]);
                    $count++;
                } catch (\Exception $e) {
                    $this->error("Failed to encrypt user ID {$user->id}: " . $e->getMessage());
                }
            }
        }

        $this->info("Successfully encrypted $count plaintext names.");
        return 0;
    }
}
