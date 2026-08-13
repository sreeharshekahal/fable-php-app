<?php

namespace App\Imports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Shared\Date;

class StudentImport implements ToCollection, WithHeadingRow
{
    protected $organisation;
    protected $grade;

    public function __construct($organisation, $grade)
    {
        $this->organisation = $organisation;
        $this->grade = $grade;
    }

    public function collection(Collection $rows)
    {
        foreach ($rows as $index => $row) {
            // Skip completely empty rows or rows without first_name
            if (!isset($row['first_name']) || empty(trim($row['first_name']))) {
                continue;
            }

            // Check languages only for valid student rows
            if (empty($row['languages'])) {
                throw new \Exception("Languages field is required. Missing in row " . ($index + 2));
            }
        }

        //get benchmarking id
        $level = DB::table('common_level')
            ->where('title', 'Benchmarking')
            ->first();

        $group = DB::table('organisation_group')
            ->where('organisation_id', $this->organisation)
            ->where('grade_id', $this->grade)
            ->where('level_id', $level->id)
            ->first();

        $group_id = $group ? $group->id : null;

        foreach ($rows as $row) {
            if (!isset($row['first_name']) || empty(trim($row['first_name'])))
                continue; // basic row validation

            if (is_numeric($row['date_of_birth'])) {
                $date_of_birth = Carbon::createFromTimestamp(Date::excelToTimestamp($row['date_of_birth']))
                    ->format('Y-m-d');
            } else {
                $date_of_birth = Carbon::parse($row['date_of_birth'])->format('Y-m-d');
            }

            $password = 'qwerty12';
            $salt = Str::random(12);
            $iterations = 150000;
            $hashedPassword = "pbkdf2_sha256\${$iterations}\${$salt}\$" . base64_encode(
                hash_pbkdf2('sha256', $password, $salt, $iterations, 32, true)
            );

            $currentTime = Carbon::now('Asia/Kolkata');
            $date_joined = $currentTime->format('Y-m-d H:i:s.uP');

            $genders = ['Male' => 0, 'Female' => 1, 'Others' => 2];

            $user_id = DB::table('auth_user')->insertGetId([
                'first_name' => \Illuminate\Support\Facades\Crypt::encryptString($row['first_name']),
                'last_name' => \Illuminate\Support\Facades\Crypt::encryptString($row['last_name']),
                // 'first_name' => $row['first_name'],
                // 'last_name' => $row['last_name'],
                'password' => $hashedPassword,
                'is_superuser' => 'f',
                'username' => Str::uuid(),
                'email' => '',
                'is_staff' => 'f',
                'is_active' => 't',
                'date_joined' => $date_joined
            ]);

            $student_id = DB::table('access_student')->insertGetId([
                'id' => Str::uuid(),
                'date_of_birth' => $date_of_birth,
                'gender' => $genders[$row['gender']],
                'is_english_second_language' => $row['is_english_second_language'],
                'disability' => $row['disability'],
                'division' => (isset($row['division'])) ? $row['division'] : '',
                'user_id' => $user_id,
                'grade_id' => $this->grade,
                'organisation_id' => $this->organisation,
                'group_id' => $group_id,
                'group_hindi_id' => $group_id,
                'group_marathi_id' => $group_id
            ]);

            foreach (explode(',', $row['languages']) as $languageName) {
                $language = DB::table('common_language')->where('name', trim($languageName))->first();
                if ($language) {
                    DB::table('access_student_languages')->insert([
                        'student_id' => $student_id,
                        'language_id' => $language->id
                    ]);
                }
            }
        }
    }
}

