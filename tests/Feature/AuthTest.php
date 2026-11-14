<?php

namespace Tests\Feature;

use App\Models\SocialAccount;
use App\Models\TaskList;
use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private string $privateKey = '';

    private array $jwk;

    protected function setUp(): void
    {
        parent::setUp();

        // A throwaway RSA key pair stands in for Apple's and Google's signing keys.
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $this->privateKey);
        $details = openssl_pkey_get_details($key)['rsa'];
        $b64 = fn (string $bin) => rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
        $this->jwk = ['kty' => 'RSA', 'kid' => 'test-key', 'use' => 'sig', 'alg' => 'RS256', 'n' => $b64($details['n']), 'e' => $b64($details['e'])];

        config([
            'daylist.apple.client_ids' => ['com.attiquedev.daylist'],
            'daylist.google.client_ids' => ['google-ios-client.apps.googleusercontent.com'],
        ]);
        Cache::flush();
        Http::fake([
            'appleid.apple.com/*' => Http::response(['keys' => [$this->jwk]]),
            'www.googleapis.com/*' => Http::response(['keys' => [$this->jwk]]),
        ]);
    }

    private function idToken(array $claims): string
    {
        return JWT::encode(array_merge(['iat' => time(), 'exp' => time() + 600], $claims), $this->privateKey, 'RS256', 'test-key');
    }

    private function appleToken(array $claims = []): string
    {
        return $this->idToken(array_merge([
            'iss' => 'https://appleid.apple.com',
            'aud' => 'com.attiquedev.daylist',
            'sub' => '001234.apple-user',
            'email' => 'sara@example.com',
            'email_verified' => 'true',
        ], $claims));
    }

    public function test_register_creates_user_token_and_default_list(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Sara', 'email' => 'Sara@Example.com', 'password' => 'secret-pass', 'device_name' => 'iPhone',
            'timezone' => 'Asia/Karachi',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.user.email', 'sara@example.com')
            ->assertJsonPath('data.user.timezone', 'Asia/Karachi')
            ->assertJsonPath('data.user.settings.week_start', 'monday')
            ->assertJsonStructure(['data' => ['token', 'token_type', 'user' => ['id', 'name']]]);

        $user = User::firstWhere('email', 'sara@example.com');
        $this->assertSame('Tasks', $user->defaultList()->name);

        $this->withToken($response->json('data.token'))->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.id', $user->id);
    }

    public function test_register_rejects_duplicate_email_and_short_password(): void
    {
        $this->makeUser(['email' => 'taken@example.com']);

        $this->postJson('/api/v1/auth/register', ['name' => 'X', 'email' => 'taken@example.com', 'password' => 'short', 'device_name' => 'iPhone'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_login_and_logout(): void
    {
        $this->makeUser(['email' => 'ali@example.com', 'password' => 'correct-horse']);

        $this->postJson('/api/v1/auth/login', ['email' => 'ali@example.com', 'password' => 'wrong', 'device_name' => 'iPhone'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');

        $token = $this->postJson('/api/v1/auth/login', ['email' => 'ALI@example.com', 'password' => 'correct-horse', 'device_name' => 'iPhone'])
            ->assertOk()->json('data.token');

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_protected_routes_need_a_token(): void
    {
        $this->getJson('/api/v1/lists')->assertUnauthorized();
        $this->get('/api/v1/lists')->assertUnauthorized()->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_apple_sign_in_creates_a_user_once(): void
    {
        $first = $this->postJson('/api/v1/auth/apple', ['identity_token' => $this->appleToken(), 'name' => 'Sara Khan', 'device_name' => 'iPhone']);
        $first->assertCreated()->assertJsonPath('data.is_new', true)->assertJsonPath('data.user.name', 'Sara Khan');

        // Apple sends the name only the first time; the second sign-in finds the same account.
        $second = $this->postJson('/api/v1/auth/apple', ['identity_token' => $this->appleToken(), 'device_name' => 'iPad']);
        $second->assertOk()->assertJsonPath('data.is_new', false)->assertJsonPath('data.user.id', $first->json('data.user.id'));

        $this->assertSame(1, User::count());
        $this->assertSame(1, TaskList::where('is_default', true)->count());
    }

    public function test_apple_sign_in_links_to_existing_email_account(): void
    {
        $existing = $this->makeUser(['email' => 'sara@example.com']);

        $this->postJson('/api/v1/auth/apple', ['identity_token' => $this->appleToken(), 'device_name' => 'iPhone'])
            ->assertOk()->assertJsonPath('data.user.id', $existing->id);

        $this->assertDatabaseHas('social_accounts', ['user_id' => $existing->id, 'provider' => 'apple']);
    }

    public function test_apple_sign_in_rejects_wrong_audience_expired_and_bad_signature(): void
    {
        $this->postJson('/api/v1/auth/apple', ['identity_token' => $this->appleToken(['aud' => 'com.someone.else']), 'device_name' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors('identity_token');

        $this->postJson('/api/v1/auth/apple', ['identity_token' => $this->appleToken(['exp' => time() - 3600]), 'device_name' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors('identity_token');

        $this->postJson('/api/v1/auth/apple', ['identity_token' => $this->appleToken().'tampered', 'device_name' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors('identity_token');

        $this->assertSame(0, User::count());
    }

    public function test_google_sign_in(): void
    {
        $token = $this->idToken([
            'iss' => 'https://accounts.google.com',
            'aud' => 'google-ios-client.apps.googleusercontent.com',
            'sub' => '1098765',
            'email' => 'omar@gmail.com',
            'email_verified' => true,
            'name' => 'Omar Farooq',
            'picture' => 'https://example.com/omar.png',
        ]);

        $this->postJson('/api/v1/auth/google', ['id_token' => $token, 'device_name' => 'iPhone'])
            ->assertCreated()
            ->assertJsonPath('data.user.name', 'Omar Farooq')
            ->assertJsonPath('data.user.avatar_url', 'https://example.com/omar.png');

        $this->assertDatabaseHas('social_accounts', ['provider' => 'google', 'provider_user_id' => '1098765']);
    }

    public function test_unverified_social_email_does_not_take_over_an_account(): void
    {
        $existing = $this->makeUser(['email' => 'sara@example.com']);

        $response = $this->postJson('/api/v1/auth/apple', [
            'identity_token' => $this->appleToken(['email_verified' => 'false']),
            'device_name' => 'iPhone',
        ])->assertCreated();

        $this->assertNotSame($existing->id, $response->json('data.user.id'));
        $this->assertSame(0, SocialAccount::where('user_id', $existing->id)->count());
    }

    public function test_password_reset_flow(): void
    {
        Notification::fake();
        $user = $this->makeUser(['email' => 'reset@example.com']);
        $user->createToken('old-phone');

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.com'])->assertStatus(202);
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'reset@example.com'])->assertStatus(202);

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use (&$token) {
            $token = $n->token;

            return true;
        });

        $this->postJson('/api/v1/auth/reset-password', ['email' => 'reset@example.com', 'token' => 'bad', 'password' => 'new-password'])
            ->assertUnprocessable();

        $this->postJson('/api/v1/auth/reset-password', ['email' => 'reset@example.com', 'token' => $token, 'password' => 'new-password'])
            ->assertOk();

        $this->assertSame(0, $user->tokens()->count());
        $this->postJson('/api/v1/auth/login', ['email' => 'reset@example.com', 'password' => 'new-password', 'device_name' => 'x'])->assertOk();
    }
}
