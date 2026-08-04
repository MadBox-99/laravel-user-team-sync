<?php

declare(strict_types=1);

namespace Madbox99\UserTeamSync\Client;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Session;

/**
 * Every session key the client mode touches lives here, so the token storage
 * format is decided in exactly one place.
 */
final class IdentitySession
{
    public const string STATE = 'identity.state';

    public const string CODE_VERIFIER = 'identity.code_verifier';

    public const string ACCESS_TOKEN = 'identity.access_token';

    public const string REFRESH_TOKEN = 'identity.refresh_token';

    public const string CHECKED_AT = 'identity.claims_checked_at';

    public const string GRACE_STARTED_AT = 'identity.grace_started_at';

    public const string INTENDED = 'identity.intended';

    /**
     * @param  array{access_token: string, refresh_token?: string, expires_in?: int}  $tokens
     */
    public static function putTokens(array $tokens): void
    {
        // Encrypted rather than plain: session payloads land in the database on
        // every receiver, and a refresh token is a long-lived credential.
        // Crypt::encrypt() (not encryptString()) so a caller can decrypt it
        // with the plain decrypt() helper, which unserializes by default.
        Session::put(self::ACCESS_TOKEN, Crypt::encrypt($tokens['access_token']));

        if (isset($tokens['refresh_token'])) {
            Session::put(self::REFRESH_TOKEN, Crypt::encrypt($tokens['refresh_token']));
        }
    }

    public static function accessToken(): ?string
    {
        return self::decrypt(self::ACCESS_TOKEN);
    }

    public static function refreshToken(): ?string
    {
        return self::decrypt(self::REFRESH_TOKEN);
    }

    public static function markChecked(): void
    {
        Session::put(self::CHECKED_AT, Carbon::now()->timestamp);
    }

    public static function checkedAt(): ?CarbonInterface
    {
        $timestamp = Session::get(self::CHECKED_AT);

        return is_int($timestamp) ? Carbon::createFromTimestamp($timestamp) : null;
    }

    public static function startGrace(): void
    {
        if (! Session::has(self::GRACE_STARTED_AT)) {
            Session::put(self::GRACE_STARTED_AT, Carbon::now()->timestamp);
        }
    }

    public static function graceStartedAt(): ?CarbonInterface
    {
        $timestamp = Session::get(self::GRACE_STARTED_AT);

        return is_int($timestamp) ? Carbon::createFromTimestamp($timestamp) : null;
    }

    public static function clearGrace(): void
    {
        Session::forget(self::GRACE_STARTED_AT);
    }

    public static function forgetHandshake(): void
    {
        // INTENDED is included so an abandoned attempt (forged state,
        // allowlist rejection) never leaks its target into a later,
        // unrelated login in the same session. The one path that legitimately
        // needs INTENDED — a successful callback — reads it before calling
        // this method.
        Session::forget([self::STATE, self::CODE_VERIFIER, self::INTENDED]);
    }

    public static function forget(): void
    {
        Session::forget([
            self::STATE,
            self::CODE_VERIFIER,
            self::ACCESS_TOKEN,
            self::REFRESH_TOKEN,
            self::CHECKED_AT,
            self::GRACE_STARTED_AT,
        ]);
    }

    private static function decrypt(string $key): ?string
    {
        $value = Session::get($key);

        if (! is_string($value)) {
            return null;
        }

        try {
            /** @var string $decrypted */
            $decrypted = Crypt::decrypt($value);

            return $decrypted;
        } catch (\Throwable) {
            return null;
        }
    }
}
