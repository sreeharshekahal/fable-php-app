<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Ramsey\Uuid\Uuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class UserModel extends Model
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $keyType = 'string';
    protected $table = 'access_student';

    public $incrementing = false;
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'id',
        'date_of_birth',
        'gender',
        'is_english_second_language',
        'disability',
        'division',
        'user_id',
        'grade_id',
        'organisation_id',
        'picture',
        'group_id',
        'group_hindi_id',
        'group_marathi_id'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        // 'password',
        // 'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    /**
     * The "booting" method of the model.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = (string) Uuid::uuid4();
            }
        });
    }

    public function getStudentAssessment($assessment_id, $student_id)
    {
        return DB::table('assessment_assessment')->where('id', $assessment_id)->where('student_id', $student_id)->first();
    }

    public function saveAudio($assessment_id, $student_id, $audio, $retellaudio)
    {
        if ($audio && $retellaudio) {
            $updateData = ['audio' => $audio, 'retell_audio' => $retellaudio, 'updated' => now()];
        } elseif ($audio) {
            $updateData = ['audio' => $audio, 'updated' => now()];
        } elseif ($retellaudio) {
            $updateData = ['retell_audio' => $retellaudio, 'updated' => now()];
        }

        DB::table('assessment_assessment')->where('id', $assessment_id)->where('student_id', $student_id)->update($updateData);
        return TRUE;
    }

    public function getAudio($assessment_id, $student_id)
    {
        return DB::table('assessment_assessment')->select('audio', 'retell_audio')->where('id', $assessment_id)->where('student_id', $student_id)->first();
    }

    public function saveDeviceDetails($user_id, $device_details)
    {
        return DB::table('auth_user')->where('id', $user_id)->update(['device_details' => $device_details]);
    }
}
