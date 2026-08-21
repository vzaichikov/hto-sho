<?php

namespace Tests\Feature;

use App\Actions\AuthenticateSilpoUserAction;
use App\Contracts\SilpoProfileGateway;
use App\Models\SilpoConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Client\OAuth\TokenSet;
use RuntimeException;
use Tests\TestCase;

class SilpoAuthenticationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_landing_only_offers_silpo_login(): void
    {
        $this->get(route('landing'))
            ->assertOk()
            ->assertSee('Увійти через Сільпо')
            ->assertSee('Гусь прочитав')
            ->assertSee(route('mcp.oauth.silpo.connect'), escape: false)
            ->assertSee(asset('images/brand/goose-sho.png'), escape: false)
            ->assertDontSee('type="password"', escape: false)
            ->assertDontSee('Реєстрація');
    }

    public function test_oauth_routes_use_the_expected_loopback_paths_and_rate_limit(): void
    {
        $connectRoute = Route::getRoutes()->getByName('mcp.oauth.silpo.connect');
        $callbackRoute = Route::getRoutes()->getByName('mcp.oauth.silpo.callback');

        $this->assertNotNull($connectRoute);
        $this->assertNotNull($callbackRoute);
        $this->assertSame('login/silpo', $connectRoute->uri());
        $this->assertSame('mcp/oauth/silpo/callback', $callbackRoute->uri());
        $this->assertContains('throttle:silpo-oauth', $connectRoute->gatherMiddleware());
        $this->assertSame(
            'http://127.0.0.1:8001/mcp/oauth/silpo/callback',
            config('services.silpo_mcp.redirect_uri'),
        );
    }

    public function test_oauth_start_registers_a_public_client_and_omits_scope(): void
    {
        $this->fakeOAuthServer();

        $response = $this->get(route('mcp.oauth.silpo.connect'));

        $response->assertRedirect();

        $location = (string) $response->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        $this->assertSame('https://identity.example.test/authorize', strtok($location, '?'));
        $this->assertSame('public-client', $query['client_id'] ?? null);
        $this->assertSame('S256', $query['code_challenge_method'] ?? null);
        $this->assertSame('https://mcp.example.test/mcp', $query['resource'] ?? null);
        $this->assertArrayHasKey('state', $query);
        $this->assertArrayHasKey('code_challenge', $query);
        $this->assertArrayNotHasKey('scope', $query);

        Http::assertSent(function (Request $request): bool {
            if ($request->url() !== 'https://identity.example.test/register') {
                return false;
            }

            $data = $request->data();

            return $request->method() === 'POST'
                && ($data['token_endpoint_auth_method'] ?? null) === 'none'
                && ($data['application_type'] ?? null) === 'native'
                && ($data['client_name'] ?? null) === 'Хто Шо?'
                && ! array_key_exists('scope', $data)
                && ! array_key_exists('client_secret', $data);
        });
    }

    public function test_callback_exchanges_public_pkce_code_and_logs_in_a_passwordless_user(): void
    {
        $this->fakeOAuthServer([
            'access_token' => 'access-secret',
            'refresh_token' => 'refresh-secret',
            'expires_in' => 3600,
            'token_type' => 'Bearer',
            'scope' => 'openid offline_access silpo--mcp-server',
        ]);

        $gateway = new FakeSilpoProfileGateway([
            'id' => 'guest-123',
            'name' => 'Олена Коваль',
            'email' => 'olena@example.com',
        ]);
        $this->app->instance(SilpoProfileGateway::class, $gateway);

        $connectResponse = $this->get(route('mcp.oauth.silpo.connect'));
        parse_str((string) parse_url((string) $connectResponse->headers->get('Location'), PHP_URL_QUERY), $query);

        $response = $this->get(route('mcp.oauth.silpo.callback', [
            'code' => 'one-time-code',
            'state' => $query['state'],
        ]));

        $user = User::query()->where('name', 'Олена Коваль')->sole();
        $connection = $user->silpoConnection()->sole();

        $this->assertAuthenticatedAs($user);
        $this->assertTrue($response->isRedirect(route('events.index')));
        $this->assertSame('Олена Коваль', $user->name);
        $this->assertNull($user->email);
        $this->assertNull($user->password);
        $this->assertSame('access-secret', $connection->access_token);
        $this->assertSame('refresh-secret', $connection->refresh_token);
        $this->assertSame('public-client', $connection->client_id);
        $this->assertNull($connection->client_secret);
        $this->assertSame('guest-123', $connection->profile_snapshot['id']);
        $this->assertNotSame(
            'access-secret',
            $connection->getRawOriginal('access_token'),
        );
        $this->assertArrayNotHasKey('access_token', $connection->toArray());
        $this->assertArrayNotHasKey('profile_snapshot', $connection->toArray());

        Http::assertSent(function (Request $request): bool {
            if ($request->url() !== 'https://identity.example.test/token') {
                return false;
            }

            $data = $request->data();

            return $request->method() === 'POST'
                && ($data['grant_type'] ?? null) === 'authorization_code'
                && ($data['client_id'] ?? null) === 'public-client'
                && ($data['code'] ?? null) === 'one-time-code'
                && ($data['resource'] ?? null) === 'https://mcp.example.test/mcp'
                && is_string($data['code_verifier'] ?? null)
                && $data['code_verifier'] !== ''
                && ! array_key_exists('scope', $data)
                && ! array_key_exists('client_secret', $data);
        });
    }

    public function test_callback_rejects_a_mismatched_state_before_token_exchange(): void
    {
        $this->fakeOAuthServer([
            'access_token' => 'must-not-be-used',
        ]);

        $this->get(route('mcp.oauth.silpo.connect'));

        $this->get(route('mcp.oauth.silpo.callback', [
            'code' => 'one-time-code',
            'state' => 'wrong-state',
        ]))
            ->assertRedirect(route('landing'))
            ->assertSessionHas('error');

        $this->assertGuest();
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://identity.example.test/token');
    }

    public function test_reauthorization_updates_the_single_existing_connection_and_preserves_refresh_token(): void
    {
        $gateway = new FakeSilpoProfileGateway(['id' => 'same-guest', 'name' => 'Перше імʼя']);
        $this->app->instance(SilpoProfileGateway::class, $gateway);
        $authenticate = $this->app->make(AuthenticateSilpoUserAction::class);

        $firstUser = $authenticate->execute(new TokenSet(
            accessToken: 'first-access',
            refreshToken: 'first-refresh',
            clientId: 'first-client',
            clientSecret: 'first-secret',
        ));

        $gateway->profile = ['id' => 'same-guest', 'name' => 'Нове імʼя'];
        $secondUser = $authenticate->execute(new TokenSet(
            accessToken: 'second-access',
            refreshToken: null,
            clientId: 'second-client',
            clientSecret: 'second-secret',
        ));

        $this->assertTrue($firstUser->is($secondUser));
        $this->assertSame(1, User::query()
            ->where('silpo_identity_hash', $firstUser->silpo_identity_hash)
            ->count());
        $this->assertSame(1, SilpoConnection::query()
            ->whereBelongsTo($firstUser)
            ->count());
        $this->assertSame('Нове імʼя', $secondUser->name);
        $this->assertSame('second-access', $secondUser->silpoConnection->access_token);
        $this->assertSame('first-refresh', $secondUser->silpoConnection->refresh_token);
    }

    public function test_login_fails_if_profile_has_no_safe_identity(): void
    {
        $this->app->instance(SilpoProfileGateway::class, new FakeSilpoProfileGateway([
            'name' => 'Без ідентифікатора',
        ]));

        $this->expectException(RuntimeException::class);

        $this->app->make(AuthenticateSilpoUserAction::class)
            ->execute(new TokenSet(accessToken: 'access-secret'));
    }

    /**
     * @param  array<string, mixed>  $tokenResponse
     */
    private function fakeOAuthServer(array $tokenResponse = ['access_token' => 'access-secret']): void
    {
        config()->set([
            'services.silpo_mcp.url' => 'https://mcp.example.test/mcp',
            'services.silpo_mcp.client_name' => 'Хто Шо?',
            'services.silpo_mcp.redirect_uri' => 'http://127.0.0.1:8001/mcp/oauth/silpo/callback',
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://mcp.example.test/.well-known/oauth-protected-resource/mcp' => Http::response([
                'resource' => 'https://mcp.example.test/mcp',
                'authorization_servers' => ['https://identity.example.test'],
            ]),
            'https://identity.example.test/.well-known/oauth-authorization-server' => Http::response([
                'issuer' => 'https://identity.example.test',
                'authorization_endpoint' => 'https://identity.example.test/authorize',
                'token_endpoint' => 'https://identity.example.test/token',
                'registration_endpoint' => 'https://identity.example.test/register',
                'code_challenge_methods_supported' => ['S256'],
                'token_endpoint_auth_methods_supported' => ['none'],
            ]),
            'https://identity.example.test/register' => Http::response([
                'client_id' => 'public-client',
            ], 201),
            'https://identity.example.test/token' => Http::response($tokenResponse),
        ]);
    }
}

class FakeSilpoProfileGateway implements SilpoProfileGateway
{
    /**
     * @param  array<string, mixed>  $profile
     */
    public function __construct(public array $profile) {}

    public function getProfile(string $accessToken): array
    {
        return $this->profile;
    }
}
