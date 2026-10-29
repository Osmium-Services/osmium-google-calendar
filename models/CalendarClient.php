<?php

declare(strict_types=1);

namespace Osmium\Services\GoogleCalendar\Models;

/**
 * The little of the Google Calendar API this service needs, over curl (no SDK). A service account signs a JWT with its
 * private key and trades it for a one-hour access token; the token is kept for the rest of the request only.
 */
class CalendarClient
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const SCOPE = 'https://www.googleapis.com/auth/calendar.events';
    private const EVENTS_URL = 'https://www.googleapis.com/calendar/v3/calendars/';

    private ?string $accessToken = null;

    public function __construct(
        private readonly string $clientEmail,
        private readonly string $privateKey,
        private readonly string $calendarId,
    ) {
    }

    /**
     * Create the event with the given id, or replace it when that id already exists (a booking that moves, or comes back
     * after being cancelled, keeps the same id).
     *
     * @param array<string, mixed> $event
     */
    public function upsert(string $eventId, array $event): void
    {
        $event['id'] = $eventId;
        $event['status'] = 'confirmed';

        $created = $this->request(method: 'POST', url: $this->eventsUrl(), body: $event);
        $alreadyThere = $created['code'] === 409;
        if (!$alreadyThere) $this->expect(response: $created, ok: [200]);
        if (!$alreadyThere) return;

        $replaced = $this->request(method: 'PUT', url: $this->eventsUrl() . '/' . $eventId, body: $event);
        $this->expect(response: $replaced, ok: [200]);
    }

    /** Remove the event; one that is already gone (404, 410) is fine */
    public function remove(string $eventId): void
    {
        $response = $this->request(method: 'DELETE', url: $this->eventsUrl() . '/' . $eventId);
        $this->expect(response: $response, ok: [200, 204, 404, 410]);
    }

    private function eventsUrl(): string
    {
        return self::EVENTS_URL . \rawurlencode($this->calendarId) . '/events';
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array{code: int, body: string}
     */
    private function request(string $method, string $url, ?array $body = null): array
    {
        $headers = ['Authorization: Bearer ' . $this->token(), 'Content-Type: application/json'];
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ];
        if ($body !== null) $options[CURLOPT_POSTFIELDS] = \json_encode($body);

        return $this->curl(url: $url, options: $options);
    }

    private function token(): string
    {
        $haveToken = $this->accessToken !== null;
        if ($haveToken) return $this->accessToken;

        $response = $this->curl(url: self::TOKEN_URL, options: [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => \http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $this->signedJwt(),
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ]);
        $decoded = \json_decode($response['body'], associative: true);
        $token = \is_array($decoded) ? (string) ($decoded['access_token'] ?? '') : '';
        $noToken = $token === '';
        if ($noToken) throw new \RuntimeException('Google Calendar: no access token (' . $response['code'] . '): ' . $response['body']);

        return $this->accessToken = $token;
    }

    private function signedJwt(): string
    {
        $now = \time();
        $header = self::base64Url(\json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = self::base64Url(\json_encode([
            'iss' => $this->clientEmail,
            'scope' => self::SCOPE,
            'aud' => self::TOKEN_URL,
            'iat' => $now,
            'exp' => $now + 3600,
        ]));

        $signature = '';
        $signed = \openssl_sign($header . '.' . $claims, $signature, $this->privateKey, OPENSSL_ALGO_SHA256);
        $failed = $signed === false;
        if ($failed) throw new \RuntimeException('Google Calendar: the private key could not sign the request');

        return $header . '.' . $claims . '.' . self::base64Url($signature);
    }

    /**
     * @param array<int, mixed> $options
     * @return array{code: int, body: string}
     */
    private function curl(string $url, array $options): array
    {
        $ch = \curl_init($url);
        \curl_setopt_array($ch, $options);
        $body = \curl_exec($ch);
        $curlError = \curl_error($ch);
        $code = (int) \curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($curlError !== '') throw new \RuntimeException('Google Calendar curl error: ' . $curlError);

        return ['code' => $code, 'body' => (string) $body];
    }

    /**
     * @param array{code: int, body: string} $response
     * @param int[] $ok
     */
    private function expect(array $response, array $ok): void
    {
        $accepted = \in_array($response['code'], $ok, true);
        if ($accepted) return;

        throw new \RuntimeException('Google Calendar: HTTP ' . $response['code'] . ': ' . $response['body']);
    }

    private static function base64Url(string $data): string
    {
        return \rtrim(\strtr(\base64_encode($data), '+/', '-_'), '=');
    }
}
