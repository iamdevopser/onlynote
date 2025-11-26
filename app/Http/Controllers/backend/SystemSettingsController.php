<?php

namespace App\Http\Controllers\backend;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class SystemSettingsController extends Controller
{
    /**
     * Display system settings page
     */
    public function index()
    {
        $settings = $this->getSystemSettings();
        return view('backend.admin.settings.index', compact('settings'));
    }

    /**
     * Update general settings
     */
    public function updateGeneral(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'site_name' => 'required|string|max:255',
            'site_description' => 'nullable|string|max:500',
            'site_keywords' => 'nullable|string|max:500',
            'site_url' => 'required|url',
            'admin_email' => 'required|email',
            'support_email' => 'required|email',
            'timezone' => 'required|string',
            'date_format' => 'required|string',
            'time_format' => 'required|string',
            'currency' => 'required|string|max:3',
            'currency_symbol' => 'required|string|max:5',
            'maintenance_mode' => 'boolean',
            'maintenance_message' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $settings = $request->only([
                'site_name', 'site_description', 'site_keywords', 'site_url',
                'admin_email', 'support_email', 'timezone', 'date_format',
                'time_format', 'currency', 'currency_symbol', 'maintenance_mode',
                'maintenance_message'
            ]);

            $this->saveSystemSettings($settings);
            
            // Clear cache
            Cache::forget('system_settings');

            return response()->json([
                'success' => true,
                'message' => 'Genel ayarlar başarıyla güncellendi'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ayarlar güncellenirken hata oluştu: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update email settings
     */
    public function updateEmail(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'mail_mailer' => 'required|string',
            'mail_host' => 'required|string',
            'mail_port' => 'required|integer',
            'mail_username' => 'required|string',
            'mail_password' => 'nullable|string',
            'mail_encryption' => 'required|string',
            'mail_from_address' => 'required|email',
            'mail_from_name' => 'required|string',
            'mailgun_domain' => 'nullable|string',
            'mailgun_secret' => 'nullable|string',
            'ses_key' => 'nullable|string',
            'ses_secret' => 'nullable|string',
            'ses_region' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $settings = $request->all();
            
            // Update .env file
            $this->updateEnvFile($settings);
            
            // Clear cache
            Cache::forget('system_settings');

            return response()->json([
                'success' => true,
                'message' => 'Email ayarları başarıyla güncellendi'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Email ayarları güncellenirken hata oluştu: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update payment settings
     */
    public function updatePayment(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'stripe_public_key' => 'nullable|string',
            'stripe_secret_key' => 'nullable|string',
            'stripe_webhook_secret' => 'nullable|string',
            'paypal_client_id' => 'nullable|string',
            'paypal_secret' => 'nullable|string',
            'paypal_mode' => 'nullable|string',
            'default_payment_method' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $settings = $request->all();
            $this->saveSystemSettings($settings);
            
            Cache::forget('system_settings');

            return response()->json([
                'success' => true,
                'message' => 'Ödeme ayarları başarıyla güncellendi'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ödeme ayarları güncellenirken hata oluştu: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update file upload settings
     */
    public function updateFileUpload(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'max_file_size' => 'required|integer|min:1|max:100',
            'allowed_file_types' => 'required|string',
            'image_quality' => 'required|integer|min:1|max:100',
            'storage_driver' => 'required|string',
            'aws_access_key_id' => 'nullable|string',
            'aws_secret_access_key' => 'nullable|string',
            'aws_default_region' => 'nullable|string',
            'aws_bucket' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $settings = $request->all();
            $this->saveSystemSettings($settings);
            
            Cache::forget('system_settings');

            return response()->json([
                'success' => true,
                'message' => 'Dosya yükleme ayarları başarıyla güncellendi'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Dosya yükleme ayarları güncellenirken hata oluştu: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update security settings
     */
    public function updateSecurity(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'session_lifetime' => 'required|integer|min:1|max:1440',
            'password_timeout' => 'required|integer|min:0|max:1440',
            'max_login_attempts' => 'required|integer|min:1|max:10',
            'lockout_time' => 'required|integer|min:1|max:60',
            'two_factor_enabled' => 'boolean',
            'recaptcha_enabled' => 'boolean',
            'recaptcha_site_key' => 'nullable|string',
            'recaptcha_secret_key' => 'nullable|string',
            'ip_whitelist' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $settings = $request->all();
            $this->saveSystemSettings($settings);
            
            Cache::forget('system_settings');

            return response()->json([
                'success' => true,
                'message' => 'Güvenlik ayarları başarıyla güncellendi'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Güvenlik ayarları güncellenirken hata oluştu: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Test email configuration
     */
    public function testEmail(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'test_email' => 'required|email'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Send test email
            \Mail::send('emails.test', ['user' => auth()->user()], function($message) use ($request) {
                $message->to($request->test_email)->subject('Test Email - LMS Platform');
            });

            return response()->json([
                'success' => true,
                'message' => 'Test email başarıyla gönderildi!'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Test email gönderilemedi: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Backup system settings
     */
    public function backup()
    {
        try {
            $settings = $this->getSystemSettings();
            $filename = 'system_settings_' . date('Y-m-d_H-i-s') . '.json';
            
            $headers = [
                'Content-Type' => 'application/json',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"'
            ];

            return response()->json($settings, 200, $headers);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Yedekleme yapılamadı: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Restore system settings
     */
    public function restore(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'settings_file' => 'required|file|mimes:json|max:1024'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $file = $request->file('settings_file');
            $content = file_get_contents($file->getPathname());
            $settings = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('Geçersiz JSON dosyası');
            }

            $this->saveSystemSettings($settings);
            Cache::forget('system_settings');

            return response()->json([
                'success' => true,
                'message' => 'Sistem ayarları başarıyla geri yüklendi'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Geri yükleme yapılamadı: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get system settings
     */
    private function getSystemSettings()
    {
        return Cache::remember('system_settings', 3600, function () {
            return [
                'site_name' => config('app.name', 'LMS Platform'),
                'site_description' => config('app.description', ''),
                'site_keywords' => config('app.keywords', ''),
                'site_url' => config('app.url', ''),
                'admin_email' => config('mail.from.address', ''),
                'support_email' => config('mail.support_email', ''),
                'timezone' => config('app.timezone', 'UTC'),
                'date_format' => config('app.date_format', 'd.m.Y'),
                'time_format' => config('app.time_format', 'H:i'),
                'currency' => config('app.currency', 'TRY'),
                'currency_symbol' => config('app.currency_symbol', '₺'),
                'maintenance_mode' => app()->isDownForMaintenance(),
                'maintenance_message' => '',
                'max_file_size' => config('filesystems.max_file_size', 10),
                'allowed_file_types' => config('filesystems.allowed_file_types', 'jpg,jpeg,png,gif,pdf,doc,docx'),
                'image_quality' => config('filesystems.image_quality', 80),
                'storage_driver' => config('filesystems.default', 'local'),
                'session_lifetime' => config('session.lifetime', 120),
                'password_timeout' => config('auth.password_timeout', 0),
                'max_login_attempts' => config('auth.max_login_attempts', 5),
                'lockout_time' => config('auth.lockout_time', 15),
                'two_factor_enabled' => config('auth.two_factor_enabled', false),
                'recaptcha_enabled' => config('services.recaptcha.enabled', false),
                'recaptcha_site_key' => config('services.recaptcha.site_key', ''),
                'recaptcha_secret_key' => config('services.recaptcha.secret_key', ''),
                'ip_whitelist' => config('auth.ip_whitelist', ''),
                'stripe_public_key' => config('services.stripe.key', ''),
                'stripe_secret_key' => config('services.stripe.secret', ''),
                'stripe_webhook_secret' => config('services.stripe.webhook_secret', ''),
                'paypal_client_id' => config('services.paypal.client_id', ''),
                'paypal_secret' => config('services.paypal.secret', ''),
                'paypal_mode' => config('services.paypal.mode', 'sandbox'),
                'default_payment_method' => config('services.default_payment_method', 'stripe')
            ];
        });
    }

    /**
     * Save system settings
     */
    private function saveSystemSettings(array $settings)
    {
        // Save to database or config file
        foreach ($settings as $key => $value) {
            // You can implement your own storage logic here
            // For now, we'll just store in cache
            Cache::put("setting_{$key}", $value, 86400);
        }
    }

    /**
     * Update .env file
     */
    private function updateEnvFile(array $settings)
    {
        $envPath = base_path('.env');
        
        if (!file_exists($envPath)) {
            throw new \Exception('.env dosyası bulunamadı');
        }

        $envContent = file_get_contents($envPath);
        
        foreach ($settings as $key => $value) {
            $envKey = strtoupper($key);
            $pattern = "/^{$envKey}=.*/m";
            
            if (preg_match($pattern, $envContent)) {
                $envContent = preg_replace($pattern, "{$envKey}={$value}", $envContent);
            } else {
                $envContent .= "\n{$envKey}={$value}";
            }
        }
        
        file_put_contents($envPath, $envContent);
    }
} 