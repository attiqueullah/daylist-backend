<?php

namespace App\Services\Auth;

use App\Models\SocialAccount;
use App\Models\User;
use App\Services\ListService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Finds or creates the user behind a verified Apple or Google identity. */
class SocialLogin
{
    public function __construct(private ListService $lists) {}

    public static function verifier(string $provider): IdTokenVerifier
    {
        $config = config("daylist.{$provider}");

        return new IdTokenVerifier(
            $provider,
            $config['jwks_url'],
            $provider === 'apple' ? [$config['issuer']] : $config['issuers'],
            $config['client_ids'],
        );
    }

    /** @return array{0: User, 1: bool} the user and whether it was just created */
    public function resolve(string $provider, array $claims, ?string $name = null): array
    {
        return DB::transaction(function () use ($provider, $claims, $name) {
            $sub = (string) $claims['sub'];
            $email = isset($claims['email']) ? Str::lower((string) $claims['email']) : null;
            // Apple sends email_verified as the string "true".
            $verified = in_array($claims['email_verified'] ?? false, [true, 'true', 1, '1'], true);

            $account = SocialAccount::where('provider', $provider)->where('provider_user_id', $sub)->first();
            if ($account) {
                return [$account->user, false];
            }

            $user = ($email && $verified) ? User::where('email', $email)->first() : null;
            $isNew = $user === null;

            if ($isNew) {
                $user = User::create([
                    'name' => $this->displayName($name, $claims, $email),
                    'email' => ($email && ! User::where('email', $email)->exists()) ? $email : null,
                    'email_verified_at' => ($email && $verified) ? now() : null,
                    'avatar_url' => $claims['picture'] ?? null,
                ]);
                $this->lists->createDefaultList($user);
            }

            SocialAccount::create([
                'user_id' => $user->id,
                'provider' => $provider,
                'provider_user_id' => $sub,
                'email' => $email,
            ]);

            return [$user, $isNew];
        });
    }

    private function displayName(?string $name, array $claims, ?string $email): string
    {
        $name = trim((string) ($name ?? $claims['name'] ?? ''));
        if ($name !== '') {
            return Str::limit($name, 120, '');
        }

        return $email ? Str::before($email, '@') : 'Daylist user';
    }
}
