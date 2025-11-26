<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Language;
use App\Models\Translation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Cache;

class LanguageController extends Controller
{
    /**
     * Show language selection page
     */
    public function index()
    {
        $languages = Language::active()->get();
        $currentLanguage = $this->getCurrentLanguage();
        
        return view('language.index', compact('languages', 'currentLanguage'));
    }

    /**
     * Change language
     */
    public function change($code)
    {
        $language = Language::where('code', $code)->active()->first();
        
        if (!$language) {
            return redirect()->back()->with('error', 'Dil bulunamadı.');
        }
        
        // Set language in session
        Session::put('locale', $code);
        App::setLocale($code);
        
        // Clear cache
        Cache::forget('translations_' . $code);
        
        return redirect()->back()->with('success', "Dil {$language->name} olarak değiştirildi.");
    }

    /**
     * Get current language
     */
    public function getCurrentLanguage()
    {
        $locale = Session::get('locale', config('app.locale'));
        return Language::where('code', $locale)->first() ?? Language::default()->first();
    }

    /**
     * Get translations for current language
     */
    public function getTranslations($group = 'general')
    {
        $locale = App::getLocale();
        $cacheKey = "translations_{$locale}_{$group}";
        
        return Cache::remember($cacheKey, 3600, function () use ($locale, $group) {
            $language = Language::where('code', $locale)->first();
            
            if (!$language) {
                return collect();
            }
            
            return $language->translations()
                ->where('group', $group)
                ->pluck('value', 'key');
        });
    }

    /**
     * Get all translations for admin
     */
    public function adminIndex()
    {
        $languages = Language::all();
        $currentLanguage = request('lang', config('app.locale'));
        $groups = Translation::distinct('group')->pluck('group');
        
        $translations = Language::where('code', $currentLanguage)
            ->first()
            ?->translations()
            ->groupBy('group')
            ->get();
        
        return view('admin.language.index', compact('languages', 'currentLanguage', 'groups', 'translations'));
    }

    /**
     * Update translations
     */
    public function updateTranslations(Request $request)
    {
        $request->validate([
            'language_id' => 'required|exists:languages,id',
            'translations' => 'required|array',
            'translations.*.key' => 'required|string',
            'translations.*.value' => 'required|string',
            'translations.*.group' => 'required|string'
        ]);
        
        $language = Language::findOrFail($request->language_id);
        
        foreach ($request->translations as $translation) {
            Translation::updateOrCreate(
                [
                    'language_id' => $language->id,
                    'key' => $translation['key'],
                    'group' => $translation['group']
                ],
                [
                    'value' => $translation['value'],
                    'is_html' => $translation['is_html'] ?? false
                ]
            );
        }
        
        // Clear cache
        Cache::forget('translations_' . $language->code);
        
        return response()->json([
            'success' => true,
            'message' => 'Çeviriler başarıyla güncellendi.'
        ]);
    }

