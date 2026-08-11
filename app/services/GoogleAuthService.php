<?php

namespace App\Services;

use Google\Client as GoogleClient;

/**
 * OAuth2 client for Google SSO. Mirrors the sibling hr-advws app's
 * LineAuthService shape (getAuthUrl/exchangeCode/verify), but delegates
 * token exchange and ID-token verification to the official google/apiclient
 * library instead of hand-rolling JWKS/signature checks.
 */
class GoogleAuthService
{
    private string $hostedDomain;
    private GoogleClient $client;

    public function __construct(
        string $clientId,
        string $clientSecret,
        string $redirectUri,
        string $hostedDomain
    ) {
        foreach (['GOOGLE_CLIENT_ID' => $clientId, 'GOOGLE_CLIENT_SECRET' => $clientSecret, 'GOOGLE_REDIRECT_URI' => $redirectUri] as $name => $value) {
            if ($value === '' || str_starts_with($value, 'CHANGE_ME')) {
                throw new \RuntimeException("$name is not set (or still a CHANGE_ME placeholder) in the environment.");
            }
        }

        $this->hostedDomain = $hostedDomain;

        $this->client = new GoogleClient();
        $this->client->setClientId($clientId);
        $this->client->setClientSecret($clientSecret);
        $this->client->setRedirectUri($redirectUri);
        $this->client->setScopes(['openid', 'email', 'profile']);
        $this->client->setHostedDomain($hostedDomain);
        $this->client->setAccessType('online');
        $this->client->setPrompt('select_account');
    }

    public function getAuthUrl(string $state): string
    {
        $this->client->setState($state);
        return $this->client->createAuthUrl();
    }

    /**
     * @return array{id_token: string, access_token: string}
     */
    public function exchangeCode(string $code): array
    {
        $token = $this->client->fetchAccessTokenWithAuthCode($code);

        if (isset($token['error'])) {
            throw new \RuntimeException('Google token exchange failed: ' . $token['error']);
        }

        return $token;
    }

    /**
     * Verifies the ID token's signature/audience/issuer and returns its claims.
     * Callers MUST separately check claims['hd'] === expected domain — the
     * hosted-domain hint set on the client only affects the consent screen,
     * it is not enforced by verifyIdToken() itself.
     */
    public function verifyIdTokenAndGetClaims(string $idToken): ?array
    {
        $claims = $this->client->verifyIdToken($idToken);
        return $claims ?: null;
    }

    public function isAllowedHostedDomain(array $claims): bool
    {
        return ($claims['hd'] ?? null) === $this->hostedDomain;
    }
}
