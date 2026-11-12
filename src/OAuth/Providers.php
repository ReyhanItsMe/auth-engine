<?php

declare(strict_types=1);

namespace ReyhanItsMe\AuthEngine\OAuth;

/**
 * Built-in OAuth 2.0 provider definitions.
 *
 * "map" values are dot-paths into the user-info payload; a list means
 * "first non-empty". "email_verified" may also be a literal boolean.
 * Any key can be overridden per provider in config, and a completely
 * custom provider only needs the same keys.
 */
final class Providers
{
    private function __construct()
    {
    }

    /** @return array<string, mixed>|null */
    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        $oidc = ['id' => 'sub', 'email' => 'email', 'email_verified' => 'email_verified', 'name' => 'name', 'avatar' => 'picture'];

        return [
            'google' => [
                'name' => 'Google',
                'authorize_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
                'token_url' => 'https://oauth2.googleapis.com/token',
                'userinfo_url' => 'https://openidconnect.googleapis.com/v1/userinfo',
                'scopes' => ['openid', 'email', 'profile'],
                'pkce' => true,
                'map' => $oidc,
            ],
            'github' => [
                'name' => 'GitHub',
                'authorize_url' => 'https://github.com/login/oauth/authorize',
                'token_url' => 'https://github.com/login/oauth/access_token',
                'userinfo_url' => 'https://api.github.com/user',
                'emails_url' => 'https://api.github.com/user/emails',
                'scopes' => ['read:user', 'user:email'],
                'pkce' => true,
                'map' => ['id' => 'id', 'email' => 'email', 'email_verified' => false, 'name' => ['name', 'login'], 'avatar' => 'avatar_url'],
            ],
            'discord' => [
                'name' => 'Discord',
                'authorize_url' => 'https://discord.com/oauth2/authorize',
                'token_url' => 'https://discord.com/api/oauth2/token',
                'userinfo_url' => 'https://discord.com/api/users/@me',
                'scopes' => ['identify', 'email'],
                'pkce' => true,
                'map' => ['id' => 'id', 'email' => 'email', 'email_verified' => 'verified', 'name' => ['global_name', 'username'], 'avatar' => null],
            ],
            'apple' => [
                'name' => 'Apple',
                'authorize_url' => 'https://appleid.apple.com/auth/authorize',
                'token_url' => 'https://appleid.apple.com/auth/token',
                'userinfo_url' => null, // the profile is read from the id_token
                'scopes' => ['name', 'email'],
                'pkce' => false,
                'authorize_params' => ['response_mode' => 'form_post'],
                'map' => ['id' => 'sub', 'email' => 'email', 'email_verified' => 'email_verified', 'name' => 'name', 'avatar' => null],
            ],
            'x' => [
                'name' => 'X',
                'authorize_url' => 'https://x.com/i/oauth2/authorize',
                'token_url' => 'https://api.x.com/2/oauth2/token',
                'userinfo_url' => 'https://api.x.com/2/users/me?user.fields=profile_image_url',
                'scopes' => ['users.read', 'tweet.read'],
                'pkce' => true,
                'token_auth' => 'basic',
                'map' => ['id' => 'data.id', 'email' => null, 'email_verified' => false, 'name' => ['data.name', 'data.username'], 'avatar' => 'data.profile_image_url'],
            ],
            'facebook' => [
                'name' => 'Facebook',
                'authorize_url' => 'https://www.facebook.com/v19.0/dialog/oauth',
                'token_url' => 'https://graph.facebook.com/v19.0/oauth/access_token',
                'userinfo_url' => 'https://graph.facebook.com/v19.0/me?fields=id,name,email,picture.type(large)',
                'scopes' => ['email', 'public_profile'],
                'scope_separator' => ',',
                'pkce' => false,
                'map' => ['id' => 'id', 'email' => 'email', 'email_verified' => false, 'name' => 'name', 'avatar' => 'picture.data.url'],
            ],
            'microsoft' => [
                'name' => 'Microsoft',
                'authorize_url' => 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize',
                'token_url' => 'https://login.microsoftonline.com/common/oauth2/v2.0/token',
                'userinfo_url' => 'https://graph.microsoft.com/oidc/userinfo',
                'scopes' => ['openid', 'email', 'profile'],
                'pkce' => true,
                'map' => ['email_verified' => false] + $oidc,
            ],
            'gitlab' => [
                'name' => 'GitLab',
                'authorize_url' => 'https://gitlab.com/oauth/authorize',
                'token_url' => 'https://gitlab.com/oauth/token',
                'userinfo_url' => 'https://gitlab.com/oauth/userinfo',
                'scopes' => ['openid', 'email', 'profile'],
                'pkce' => true,
                'map' => $oidc,
            ],
            'linkedin' => [
                'name' => 'LinkedIn',
                'authorize_url' => 'https://www.linkedin.com/oauth/v2/authorization',
                'token_url' => 'https://www.linkedin.com/oauth/v2/accessToken',
                'userinfo_url' => 'https://api.linkedin.com/v2/userinfo',
                'scopes' => ['openid', 'profile', 'email'],
                'pkce' => false,
                'map' => $oidc,
            ],
            'spotify' => [
                'name' => 'Spotify',
                'authorize_url' => 'https://accounts.spotify.com/authorize',
                'token_url' => 'https://accounts.spotify.com/api/token',
                'userinfo_url' => 'https://api.spotify.com/v1/me',
                'scopes' => ['user-read-email'],
                'pkce' => true,
                'map' => ['id' => 'id', 'email' => 'email', 'email_verified' => false, 'name' => 'display_name', 'avatar' => 'images.0.url'],
            ],
        ];
    }
}
