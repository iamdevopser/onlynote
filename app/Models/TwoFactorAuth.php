<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class TwoFactorAuth extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'secret_key',
        'backup_codes',
        'is_enabled',
        'last_used_at'
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'last_used_at' => 'datetime',
        'backup_codes' => 'array'
    ];

    protected $hidden = [
        'secret_key'
    ];

    /**
     * Get the user that owns the 2FA
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Generate new secret key
     */
    public function generateSecretKey()
    {
        $secret = \PragmaRX\Google2FA\Google2FA::generateSecretKey();
        $this->secret_key = Crypt::encryptString($secret);
        $this->save();
        return $secret;
    }

    /**
     * Get decrypted secret key
     */
    public function getDecryptedSecretKey()
    {
        return Crypt::decryptString($this->secret_key);
    }

    /**
     * Generate backup codes
     */
    public function generateBackupCodes()
    {
        $codes = [];
        for ($i = 0; $i < 8; $i++) {
            $codes[] = strtoupper(substr(md5(uniqid()), 0, 8));
        }
        
        $this->backup_codes = $codes;
        $this->save();
        
        return $codes;
    }

    /**
     * Verify backup code
     */
    public function verifyBackupCode($code)
    {
        if (!$this->backup_codes || !in_array($code, $this->backup_codes)) {
            return false;
        }

        // Remove used backup code
        $this->backup_codes = array_diff($this->backup_codes, [$code]);
        $this->save();

        return true;
    }

    /**
     * Check if user has backup codes
     */
    public function hasBackupCodes()
    {
        return !empty($this->backup_codes);
    }

    /**
     * Get QR code URL for Google Authenticator
     */
    public function getQRCodeUrl()
    {
        $secret = $this->getDecryptedSecretKey();
        $user = $this->user;
        
        return \PragmaRX\Google2FA\Google2FA::getQRCodeUrl(
            config('app.name'),
            $user->email,
            $secret
        );
    }

    /**
     * Verify TOTP code
     */
    public function verifyTOTP($code)
    {
        $secret = $this->getDecryptedSecretKey();
        $google2fa = new \PragmaRX\Google2FA\Google2FA();
        
        return $google2fa->verifyKey($secret, $code, 2); // 2 minute window
    }

    /**
     * Enable 2FA
     */
    public function enable()
    {
        $this->is_enabled = true;
        $this->save();
    }

    /**
     * Disable 2FA
     */
    public function disable()
    {
        $this->is_enabled = false;
        $this->backup_codes = [];
        $this->save();
    }

    /**
     * Update last used timestamp
     */
    public function updateLastUsed()
    {
        $this->last_used_at = now();
        $this->save();
    }
} 