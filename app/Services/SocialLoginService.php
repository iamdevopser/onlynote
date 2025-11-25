<?php

namespace App\Services;

use App\Models\User;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class SocialLoginService
{
    protected $googleClientId;
    protected $googleClientSecret;
    protected $facebookAppId;
    protected $facebookAppSecret;
    
    public function __construct()
    {
        $this->googleClientId = config('services.google.client_id');
        $this->googleClientSecret = config('services.google.client_secret');
        $this->facebookAppId = config('services.facebook.client_id');
        $this->facebookAppSecret = config('services.facebook.client_secret');
    }

    /**
     * Handle Google OAuth login
     */
    public function handleGoogleLogin($code)
    {
        try {
            // Exchange authorization code for access token
            $tokenResponse = Http::post('https://oauth2.googleapis.com/token', [
                'client_id' => $this->googleClientId,
                'client_secret' => $this->googleClientSecret,
                'code' => $code,
                'grant_type' => 'authorization_code',
                'redirect_uri' => config('services.google.redirect')
            ]);

            if (!$tokenResponse->successful()) {
                throw new \Exception('Google token exchange failed: ' . $tokenResponse->body());
            }

            $tokenData = $tokenResponse->json();
            $accessToken = $tokenData['access_token'];

            // Get user information from Google
            $userResponse = Http::withHeaders([
                'Authorization' => 'Bearer ' . $accessToken
            ])->get('https://www.googleapis.com/oauth2/v2/userinfo');

            if (!$userResponse->successful()) {
                throw new \Exception('Failed to get Google user info');
            }

            $googleUser = $userResponse->json();

            // Find or create user
            $user = $this->findOrCreateUser($googleUser, 'google');

            // Create or update social account
            $this->createOrUpdateSocialAccount($user, $googleUser, 'google', $accessToken);

            return $user;

        } catch (\Exception $e) {
            Log::error('Google login error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Handle Facebook OAuth login
     */
    public function handleFacebookLogin($code)
    {
        try {
            // Exchange authorization code for access token
            $tokenResponse = Http::get('https://graph.facebook.com/v12.0/oauth/access_token', [
                'client_id' => $this->facebookAppId,
                'client_secret' => $this->facebookAppSecret,
                'code' => $code,
                'redirect_uri' => config('services.facebook.redirect')
            ]);

            if (!$tokenResponse->successful()) {
                throw new \Exception('Facebook token exchange failed: ' . $tokenResponse->body());
            }

            $tokenData = $tokenResponse->json();
            $accessToken = $tokenData['access_token'];

            // Get user information from Facebook
            $userResponse = Http::get('https://graph.facebook.com/me', [
                'fields' => 'id,name,email,picture',
                'access_token' => $accessToken
            ]);

            if (!$userResponse->successful()) {
                throw new \Exception('Failed to get Facebook user info');
            }

            $facebookUser = $userResponse->json();

            // Find or create user
            $user = $this->findOrCreateUser($facebookUser, 'facebook');

            // Create or update social account
            $this->createOrUpdateSocialAccount($user, $facebookUser, 'facebook', $accessToken);

            return $user;

        } catch (\Exception $e) {
            Log::error('Facebook login error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Handle Apple OAuth login
     */
    public function handleAppleLogin($identityToken)
    {
        try {
            // Verify Apple identity token
            $appleUser = $this->verifyAppleIdentityToken($identityToken);

            // Find or create user
            $user = $this->findOrCreateUser($appleUser, 'apple');

            // Create or update social account
            $this->createOrUpdateSocialAccount($user, $appleUser, 'apple', null);

            return $user;

        } catch (\Exception $e) {
            Log::error('Apple login error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Find or create user from social login
     */
    private function findOrCreateUser($socialUser, $provider)
    {
        // First, try to find user by social account
        $socialAccount = SocialAccount::where('provider', $provider)
            ->where('provider_user_id', $socialUser['id'])
            ->first();

        if ($socialAccount) {
            return $socialAccount->user;
        }

        // Try to find user by email
        if (isset($socialUser['email'])) {
            $user = User::where('email', $socialUser['email'])->first();
            
            if ($user) {
                // Link existing user account with social account
                $this->createOrUpdateSocialAccount($user, $socialUser, $provider, null);
                return $user;
            }
        }

        // Create new user
        $userData = [
            'name' => $socialUser['name'] ?? $socialUser['first_name'] . ' ' . $socialUser['last_name'],
            'email' => $socialUser['email'] ?? $this->generateUniqueEmail($socialUser['id'], $provider),
            'password' => Hash::make(Str::random(16)),
            'email_verified_at' => now(), // Social login users are pre-verified
            'role' => 'user',
            'social_login_provider' => $provider
        ];

        $user = User::create($userData);

        // Send welcome email for new social users
        $this->sendWelcomeEmail($user, $provider);

        return $user;
    }

    /**
     * Create or update social account
     */
    private function createOrUpdateSocialAccount($user, $socialUser, $provider, $accessToken = null)
    {
        $socialAccount = SocialAccount::updateOrCreate(
            [
                'user_id' => $user->id,
                'provider' => $provider,
                'provider_user_id' => $socialUser['id']
            ],
            [
                'provider_user_email' => $socialUser['email'] ?? null,
                'provider_user_name' => $socialUser['name'] ?? null,
                'access_token' => $accessToken,
                'refresh_token' => $socialUser['refresh_token'] ?? null,
                'expires_at' => isset($socialUser['expires_in']) ? now()->addSeconds($socialUser['expires_in']) : null,
                'last_used_at' => now()
            ]
        );

        return $socialAccount;
    }

    /**
     * Generate unique email for users without email
     */
    private function generateUniqueEmail($providerId, $provider)
    {
        $baseEmail = "user_{$provider}_{$providerId}@" . config('app.domain', 'example.com');
        
        $counter = 1;
        $email = $baseEmail;
        
        while (User::where('email', $email)->exists()) {
            $email = "user_{$provider}_{$providerId}_{$counter}@" . config('app.domain', 'example.com');
            $counter++;
        }
        
        return $email;
    }

    /**
     * Verify Apple identity token
     */
    private function verifyAppleIdentityToken($identityToken)
    {
        // This is a simplified verification
        // In production, you should verify the JWT token with Apple's public keys
        
        $tokenParts = explode('.', $identityToken);
        if (count($tokenParts) !== 3) {
            throw new \Exception('Invalid Apple identity token format');
        }

        $payload = json_decode(base64_decode($tokenParts[1]), true);
        
        if (!$payload) {
            throw new \Exception('Invalid Apple identity token payload');
        }

        return [
            'id' => $payload['sub'] ?? uniqid('apple_'),
            'email' => $payload['email'] ?? null,
            'name' => $payload['name'] ?? 'Apple User'
        ];
    }

    /**
     * Send welcome email for new social users
     */
    private function sendWelcomeEmail($user, $provider)
    {
        try {
            $providerNames = [
                'google' => 'Google',
                'facebook' => 'Facebook',
                'apple' => 'Apple'
            ];

            $providerName = $providerNames[$provider] ?? $provider;

            // Send welcome email
            \Mail::to($user->email)->send(new \App\Mail\SocialLoginWelcomeEmail($user, $providerName));

        } catch (\Exception $e) {
            Log::error('Failed to send welcome email: ' . $e->getMessage());
        }
    }

    /**
     * Link existing account with social provider
     */
    public function linkSocialAccount($userId, $provider, $socialUserData)
    {
        $user = User::findOrFail($userId);
        
        // Check if social account already exists
        $existingAccount = SocialAccount::where('provider', $provider)
            ->where('provider_user_id', $socialUserData['id'])
            ->first();

        if ($existingAccount && $existingAccount->user_id !== $userId) {
            throw new \Exception('This social account is already linked to another user');
        }

        // Create or update social account
        $this->createOrUpdateSocialAccount($user, $socialUserData, $provider);

        return $user;
    }

    /**
     * Unlink social account
     */
    public function unlinkSocialAccount($userId, $provider)
    {
        $user = User::findOrFail($userId);
        
        $socialAccount = $user->socialAccounts()
            ->where('provider', $provider)
            ->first();

        if (!$socialAccount) {
            throw new \Exception('Social account not found');
        }

        // Check if user has other login methods
        $otherAccounts = $user->socialAccounts()->where('provider', '!=', $provider)->count();
        $hasPassword = !empty($user->password);

        if ($otherAccounts === 0 && !$hasPassword) {
            throw new \Exception('Cannot unlink last login method. Please set a password first.');
        }

        $socialAccount->delete();

        return true;
    }

    /**
     * Get user's social accounts
     */
    public function getUserSocialAccounts($userId)
    {
        $user = User::findOrFail($userId);
        
        return $user->socialAccounts()->get()->map(function ($account) {
            return [
                'provider' => $account->provider,
                'provider_user_id' => $account->provider_user_id,
                'provider_user_email' => $account->provider_user_email,
                'provider_user_name' => $account->provider_user_name,
                'linked_at' => $account->created_at,
                'last_used_at' => $account->last_used_at
            ];
        });
    }

    /**
     * Refresh social access token
     */
    public function refreshAccessToken($userId, $provider)
    {
        $socialAccount = SocialAccount::where('user_id', $userId)
            ->where('provider', $provider)
            ->first();

        if (!$socialAccount || !$socialAccount->refresh_token) {
            throw new \Exception('No refresh token available');
        }

        try {
            switch ($provider) {
                case 'google':
                    $response = Http::post('https://oauth2.googleapis.com/token', [
                        'client_id' => $this->googleClientId,
                        'client_secret' => $this->googleClientSecret,
                        'refresh_token' => $socialAccount->refresh_token,
                        'grant_type' => 'refresh_token'
                    ]);
                    break;

                case 'facebook':
                    // Facebook doesn't provide refresh tokens, so we need to re-authenticate
                    throw new \Exception('Facebook requires re-authentication');
                    break;

                default:
                    throw new \Exception('Unsupported provider for token refresh');
            }

            if ($response->successful()) {
                $tokenData = $response->json();
                
                $socialAccount->update([
                    'access_token' => $tokenData['access_token'],
                    'expires_at' => isset($tokenData['expires_in']) ? now()->addSeconds($tokenData['expires_in']) : null,
                    'last_used_at' => now()
                ]);

                return $socialAccount;
            }

            throw new \Exception('Failed to refresh access token');

        } catch (\Exception $e) {
            Log::error("Failed to refresh {$provider} access token: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Check if social account is expired
     */
    public function isSocialAccountExpired($userId, $provider)
    {
        $socialAccount = SocialAccount::where('user_id', $userId)
            ->where('provider', $provider)
            ->first();

        if (!$socialAccount || !$socialAccount->expires_at) {
            return false;
        }

        return now()->isAfter($socialAccount->expires_at);
    }

    /**
     * Get social login statistics
     */
    public function getSocialLoginStats()
    {
        $stats = [
            'total_social_users' => SocialAccount::distinct('user_id')->count(),
            'providers' => SocialAccount::selectRaw('provider, COUNT(*) as count')
                ->groupBy('provider')
                ->get()
                ->keyBy('provider'),
            'recent_logins' => SocialAccount::where('last_used_at', '>=', now()->subDays(7))
                ->count(),
            'linked_accounts' => User::whereHas('socialAccounts')->count()
        ];

        return $stats;
    }
} 