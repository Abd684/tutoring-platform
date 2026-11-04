<?php

declare(strict_types=1);

namespace App\Services;

use Google\Auth\Credentials\ServiceAccountCredentials;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

/**
 * Sends mobile push notifications through Firebase Cloud Messaging HTTP v1.
 *
 * The credentials JSON stays outside the repository. This service does not
 * modify the application's existing database notifications or authentication.
 */
final class FirebaseMessagingService
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    /**
     * Verify Google OAuth credentials without sending a notification.
     */
    public function verifyAuthentication(): bool
    {
        return $this->accessToken() !== '';
    }

    public function forgetAccessToken(): void
    {
        Cache::forget('firebase:fcm:oauth:'.hash('sha256', $this->projectId()));
    }

    /**
     * Send a notification to one FCM registration token.
     *
     * Data payload values must be strings in FCM, so scalar/structured values
     * are encoded to strings. Returns the Firebase message name on success;
     * throws on authentication, network or HTTP errors.
     *
     * @param  array<string, mixed>  $data
     */
    public function sendToToken(
        string $deviceToken,
        string $title,
        string $body,
        array $data = []
    ): string {
        if (trim($deviceToken) === '') {
            throw new InvalidArgumentException('FCM device token cannot be empty.');
        }

        if (trim($title) === '') {
            throw new InvalidArgumentException('FCM notification title cannot be empty.');
        }

        $message = [
            'token' => $deviceToken,
            'notification' => [
                'title' => $title,
                'body' => $body,
            ],
        ];

        if ($data !== []) {
            $encodedData = [];
            foreach ($data as $key => $value) {
                if (! is_string($key) || $key === '') {
                    throw new InvalidArgumentException('FCM data keys must be nonempty strings.');
                }

                $encodedData[$key] = is_string($value)
                    ? $value
                    : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            }
            $message['data'] = $encodedData;
        }

        $response = Http::asJson()
            ->acceptJson()
            ->withToken($this->accessToken())
            ->connectTimeout(10)
            ->timeout(25)
            ->post(
                'https://fcm.googleapis.com/v1/projects/'.$this->projectId().'/messages:send',
                ['message' => $message]
            );

        $response->throw();

        $name = $response->json('name');
        if (! is_string($name) || $name === '') {
            throw new RuntimeException('FCM accepted the request but did not return a message name.');
        }

        return $name;
    }

    private function projectId(): string
    {
        $projectId = config('services.firebase.project_id');
        if (! is_string($projectId) || $projectId === '') {
            throw new RuntimeException('Firebase project_id is not configured.');
        }

        return $projectId;
    }

    private function accessToken(): string
    {
        $cacheKey = 'firebase:fcm:oauth:'.hash('sha256', $this->projectId());
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $path = config('services.firebase.credentials_path');
        if (! is_string($path) || ! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('Firebase credentials file is missing or unreadable.');
        }

        $credentials = new ServiceAccountCredentials(self::SCOPE, $path);
        $credentialProjectId = $credentials->getProjectId();
        if ($credentialProjectId !== null && $credentialProjectId !== $this->projectId()) {
            throw new RuntimeException('Firebase credentials project_id does not match the configured project.');
        }

        $result = $credentials->fetchAuthToken();
        $token = $result['access_token'] ?? null;
        if (! is_string($token) || $token === '') {
            throw new RuntimeException('Failed to obtain an OAuth access token for Firebase.');
        }

        // Google commonly issues ~1 hour tokens. Cache no longer than 45 min
        // and leave a two-minute margin before expiration.
        $expiresIn = (int) ($result['expires_in'] ?? 3600);
        $ttl = min(2700, max(0, $expiresIn - 120));
        if ($ttl > 0) {
            Cache::put($cacheKey, $token, $ttl);
        }

        return $token;
    }
}
