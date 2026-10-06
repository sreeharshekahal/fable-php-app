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
        // Students do not have email (teachers require email).
        // To ensure we capture all students (even if some lack access_student records)
        // and strictly exclude teachers, staff, and superusers:
        $students = DB::table('auth_user')
            ->leftJoin('access_student', 'auth_user.id', '=', 'access_student.user_id')
            ->where(function ($query) {
                $query->whereNull('auth_user.email')
                      ->orWhere('auth_user.email', '')
                      ->orWhereNotNull('access_student.id');
            })
            ->where('auth_user.is_superuser', 'f')
            ->where('auth_user.is_staff', 'f')
            ->whereNotIn('auth_user.id', function ($query) {
                $query->select('user_id')->from('access_teacher')->whereNotNull('user_id');
            })
            ->select('auth_user.id', 'auth_user.first_name', 'auth_user.last_name')
            ->distinct()
            ->get();

        $count = 0;

        $this->info("Found " . count($students) . " students. Checking for plaintext names...");

        foreach ($students as $student) {
            $updateData = [];

            // Check if first_name is plaintext and avoid re-encrypting (Laravel payload starts with 'eyJpdiI6')
            if (!empty($student->first_name) && !str_contains($student->first_name, 'eyJpdiI6')) {
                $updateData['first_name'] = Crypt::encryptString($student->first_name);
            }

            // Check if last_name is plaintext and avoid re-encrypting
            if (!empty($student->last_name) && !str_contains($student->last_name, 'eyJpdiI6')) {
                $updateData['last_name'] = Crypt::encryptString($student->last_name);
            }

            if (!empty($updateData)) {
                try {
                    DB::table('auth_user')->where('id', $student->id)->update($updateData);
                    $count++;
                } catch (\Exception $e) {
                    $this->error("Failed to encrypt student user ID {$student->id}: " . $e->getMessage());
                }
            }
        }

        $this->info("Successfully encrypted $count plaintext student names.");
        return 0;
    }
}
