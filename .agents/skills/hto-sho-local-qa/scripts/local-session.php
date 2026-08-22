#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$projectRoot = dirname(__DIR__, 4);

require $projectRoot.'/vendor/autoload.php';

$app = require $projectRoot.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

/**
 * @return never
 */
function fail(string $message): void
{
    fwrite(STDERR, $message.PHP_EOL);

    exit(1);
}

/**
 * @param  list<string>  $arguments
 * @return array<string, string>
 */
function parseOptions(array $arguments): array
{
    $options = [];

    foreach ($arguments as $argument) {
        if (! str_starts_with($argument, '--') || ! str_contains($argument, '=')) {
            fail("Invalid option: {$argument}. Use --name=value.");
        }

        [$name, $value] = explode('=', substr($argument, 2), 2);

        if ($name === '' || $value === '') {
            fail("Invalid option: {$argument}.");
        }

        $options[$name] = $value;
    }

    return $options;
}

function assertSafeEnvironment(): void
{
    if (! app()->environment(['local', 'testing'])) {
        fail('Refusing to create a QA session outside local or testing.');
    }

    if (config('session.driver') !== 'database') {
        fail('Refusing to continue: the guarded helper requires the database session driver.');
    }

    $connectionName = (string) config('database.default');
    $driver = (string) config("database.connections.{$connectionName}.driver");

    if ($driver === 'sqlite') {
        $database = (string) config("database.connections.{$connectionName}.database");

        if ($database !== ':memory:' && ! str_starts_with($database, base_path().DIRECTORY_SEPARATOR)) {
            fail('Refusing to use a SQLite database outside the local project.');
        }

        return;
    }

    $host = (string) config("database.connections.{$connectionName}.host");

    if (! in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
        fail("Refusing non-loopback database host: {$host}.");
    }
}

function assertTemporaryArtifactPath(string $path): string
{
    if (! str_starts_with($path, DIRECTORY_SEPARATOR)) {
        fail('The artifact path must be absolute.');
    }

    $directory = realpath(dirname($path));
    $temporaryDirectory = realpath(sys_get_temp_dir());

    if ($directory === false || $temporaryDirectory === false) {
        fail('The artifact parent directory must already exist.');
    }

    if ($directory !== $temporaryDirectory && ! str_starts_with($directory, $temporaryDirectory.DIRECTORY_SEPARATOR)) {
        fail('The session artifact must stay inside the system temporary directory.');
    }

    return $directory.DIRECTORY_SEPARATOR.basename($path);
}

/**
 * @return array<string, mixed>
 */
function readArtifact(string $path): array
{
    $contents = file_get_contents($path);

    if ($contents === false) {
        fail('Unable to read the session artifact.');
    }

    $artifact = json_decode($contents, true);

    if (! is_array($artifact) || ! is_string($artifact['session_id'] ?? null)) {
        fail('The session artifact is invalid.');
    }

    return $artifact;
}

function createSession(array $options): void
{
    assertSafeEnvironment();

    $userId = filter_var($options['user-id'] ?? null, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);
    $output = assertTemporaryArtifactPath($options['output'] ?? '');
    $minutes = filter_var($options['minutes'] ?? '30', FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 5, 'max_range' => 60],
    ]);

    if ($userId === false) {
        fail('A valid existing --user-id is required.');
    }

    if ($minutes === false) {
        fail('--minutes must be between 5 and 60.');
    }

    if (file_exists($output)) {
        fail('Refusing to overwrite an existing session artifact. Destroy it first.');
    }

    $user = User::query()->find($userId);

    if ($user === null) {
        fail("Existing user {$userId} was not found.");
    }

    $request = Request::create('/', 'GET', server: [
        'REMOTE_ADDR' => '127.0.0.1',
        'HTTP_USER_AGENT' => 'Codex guarded local QA',
    ]);
    app()->instance('request', $request);

    $session = app('session')->driver();
    $request->setLaravelSession($session);
    Auth::guard('web')->login($user);
    $session->save();

    $sessionId = $session->getId();
    $cookieName = (string) config('session.cookie');
    $encrypter = app('encrypter');
    $cookieValue = $encrypter->encrypt(
        CookieValuePrefix::create($cookieName, $encrypter->getKey()).$sessionId,
        EncryptCookies::serialized($cookieName),
    );

    $artifact = [
        'user_id' => $user->getKey(),
        'session_id' => $sessionId,
        'created_at' => now()->toIso8601String(),
        'expires_at' => now()->addMinutes($minutes)->toIso8601String(),
        'cookie' => [
            'name' => $cookieName,
            'value' => $cookieValue,
            'url' => rtrim((string) config('app.url'), '/').'/',
            'path' => (string) config('session.path', '/'),
            'httpOnly' => true,
            'secure' => (bool) config('session.secure', false),
            'sameSite' => ucfirst((string) config('session.same_site', 'lax')),
            'expires' => now()->addMinutes($minutes)->getTimestamp(),
        ],
    ];

    try {
        $written = file_put_contents(
            $output,
            json_encode($artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL,
            LOCK_EX,
        );

        if ($written === false || ! chmod($output, 0600)) {
            throw new RuntimeException('Unable to secure the session artifact.');
        }
    } catch (Throwable $exception) {
        $session->invalidate();
        $session->save();

        fail($exception->getMessage());
    }

    fwrite(STDOUT, "Created guarded local QA session for existing user {$userId} at {$output}.".PHP_EOL);
}

function destroySession(array $options): void
{
    assertSafeEnvironment();

    $artifactPath = assertTemporaryArtifactPath($options['artifact'] ?? '');

    if (! is_file($artifactPath)) {
        fail('The session artifact does not exist.');
    }

    $artifact = readArtifact($artifactPath);
    $connection = config('session.connection') ?: config('database.default');
    $table = (string) config('session.table', 'sessions');

    DB::connection($connection)
        ->table($table)
        ->where('id', $artifact['session_id'])
        ->delete();

    if (! unlink($artifactPath)) {
        fail('The database session was removed, but the temporary artifact could not be deleted.');
    }

    fwrite(STDOUT, 'Destroyed guarded local QA session and removed its artifact.'.PHP_EOL);
}

$command = $argv[1] ?? null;
$options = parseOptions(array_slice($argv, 2));

match ($command) {
    'create' => createSession($options),
    'destroy' => destroySession($options),
    default => fail('Usage: local-session.php create --user-id=ID --output=/tmp/file [--minutes=30] | destroy --artifact=/tmp/file'),
};
