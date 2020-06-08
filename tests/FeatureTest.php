<?php

namespace Tests;

use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Contracts\Routing\Registrar;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;

class FeatureTest extends TestCase
{
    public function test_user_can_create_tokens()
    {
        $this->withoutExceptionHandling();

        $password = 'foobar123';
        $user = new User();
        $user->email = 'foo@gmail.com';
        $user->password = $this->app->make(Hasher::class)->make($password);
        $user->save();

        /** @var Client $client */
        $client = Client::create([
            'user_id' => null,
            'name' => 'Foo',
            'secret' => Str::random(40),
            'redirect' => 'http://localhost/',
            'personal_access_client' => false,
            'password_client' => true,
            'revoked' => false,
        ]);

        $response = $this->post(
            '/oauth/token',
            [
                'grant_type' => 'password',
                'client_id' => $client->id,
                'client_secret' => $client->secret,
                'username' => $user->email,
                'password' => $password,
            ]
        );

        $response->assertOk();
    }

    public function test_it_can_cache_token()
    {
        $password = 'foobar123';
        $user = new User();
        $user->email = 'foo@gmail.com';
        $user->password = $this->app->make(Hasher::class)->make($password);
        $user->save();

        /** @var Client $client */
        app(ClientRepository::class)->createPersonalAccessClient($user->id, 'Personal Token Client', 'http://localhost');

        /** @var Registrar $router */
        $router = $this->app->make(Registrar::class);

        $token = $user->createToken('test')->accessToken;

        $router->get('/foo', function () {
            return 'bar';
        })->middleware('auth:api');

        $query = $this->getQueryLog(function () use ($token, $user, $router) {
            $this->getJson('/foo')->assertStatus(401);
            $this->withHeader('Authorization', 'Bearer ' . $token)->getJson('/foo')->assertSuccessful()->assertSee('bar');
        });

        $this->assertCount(3, $query);

        // token cached
        $query = $this->getQueryLog(function () use ($token, $user, $router) {
            $router->get('/me', function () {
                return Auth::user();
            })->middleware('auth:api');

            $this->withHeader('Authorization', 'Bearer ' . $token)->getJson('/me')->assertSuccessful()->assertJsonFragment([
                'id' => $user->id,
                'email' => $user->email,
            ]);

            $this->withHeader('Authorization', 'Bearer ' . $token)->getJson('/me')->assertOk();
            $this->withHeader('Authorization', 'Bearer ' . $token)->getJson('/me')->assertOk();
            $this->withHeader('Authorization', 'Bearer ' . $token)->getJson('/me')->assertOk();
            $this->withHeader('Authorization', 'Bearer ' . $token)->getJson('/me')->assertOk();
        });

        $this->assertCount(0, $query);
    }

    protected function getQueryLog(\Closure $callback): \Illuminate\Support\Collection
    {
        $sqls = \collect([]);
        \DB::listen(function ($query) use ($sqls) {
            $sqls->push(['sql' => $query->sql, 'bindings' => $query->bindings]);
        });

        $callback();

        return $sqls;
    }
}
