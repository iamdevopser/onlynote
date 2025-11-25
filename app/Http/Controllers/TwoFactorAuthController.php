<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\TwoFactorAuth;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorAuthController extends Controller
{
    protected $google2fa;

    public function __construct()
    {
        $this->google2fa = new Google2FA();
    }

    /**
     * Show 2FA setup page
     */
    public function showSetup()
    {
        $user = Auth::user();
        
        // Check if 2FA is already enabled
        if ($user->twoFactorAuth && $user->twoFactorAuth->is_enabled) {
            return redirect()->route('profile.edit')->with('info', '2FA zaten etkin.');
        }

        // Get or create 2FA record
        $twoFactorAuth = $user->twoFactorAuth ?? new TwoFactorAuth(['user_id' => $user->id]);
        
        if (!$twoFactorAuth->secret_key) {
            $secret = $twoFactorAuth->generateSecretKey();
        } else {
            $secret = $twoFactorAuth->getDecryptedSecretKey();
        }

        $qrCodeUrl = $twoFactorAuth->getQRCodeUrl();
        $backupCodes = $twoFactorAuth->backup_codes ?? [];

        return view('auth.2fa.setup', compact('secret', 'qrCodeUrl', 'backupCodes'));
    }

    /**
     * Enable 2FA
     */
    public function enable(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required|string|size:6',
            'password' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = Auth::user();
        
        // Verify password
        if (!Hash::check($request->password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Şifre yanlış.'
            ], 422);
        }

        // Verify TOTP code
        $twoFactorAuth = $user->twoFactorAuth;
        if (!$twoFactorAuth || !$twoFactorAuth->verifyTOTP($request->code)) {
            return response()->json([
                'success' => false,
                'message' => 'Geçersiz 2FA kodu.'
            ], 422);
        }

        try {
            // Generate backup codes if not exists
            if (!$twoFactorAuth->hasBackupCodes()) {
                $twoFactorAuth->generateBackupCodes();
            }

            // Enable 2FA
            $twoFactorAuth->enable();

            return response()->json([
                'success' => true,
                'message' => '2FA başarıyla etkinleştirildi.',
                'backup_codes' => $twoFactorAuth->backup_codes
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '2FA etkinleştirilirken hata oluştu: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Disable 2FA
     */
    public function disable(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'password' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = Auth::user();
        
        // Verify password
        if (!Hash::check($request->password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Şifre yanlış.'
            ], 422);
        }

        try {
            $user->twoFactorAuth->disable();

            return response()->json([
                'success' => true,
                'message' => '2FA başarıyla devre dışı bırakıldı.'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '2FA devre dışı bırakılırken hata oluştu: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Show 2FA verification page
     */
    public function showVerification()
    {
        return view('auth.2fa.verify');
    }

    /**
     * Verify 2FA code
     */
    public function verify(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required|string|size:6'
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        $user = Auth::user();
        $twoFactorAuth = $user->twoFactorAuth;

        if (!$twoFactorAuth || !$twoFactorAuth->is_enabled) {
            return redirect()->route('login')->with('error', '2FA etkin değil.');
        }

        // Verify TOTP code
        if ($twoFactorAuth->verifyTOTP($request->code)) {
            $twoFactorAuth->updateLastUsed();
            
            // Store 2FA verification in session
            session(['2fa_verified' => true]);
            
            return redirect()->intended(route('dashboard'));
        }

        // Try backup code
        if ($twoFactorAuth->verifyBackupCode($request->code)) {
            $twoFactorAuth->updateLastUsed();
            session(['2fa_verified' => true]);
            
            return redirect()->intended(route('dashboard'))
                ->with('warning', 'Backup kod kullanıldı. Yeni backup kodlar oluşturmanız önerilir.');
        }

        return redirect()->back()
            ->with('error', 'Geçersiz 2FA kodu.')
            ->withInput();
    }

    /**
     * Generate new backup codes
     */
    public function generateBackupCodes(Request $request)
    {
        $user = Auth::user();
        
        if (!$user->twoFactorAuth || !$user->twoFactorAuth->is_enabled) {
            return response()->json([
                'success' => false,
                'message' => '2FA etkin değil.'
            ], 422);
        }

        try {
            $backupCodes = $user->twoFactorAuth->generateBackupCodes();

            return response()->json([
                'success' => true,
                'message' => 'Yeni backup kodlar oluşturuldu.',
                'backup_codes' => $backupCodes
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Backup kodlar oluşturulurken hata oluştu: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Show backup codes
     */
    public function showBackupCodes()
    {
        $user = Auth::user();
        
        if (!$user->twoFactorAuth || !$user->twoFactorAuth->is_enabled) {
            return redirect()->route('profile.edit')->with('error', '2FA etkin değil.');
        }

        $backupCodes = $user->twoFactorAuth->backup_codes ?? [];

        return view('auth.2fa.backup-codes', compact('backupCodes'));
    }

    /**
     * Check if 2FA is required
     */
    public static function isRequired()
    {
        $user = Auth::user();
        
        if (!$user) {
            return false;
        }

        return $user->twoFactorAuth && 
               $user->twoFactorAuth->is_enabled && 
               !session('2fa_verified');
    }
} 