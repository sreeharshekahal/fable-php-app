<?php

namespace App\Services;

use DateTime;
use DateTimeZone;

class DjangoPasswordService
{
    protected string $secretKey;
    protected int $timeoutDays;
    protected string $keySalt = 'django.contrib.auth.tokens.PasswordResetTokenGenerator';

    public function __construct(?string $secretKey = null, int $timeoutDays = 1)
    {
        $this->secretKey = $secretKey ?: config('app.django_secret_key', env('DJANGO_SECRET_KEY', config('app.key')));
        $this->timeoutDays = $timeoutDays;
    }

    /**
     * Check if a raw password matches Django's pbkdf2_sha256 hash.
     */
    public function checkPassword(string $password, string $encoded): bool
    {
        $parts = explode('$', $encoded);
        if (count($parts) !== 4) {
            return false;
        }

        [$algorithm, $iterations, $salt, $hash] = $parts;

        if ($algorithm !== 'pbkdf2_sha256') {
            return false;
        }

        $rawHash = hash_pbkdf2('sha256', $password, $salt, (int) $iterations, 32, true);
        $calculated = base64_encode($rawHash);

        return hash_equals($hash, $calculated);
    }

    /**
     * Hash a password following Django's PBKDF2PasswordHasher standard.
     */
    public function makePassword(string $password, int $iterations = 150000): string
    {
        // 12-char alphanumeric salt (matching standard Django salt generator)
        $characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $salt = '';
        $maxIndex = strlen($characters) - 1;
        for ($i = 0; $i < 12; $i++) {
            $salt .= $characters[random_int(0, $maxIndex)];
        }

        $rawHash = hash_pbkdf2('sha256', $password, $salt, $iterations, 32, true);
        $base64Hash = base64_encode($rawHash);

        return "pbkdf2_sha256\${$iterations}\${$salt}\${$base64Hash}";
    }

    /**
     * Make a password reset token for the given user, matching Django's PasswordResetTokenGenerator.
     */
    public function makeResetToken(object $user): string
    {
        return $this->makeTokenWithTimestamp($user, $this->numDays($this->today()));
    }

    /**
     * Check if the password reset token is valid for the given user.
     */
    public function checkResetToken(object $user, ?string $token): bool
    {
        if (!$user || !$token) {
            return false;
        }

        $parts = explode('-', $token, 2);
        if (count($parts) !== 2) {
            return false;
        }

        [$tsB36, $hash] = $parts;
        $ts = $this->base36ToInt($tsB36);

        if ($ts === null) {
            return false;
        }

        $expected = $this->makeTokenWithTimestamp($user, $ts);
        if (!hash_equals($expected, $token)) {
            return false;
        }

        if (($this->numDays($this->today()) - $ts) > $this->timeoutDays) {
            return false;
        }

        return true;
    }

    /**
     * URL-safe base64 encode matching Django's urlsafe_base64_encode.
     */
    public function urlsafeBase64Encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * URL-safe base64 decode matching Django's urlsafe_base64_decode.
     */
    public function urlsafeBase64Decode(string $value): ?string
    {
        $remainder = strlen($value) % 4;
        if ($remainder) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        return $decoded !== false ? $decoded : null;
    }

    protected function makeTokenWithTimestamp(object $user, int $timestamp): string
    {
        $tsB36 = $this->intToBase36($timestamp);
        $hashVal = $this->makeHashValue($user, $timestamp);
        $key = sha1($this->keySalt . $this->secretKey, true);
        $hmac = hash_hmac('sha1', $hashVal, $key);

        // Django limits to 20 chars via [::2]
        $hashPart = '';
        $len = strlen($hmac);
        for ($i = 0; $i < $len; $i += 2) {
            $hashPart .= $hmac[$i];
        }

        return "{$tsB36}-{$hashPart}";
    }

    protected function makeHashValue(object $user, int $timestamp): string
    {
        $loginTimestamp = '';
        if (!empty($user->last_login)) {
            // Django formats last_login truncated to seconds: Y-m-d H:i:s
            $loginTimestamp = date('Y-m-d H:i:s', strtotime($user->last_login));
        }

        return ((string) $user->id) . $user->password . $loginTimestamp . ((string) $timestamp);
    }

    protected function intToBase36(int $number): string
    {
        return base_convert((string) $number, 10, 36);
    }

    protected function base36ToInt(string $str): ?int
    {
        if (!preg_match('/^[0-9a-zA-Z]+$/', $str)) {
            return null;
        }
        return (int) base_convert($str, 36, 10);
    }

    protected function numDays(DateTime $dt): int
    {
        $epoch = new DateTime('2001-01-01', new DateTimeZone('UTC'));
        return (int) $dt->diff($epoch)->days;
    }

    protected function today(): DateTime
    {
        return new DateTime('today', new DateTimeZone('UTC'));
    }
}