    /**
     * Import translations from file
     */
    public function importTranslations(Request $request)
    {
        $request->validate([
            'language_id' => 'required|exists:languages,id',
            'file' => 'required|file|mimes:json,csv'
        ]);
        
        $language = Language::findOrFail($request->language_id);
        $file = $request->file('file');
        
        try {
            if ($file->getClientOriginalExtension() === 'json') {
                $this->importFromJson($language, $file);
            } else {
                $this->importFromCsv($language, $file);
            }
            
            // Clear cache
            Cache::forget('translations_' . $language->code);
            
            return response()->json([
                'success' => true,
                'message' => 'Çeviriler başarıyla içe aktarıldı.'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Çeviri içe aktarımı başarısız: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export translations to file
     */
    public function exportTranslations(Request $request)
    {
        $request->validate([
            'language_id' => 'required|exists:languages,id',
            'format' => 'required|in:json,csv'
        ]);
        
        $language = Language::findOrFail($request->language_id);
        $translations = $language->translations()
            ->orderBy('group')
            ->orderBy('key')
            ->get()
            ->groupBy('group');
        
        $filename = "translations_{$language->code}_" . now()->format('Y-m-d_H-i-s');
        
        if ($request->format === 'json') {
            return response()->json($translations)
                ->header('Content-Disposition', "attachment; filename={$filename}.json");
        } else {
            return $this->exportToCsv($translations, $filename);
        }
    }

    /**
     * Import from JSON file
     */
    private function importFromJson($language, $file)
    {
        $content = json_decode(file_get_contents($file->getPathname()), true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Geçersiz JSON dosyası.');
        }
        
        foreach ($content as $group => $translations) {
            foreach ($translations as $key => $value) {
                Translation::updateOrCreate(
                    [
                        'language_id' => $language->id,
                        'key' => $key,
                        'group' => $group
                    ],
                    [
                        'value' => $value,
                        'is_html' => false
                    ]
                );
            }
        }
    }

    /**
     * Import from CSV file
     */
    private function importFromCsv($language, $file)
    {
        $handle = fopen($file->getPathname(), 'r');
        $headers = fgetcsv($handle);
        
        while (($row = fgetcsv($handle)) !== false) {
            $data = array_combine($headers, $row);
            
            if (isset($data['key']) && isset($data['value']) && isset($data['group'])) {
                Translation::updateOrCreate(
                    [
                        'language_id' => $language->id,
                        'key' => $data['key'],
                        'group' => $data['group']
                    ],
                    [
                        'value' => $data['value'],
                        'is_html' => $data['is_html'] ?? false
                    ]
                );
            }
        }
        
        fclose($handle);
    }

    /**
     * Export to CSV
     */
    private function exportToCsv($translations, $filename)
    {
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename={$filename}.csv",
        ];
        
        $callback = function() use ($translations) {
            $file = fopen('php://output', 'w');
            
            // Write headers
            fputcsv($file, ['group', 'key', 'value', 'is_html']);
            
            // Write data
            foreach ($translations as $group => $groupTranslations) {
                foreach ($groupTranslations as $translation) {
                    fputcsv($file, [
                        $translation->group,
                        $translation->key,
                        $translation->value,
                        $translation->is_html ? '1' : '0'
                    ]);
                }
            }
            
            fclose($file);
        };
        
        return response()->stream($callback, 200, $headers);
    }

    /**
     * Get missing translations
     */
    public function getMissingTranslations(Request $request)
    {
        $request->validate([
            'source_language' => 'required|exists:languages,code',
            'target_language' => 'required|exists:languages,code'
        ]);
        
        $sourceLanguage = Language::where('code', $request->source_language)->first();
        $targetLanguage = Language::where('code', $request->target_language)->first();
        
        $sourceKeys = $sourceLanguage->translations()->pluck('key', 'id');
        $targetKeys = $targetLanguage->translations()->pluck('key', 'id');
        
        $missingKeys = $sourceKeys->diff($targetKeys);
        
        $missingTranslations = $sourceLanguage->translations()
            ->whereIn('id', $missingKeys->keys())
            ->get();
        
        return response()->json([
            'success' => true,
            'missing_translations' => $missingTranslations
        ]);
    }

    /**
     * Auto-translate missing translations
     */
    public function autoTranslate(Request $request)
    {
        $request->validate([
            'source_language' => 'required|exists:languages,code',
            'target_language' => 'required|exists:languages,code'
        ]);
        
        $sourceLanguage = Language::where('code', $request->source_language)->first();
        $targetLanguage = Language::where('code', $request->target_language)->first();
        
        $missingTranslations = $this->getMissingTranslations($request)->getData();
        
        $translatedCount = 0;
        
        foreach ($missingTranslations->missing_translations as $translation) {
            try {
                // Use Google Translate API or other translation service
                $translatedValue = $this->translateText(
                    $translation->value,
                    $sourceLanguage->code,
                    $targetLanguage->code
                );
                
                Translation::create([
                    'language_id' => $targetLanguage->id,
                    'key' => $translation->key,
                    'value' => $translatedValue,
                    'group' => $translation->group,
                    'is_html' => $translation->is_html
                ]);
                
                $translatedCount++;
            } catch (\Exception $e) {
                // Log error and continue
                \Log::error("Translation failed for key: {$translation->key}", [
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        // Clear cache
        Cache::forget('translations_' . $targetLanguage->code);
        
        return response()->json([
            'success' => true,
            'message' => "{$translatedCount} çeviri otomatik olarak tamamlandı."
        ]);
    }

    /**
     * Translate text using external service
     */
    private function translateText($text, $from, $to)
    {
        // This is a placeholder for Google Translate API integration
        // You would need to implement the actual API call here
        
        // For now, return the original text
        return $text;
    }
} 