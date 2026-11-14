<?php

namespace App\Services\Auth;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Verifies an OpenID Connect ID token (Apple or Google): RS256 signature
 * against the provider's published keys, issuer, audience and expiry.
 */
class IdTokenVerifier
{
    public function __construct(
        private string $provider,
        private string $jwksUrl,
        private array $issuers,
        private array $audiences,
    ) {}

    /** @return array<string, mixed> the token's claims */
    public function verify(string $token): array
    {
        if ($this->audiences === []) {
            throw new InvalidIdToken("{$this->provider} sign-in is not configured on the server (missing client id).");
        }

        JWT::$leeway = 60;

        try {
            $claims = (array) JWT::decode($token, $this->keys());
        } catch (Throwable $e) {
            // The provider may have rotated its keys: refresh once and retry.
            Cache::forget($this->cacheKey());
            try {
                $claims = (array) JWT::decode($token, $this->keys());
            } catch (Throwable $e) {
                throw new InvalidIdToken("Invalid {$this->provider} token: ".$e->getMessage());
            }
        }

        if (! in_array($claims['iss'] ?? null, $this->issuers, true)) {
            throw new InvalidIdToken("The {$this->provider} token has the wrong issuer.");
        }

        $aud = (array) ($claims['aud'] ?? []);
        if (array_intersect($aud, $this->audiences) === []) {
            throw new InvalidIdToken("The {$this->provider} token was issued for another app.");
        }

        if (empty($claims['sub'])) {
            throw new InvalidIdToken("The {$this->provider} token has no subject.");
        }

        return $claims;
    }

    private function keys(): array
    {
        $jwks = Cache::remember($this->cacheKey(), now()->addHours(6), function () {
            $response = Http::timeout(10)->get($this->jwksUrl);
            if (! $response->successful()) {
                throw new InvalidIdToken("Could not load {$this->provider} signing keys.");
            }

            return $response->json();
        });

        return JWK::parseKeySet($jwks, 'RS256');
    }

    private function cacheKey(): string
    {
        return "jwks:{$this->provider}";
    }
}
