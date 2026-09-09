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
    protected $description = 'Encrypt plaintext names of students in the auth_user table';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $users = DB::table('auth_user')
            ->whereIn('id', function ($query) {
                $query->select('user_id')
                    ->from('access_student')
                    ->whereNotNull('user_id');
            })
            ->get();
        $count = 0;

        $this->info("Found " . count($users) . " student users. Checking for plaintext names...");

        foreach ($users as $user) {
            $updateData = [];

            // Check if name is already encrypted (Laravel encryption starts with 'eyJpdiI6')
            if (!empty($user->first_name) && !str_contains($user->first_name, 'eyJpdiI6')) {
                $updateData['first_name'] = Crypt::encryptString($user->first_name);
            }

            if (!empty($user->last_name) && !str_contains($user->last_name, 'eyJpdiI6')) {
                $updateData['last_name'] = Crypt::encryptString($user->last_name);
            }

            if (!empty($updateData)) {
                try {
                    DB::table('auth_user')->where('id', $user->id)->update($updateData);
                    $count++;
                } catch (\Exception $e) {
                    $this->error("Failed to encrypt student user ID {$user->id}: " . $e->getMessage());
                }
            }
        }

        $this->info("Successfully encrypted $count student plaintext names.");
        return 0;
    }
}
