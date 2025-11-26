<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Markdown;
use Illuminate\Support\Str;

class DocumentationService
{
    protected $docsPath;
    protected $cacheEnabled;
    protected $cacheTtl;
    
    public function __construct()
    {
        $this->docsPath = base_path('docs');
        $this->cacheEnabled = config('documentation.cache_enabled', true);
        $this->cacheTtl = config('documentation.cache_ttl', 3600);
        
        // Create docs directory if it doesn't exist
        if (!is_dir($this->docsPath)) {
            mkdir($this->docsPath, 0755, true);
        }
    }

    /**
     * Get documentation structure
     */
    public function getDocumentationStructure()
    {
        $cacheKey = 'docs_structure';
        
        if ($this->cacheEnabled && Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $structure = [
            'user_guide' => $this->getUserGuideStructure(),
            'admin_guide' => $this->getAdminGuideStructure(),
            'developer_guide' => $this->getDeveloperGuideStructure(),
            'api_documentation' => $this->getAPIDocumentationStructure(),
            'troubleshooting' => $this->getTroubleshootingStructure()
        ];

        if ($this->cacheEnabled) {
            Cache::put($cacheKey, $structure, $this->cacheTtl);
        }

        return $structure;
    }

    /**
     * Get user guide structure
     */
    private function getUserGuideStructure()
    {
        $userGuidePath = $this->docsPath . '/user-guide';
        
        if (!is_dir($userGuidePath)) {
            return $this->createDefaultUserGuide();
        }

        return $this->scanDirectory($userGuidePath);
    }

    /**
     * Get admin guide structure
     */
    private function getAdminGuideStructure()
    {
        $adminGuidePath = $this->docsPath . '/admin-guide';
        
        if (!is_dir($adminGuidePath)) {
            return $this->createDefaultAdminGuide();
        }

        return $this->scanDirectory($adminGuidePath);
    }

    /**
     * Get developer guide structure
     */
    private function getDeveloperGuideStructure()
    {
        $developerGuidePath = $this->docsPath . '/developer-guide';
        
        if (!is_dir($developerGuidePath)) {
            return $this->createDefaultDeveloperGuide();
        }

        return $this->scanDirectory($developerGuidePath);
    }

    /**
     * Get API documentation structure
     */
    private function getAPIDocumentationStructure()
    {
        $apiDocsPath = $this->docsPath . '/api';
        
        if (!is_dir($apiDocsPath)) {
            return $this->createDefaultAPIDocumentation();
        }

        return $this->scanDirectory($apiDocsPath);
    }

    /**
     * Get troubleshooting structure
     */
    private function getTroubleshootingStructure()
    {
        $troubleshootingPath = $this->docsPath . '/troubleshooting';
        
        if (!is_dir($troubleshootingPath)) {
            return $this->createDefaultTroubleshooting();
        }

        return $this->scanDirectory($troubleshootingPath);
    }

    /**
     * Scan directory for documentation files
     */
    private function scanDirectory($path)
    {
        $structure = [];
        
        if (!is_dir($path)) {
            return $structure;
        }

        $files = File::files($path);
        $directories = File::directories($path);

        // Process markdown files
        foreach ($files as $file) {
            if (pathinfo($file, PATHINFO_EXTENSION) === 'md') {
                $filename = pathinfo($file, PATHINFO_FILENAME);
                $structure[$filename] = [
                    'type' => 'file',
                    'path' => $file,
                    'title' => $this->extractTitleFromMarkdown($file),
                    'last_modified' => filemtime($file)
                ];
            }
        }

        // Process subdirectories
        foreach ($directories as $directory) {
            $dirname = basename($directory);
            $structure[$dirname] = [
                'type' => 'directory',
                'path' => $directory,
                'children' => $this->scanDirectory($directory)
            ];
        }

        return $structure;
    }

    /**
     * Extract title from markdown file
     */
    private function extractTitleFromMarkdown($filePath)
    {
        $content = file_get_contents($filePath);
        $lines = explode("\n", $content);
        
        foreach ($lines as $line) {
            if (preg_match('/^#\s+(.+)$/', trim($line), $matches)) {
                return trim($matches[1]);
            }
        }
        
        return pathinfo($filePath, PATHINFO_FILENAME);
    }

    /**
     * Get documentation content
     */
    public function getDocumentationContent($path)
    {
        $cacheKey = 'docs_content_' . md5($path);
        
        if ($this->cacheEnabled && Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $fullPath = $this->docsPath . '/' . $path;
        
        if (!file_exists($fullPath)) {
            return [
                'content' => null,
                'error' => 'Documentation file not found'
            ];
        }

        $content = file_get_contents($fullPath);
        $html = Markdown::parse($content);
        
        $result = [
            'content' => $html,
            'raw_content' => $content,
            'title' => $this->extractTitleFromMarkdown($fullPath),
            'last_modified' => filemtime($fullPath),
            'file_size' => filesize($fullPath)
        ];

        if ($this->cacheEnabled) {
            Cache::put($cacheKey, $result, $this->cacheTtl);
        }

        return $result;
    }

    /**
     * Search documentation
     */
    public function searchDocumentation($query, $filters = [])
    {
        $cacheKey = 'docs_search_' . md5($query . serialize($filters));
        
        if ($this->cacheEnabled && Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $results = [];
        $query = strtolower($query);
        
        $this->searchInDirectory($this->docsPath, $query, $results, $filters);
        
        // Sort results by relevance
        usort($results, function ($a, $b) {
            return $b['relevance'] <=> $a['relevance'];
        });

        if ($this->cacheEnabled) {
            Cache::put($cacheKey, $results, $this->cacheTtl);
        }

        return $results;
    }

    /**
     * Search in directory recursively
     */
    private function searchInDirectory($path, $query, &$results, $filters)
    {
        if (!is_dir($path)) {
            return;
        }

        $files = File::files($path);
        $directories = File::directories($path);

        // Search in markdown files
        foreach ($files as $file) {
            if (pathinfo($file, PATHINFO_EXTENSION) === 'md') {
                $this->searchInFile($file, $query, $results, $filters);
            }
        }

        // Search in subdirectories
        foreach ($directories as $directory) {
            $this->searchInDirectory($directory, $query, $results, $filters);
        }
    }

    /**
     * Search in single file
     */
    private function searchInFile($filePath, $query, &$results, $filters)
    {
        $content = file_get_contents($filePath);
        $lowerContent = strtolower($content);
        
        // Check if query exists in content
        if (strpos($lowerContent, $query) === false) {
            return;
        }

        // Calculate relevance score
        $relevance = $this->calculateRelevance($content, $query);
        
        // Apply filters
        if (isset($filters['min_relevance']) && $relevance < $filters['min_relevance']) {
            return;
        }

        $relativePath = str_replace($this->docsPath . '/', '', $filePath);
        
        $results[] = [
            'file_path' => $relativePath,
            'title' => $this->extractTitleFromMarkdown($filePath),
            'excerpt' => $this->generateExcerpt($content, $query),
            'relevance' => $relevance,
            'last_modified' => filemtime($filePath)
        ];
    }

    /**
     * Calculate search relevance
     */
    private function calculateRelevance($content, $query)
    {
        $lowerContent = strtolower($content);
        $queryWords = explode(' ', $query);
        $relevance = 0;

        foreach ($queryWords as $word) {
            if (strlen($word) < 3) continue;
            
            $count = substr_count($lowerContent, $word);
            $relevance += $count;
        }

        // Boost relevance for title matches
        $title = $this->extractTitleFromMarkdown('temp.md');
        $lowerTitle = strtolower($title);
        
        foreach ($queryWords as $word) {
            if (strlen($word) < 3) continue;
            
            if (strpos($lowerTitle, $word) !== false) {
                $relevance += 10;
            }
        }

        return $relevance;
    }

    /**
     * Generate search excerpt
     */
    private function generateExcerpt($content, $query, $length = 200)
    {
        $lowerContent = strtolower($content);
        $queryWords = explode(' ', $query);
        
        // Find the first occurrence of any query word
        $pos = -1;
        foreach ($queryWords as $word) {
            if (strlen($word) < 3) continue;
            
            $wordPos = strpos($lowerContent, $word);
            if ($wordPos !== false && ($pos === -1 || $wordPos < $pos)) {
                $pos = $wordPos;
            }
        }

        if ($pos === -1) {
            return substr($content, 0, $length) . '...';
        }

        $start = max(0, $pos - $length / 2);
        $excerpt = substr($content, $start, $length);
        
        if ($start > 0) {
            $excerpt = '...' . $excerpt;
        }
        
        if (strlen($excerpt) === $length) {
            $excerpt .= '...';
        }

        return $excerpt;
    }

    /**
     * Create default user guide
     */
    private function createDefaultUserGuide()
    {
        $userGuidePath = $this->docsPath . '/user-guide';
        
        if (!is_dir($userGuidePath)) {
            mkdir($userGuidePath, 0755, true);
        }

        $defaultFiles = [
            'getting-started.md' => $this->getDefaultGettingStartedContent(),
            'courses.md' => $this->getDefaultCoursesContent(),
            'quizzes.md' => $this->getDefaultQuizzesContent(),
            'profile.md' => $this->getDefaultProfileContent(),
            'certificates.md' => $this->getDefaultCertificatesContent()
        ];

        foreach ($defaultFiles as $filename => $content) {
            $filePath = $userGuidePath . '/' . $filename;
            if (!file_exists($filePath)) {
                file_put_contents($filePath, $content);
            }
        }

        return $this->scanDirectory($userGuidePath);
    }

    /**
     * Create default admin guide
     */
    private function createDefaultAdminGuide()
    {
        $adminGuidePath = $this->docsPath . '/admin-guide';
        
        if (!is_dir($adminGuidePath)) {
            mkdir($adminGuidePath, 0755, true);
        }

        $defaultFiles = [
            'dashboard.md' => $this->getDefaultDashboardContent(),
            'users.md' => $this->getDefaultUsersContent(),
            'courses.md' => $this->getDefaultAdminCoursesContent(),
            'reports.md' => $this->getDefaultReportsContent(),
            'settings.md' => $this->getDefaultSettingsContent()
        ];

        foreach ($defaultFiles as $filename => $content) {
            $filePath = $adminGuidePath . '/' . $filename;
            if (!file_exists($filePath)) {
                file_put_contents($filePath, $content);
            }
        }

        return $this->scanDirectory($adminGuidePath);
    }

    /**
     * Create default developer guide
     */
    private function createDefaultDeveloperGuide()
    {
        $developerGuidePath = $this->docsPath . '/developer-guide';
        
        if (!is_dir($developerGuidePath)) {
            mkdir($developerGuidePath, 0755, true);
        }

        $defaultFiles = [
            'installation.md' => $this->getDefaultInstallationContent(),
            'architecture.md' => $this->getDefaultArchitectureContent(),
            'api.md' => $this->getDefaultAPIContent(),
            'plugins.md' => $this->getDefaultPluginsContent(),
            'deployment.md' => $this->getDefaultDeploymentContent()
        ];

        foreach ($defaultFiles as $filename => $content) {
            $filePath = $developerGuidePath . '/' . $filename;
            if (!file_exists($filePath)) {
                file_put_contents($filePath, $content);
            }
        }

        return $this->scanDirectory($developerGuidePath);
    }

    /**
     * Create default API documentation
     */
    private function createDefaultAPIDocumentation()
    {
        $apiDocsPath = $this->docsPath . '/api';
        
        if (!is_dir($apiDocsPath)) {
            mkdir($apiDocsPath, 0755, true);
        }

        $defaultFiles = [
            'authentication.md' => $this->getDefaultAuthContent(),
            'endpoints.md' => $this->getDefaultEndpointsContent(),
            'errors.md' => $this->getDefaultErrorsContent(),
            'examples.md' => $this->getDefaultExamplesContent()
        ];

        foreach ($defaultFiles as $filename => $content) {
            $filePath = $apiDocsPath . '/' . $filename;
            if (!file_exists($filePath)) {
                file_put_contents($filePath, $content);
            }
        }

        return $this->scanDirectory($apiDocsPath);
    }

    /**
     * Create default troubleshooting
     */
    private function createDefaultTroubleshooting()
    {
        $troubleshootingPath = $this->docsPath . '/troubleshooting';
        
        if (!is_dir($troubleshootingPath)) {
            mkdir($troubleshootingPath, 0755, true);
        }

        $defaultFiles = [
            'common-issues.md' => $this->getDefaultCommonIssuesContent(),
            'error-codes.md' => $this->getDefaultErrorCodesContent(),
            'performance.md' => $this->getDefaultPerformanceContent(),
            'support.md' => $this->getDefaultSupportContent()
        ];

        foreach ($defaultFiles as $filename => $content) {
            $filePath = $troubleshootingPath . '/' . $filename;
            if (!file_exists($filePath)) {
                file_put_contents($filePath, $content);
            }
        }

        return $this->scanDirectory($troubleshootingPath);
    }

    /**
     * Get default content templates
     */
    private function getDefaultGettingStartedContent()
    {
        return "# Getting Started\n\nWelcome to LMS Platform! This guide will help you get started with your learning journey.\n\n## First Steps\n\n1. Create your account\n2. Browse available courses\n3. Enroll in your first course\n4. Start learning!\n\n## Need Help?\n\nIf you need assistance, please contact our support team.";
    }

    private function getDefaultCoursesContent()
    {
        return "# Courses\n\nLearn at your own pace with our comprehensive course catalog.\n\n## Course Features\n\n- Video lessons\n- Interactive quizzes\n- Downloadable materials\n- Progress tracking\n- Certificates upon completion\n\n## How to Enroll\n\n1. Browse course catalog\n2. Read course description\n3. Click 'Enroll Now'\n4. Complete payment\n5. Start learning immediately";
    }

    private function getDefaultQuizzesContent()
    {
        return "# Quizzes\n\nTest your knowledge with our interactive quiz system.\n\n## Quiz Types\n\n- Multiple choice questions\n- True/False questions\n- Fill in the blanks\n- Essay questions\n\n## Taking Quizzes\n\n1. Navigate to quiz section\n2. Read instructions carefully\n3. Answer all questions\n4. Submit when ready\n5. Review results";
    }

    private function getDefaultProfileContent()
    {
        return "# Profile Management\n\nManage your account and track your progress.\n\n## Profile Settings\n\n- Personal information\n- Profile picture\n- Notification preferences\n- Privacy settings\n\n## Progress Tracking\n\n- Course completion status\n- Quiz scores\n- Learning time\n- Achievements and badges";
    }

    private function getDefaultCertificatesContent()
    {
        return "# Certificates\n\nEarn certificates upon course completion.\n\n## Certificate Types\n\n- Course completion certificates\n- Achievement certificates\n- Specialization certificates\n\n## How to Earn\n\n1. Complete course requirements\n2. Pass final assessment\n3. Download certificate\n4. Share on social media";
    }

    private function getDefaultDashboardContent()
    {
        return "# Admin Dashboard\n\nManage your LMS platform from the admin dashboard.\n\n## Key Features\n\n- User management\n- Course oversight\n- Analytics and reports\n- System settings\n- Content moderation\n\n## Getting Started\n\n1. Access admin panel\n2. Review system overview\n3. Configure settings\n4. Monitor activity";
    }

    private function getDefaultUsersContent()
    {
        return "# User Management\n\nManage platform users and their permissions.\n\n## User Types\n\n- Students\n- Instructors\n- Administrators\n\n## Management Tasks\n\n- User registration\n- Role assignment\n- Account suspension\n- Data export\n- Bulk operations";
    }

    private function getDefaultAdminCoursesContent()
    {
        return "# Course Management\n\nOversee all platform courses and content.\n\n## Course Operations\n\n- Course approval\n- Content moderation\n- Quality control\n- Performance monitoring\n- Instructor support\n\n## Tools Available\n\n- Course editor\n- Analytics dashboard\n- Reporting tools\n- Communication system";
    }

    private function getDefaultReportsContent()
    {
        return "# Reports and Analytics\n\nGenerate comprehensive reports on platform activity.\n\n## Report Types\n\n- User engagement\n- Course performance\n- Revenue analytics\n- Learning outcomes\n- System health\n\n## Export Options\n\n- PDF reports\n- Excel spreadsheets\n- CSV data\n- API access";
    }

    private function getDefaultSettingsContent()
    {
        return "# System Settings\n\nConfigure platform-wide settings and preferences.\n\n## Configuration Areas\n\n- General settings\n- Email configuration\n- Payment settings\n- Security options\n- Integration settings\n\n## Best Practices\n\n- Regular backups\n- Security audits\n- Performance monitoring\n- User feedback collection";
    }

    private function getDefaultInstallationContent()
    {
        return "# Installation Guide\n\nComplete setup guide for LMS Platform.\n\n## Requirements\n\n- PHP 8.1+\n- Laravel 10+\n- MySQL 8.0+\n- Redis (optional)\n\n## Installation Steps\n\n1. Clone repository\n2. Install dependencies\n3. Configure environment\n4. Run migrations\n5. Seed database\n6. Configure web server";
    }

    private function getDefaultArchitectureContent()
    {
        return "# System Architecture\n\nOverview of LMS Platform architecture.\n\n## Components\n\n- Frontend (Blade templates)\n- Backend (Laravel)\n- Database (MySQL)\n- Cache (Redis)\n- File storage\n\n## Design Patterns\n\n- MVC architecture\n- Service layer\n- Repository pattern\n- Event-driven design\n- Queue system";
    }

    private function getDefaultAPIContent()
    {
        return "# API Development\n\nDevelop integrations using our REST API.\n\n## Authentication\n\n- API keys\n- OAuth 2.0\n- Rate limiting\n- Request signing\n\n## Endpoints\n\n- User management\n- Course operations\n- Content delivery\n- Analytics data\n- Webhook support";
    }

    private function getDefaultPluginsContent()
    {
        return "# Plugin Development\n\nExtend platform functionality with plugins.\n\n## Plugin Structure\n\n- Configuration files\n- PHP classes\n- Database migrations\n- Asset files\n- Documentation\n\n## Development Process\n\n1. Create plugin structure\n2. Implement functionality\n3. Test thoroughly\n4. Package for distribution\n5. Submit for review";
    }

    private function getDefaultDeploymentContent()
    {
        return "# Deployment Guide\n\nDeploy LMS Platform to production.\n\n## Environment Setup\n\n- Production server\n- Database server\n- File storage\n- CDN configuration\n- SSL certificates\n\n## Deployment Steps\n\n1. Prepare environment\n2. Deploy application\n3. Configure services\n4. Run health checks\n5. Monitor performance";
    }

    private function getDefaultAuthContent()
    {
        return "# API Authentication\n\nSecure your API requests with proper authentication.\n\n## Methods\n\n- API Key authentication\n- OAuth 2.0 flow\n- JWT tokens\n- Session-based auth\n\n## Implementation\n\nInclude authentication headers in all API requests:\n\n```\nAuthorization: Bearer YOUR_TOKEN\n```";
    }

    private function getDefaultEndpointsContent()
    {
        return "# API Endpoints\n\nComplete list of available API endpoints.\n\n## Base URL\n\n`https://your-domain.com/api/v1`\n\n## Available Endpoints\n\n- `GET /users` - List users\n- `POST /courses` - Create course\n- `PUT /users/{id}` - Update user\n- `DELETE /courses/{id}` - Delete course\n\n## Response Format\n\nAll responses are in JSON format with consistent structure.";
    }

    private function getDefaultErrorsContent()
    {
        return "# Error Handling\n\nHandle API errors gracefully.\n\n## HTTP Status Codes\n\n- 200: Success\n- 400: Bad Request\n- 401: Unauthorized\n- 403: Forbidden\n- 404: Not Found\n- 500: Internal Server Error\n\n## Error Response Format\n\n```json\n{\n  \"error\": {\n    \"code\": \"ERROR_CODE\",\n    \"message\": \"Human readable message\"\n  }\n}\n```";
    }

    private function getDefaultExamplesContent()
    {
        return "# API Examples\n\nPractical examples of API usage.\n\n## Authentication Example\n\n```php\n$response = Http::withHeaders([\n    'Authorization' => 'Bearer ' . $token,\n    'Accept' => 'application/json'\n])->get('/api/v1/users');\n```\n\n## Course Creation Example\n\n```php\n$response = Http::withHeaders([\n    'Authorization' => 'Bearer ' . $token\n])->post('/api/v1/courses', [\n    'title' => 'Course Title',\n    'description' => 'Course Description'\n]);\n```";
    }

    private function getDefaultCommonIssuesContent()
    {
        return "# Common Issues\n\nSolutions to frequently encountered problems.\n\n## Login Issues\n\n- Check email/password\n- Verify account status\n- Clear browser cache\n- Check internet connection\n\n## Course Access Issues\n\n- Verify enrollment\n- Check payment status\n- Contact support\n- Review system requirements";
    }

    private function getDefaultErrorCodesContent()
    {
        return "# Error Codes\n\nReference guide for system error codes.\n\n## Common Error Codes\n\n- E001: Authentication failed\n- E002: Insufficient permissions\n- E003: Resource not found\n- E004: Validation error\n- E005: System error\n\n## Resolution Steps\n\n1. Note error code\n2. Check error description\n3. Follow resolution steps\n4. Contact support if needed";
    }

    private function getDefaultPerformanceContent()
    {
        return "# Performance Optimization\n\nTips for optimal platform performance.\n\n## User Experience\n\n- Use modern browser\n- Enable JavaScript\n- Stable internet connection\n- Clear cache regularly\n\n## System Performance\n\n- Regular maintenance\n- Database optimization\n- Cache utilization\n- CDN usage";
    }

    private function getDefaultSupportContent()
    {
        return "# Support and Help\n\nGet help when you need it.\n\n## Support Channels\n\n- Email support\n- Live chat\n- Help center\n- Community forum\n- Video tutorials\n\n## Contact Information\n\n- Support email: support@lmsplatform.com\n- Phone: +90 XXX XXX XX XX\n- Business hours: 9:00 AM - 6:00 PM (GMT+3)\n\n## Response Time\n\n- Email: 24-48 hours\n- Live chat: Immediate\n- Phone: During business hours";
    }

    /**
     * Get documentation statistics
     */
    public function getDocumentationStats()
    {
        $stats = [
            'total_files' => 0,
            'total_size' => 0,
            'last_updated' => null,
            'categories' => [
                'user_guide' => 0,
                'admin_guide' => 0,
                'developer_guide' => 0,
                'api_documentation' => 0,
                'troubleshooting' => 0
            ]
        ];

        $this->calculateStats($this->docsPath, $stats);

        return $stats;
    }

    /**
     * Calculate documentation statistics
     */
    private function calculateStats($path, &$stats)
    {
        if (!is_dir($path)) {
            return;
        }

        $files = File::files($path);
        $directories = File::directories($path);

        foreach ($files as $file) {
            if (pathinfo($file, PATHINFO_EXTENSION) === 'md') {
                $stats['total_files']++;
                $stats['total_size'] += filesize($file);
                
                $lastModified = filemtime($file);
                if (!$stats['last_updated'] || $lastModified > $stats['last_updated']) {
                    $stats['last_updated'] = $lastModified;
                }
            }
        }

        foreach ($directories as $directory) {
            $this->calculateStats($directory, $stats);
        }
    }

    /**
     * Export documentation
     */
    public function exportDocumentation($format = 'html', $filters = [])
    {
        $structure = $this->getDocumentationStructure();
        $exportData = [];

        foreach ($structure as $category => $items) {
            $exportData[$category] = $this->exportCategory($items, $format);
        }

        switch ($format) {
            case 'json':
                return response()->json($exportData);
            case 'pdf':
                return $this->exportToPDF($exportData);
            case 'markdown':
                return $this->exportToMarkdown($exportData);
            default:
                return response()->json($exportData);
        }
    }

    /**
     * Export category
     */
    private function exportCategory($items, $format)
    {
        $exported = [];

        foreach ($items as $name => $item) {
            if ($item['type'] === 'file') {
                $content = $this->getDocumentationContent(str_replace($this->docsPath . '/', '', $item['path']));
                $exported[$name] = [
                    'title' => $content['title'],
                    'content' => $format === 'html' ? $content['content'] : $content['raw_content'],
                    'last_modified' => $item['last_modified']
                ];
            } elseif ($item['type'] === 'directory') {
                $exported[$name] = $this->exportCategory($item['children'], $format);
            }
        }

        return $exported;
    }

    /**
     * Export to PDF
     */
    private function exportToPDF($data)
    {
        // This would require a PDF library like Dompdf or mPDF
        // For now, return JSON with PDF export instructions
        return response()->json([
            'message' => 'PDF export requires additional setup',
            'data' => $data
        ]);
    }

    /**
     * Export to Markdown
     */
    private function exportToMarkdown($data)
    {
        $markdown = '';
        
        foreach ($data as $category => $items) {
            $markdown .= "# {$category}\n\n";
            $markdown .= $this->convertToMarkdown($items);
            $markdown .= "\n\n";
        }

        return response($markdown)
            ->header('Content-Type', 'text/markdown')
            ->header('Content-Disposition', 'attachment; filename="documentation.md"');
    }

    /**
     * Convert data to markdown
     */
    private function convertToMarkdown($data, $level = 2)
    {
        $markdown = '';
        
        foreach ($data as $name => $item) {
            if (is_array($item) && isset($item['title'])) {
                $markdown .= str_repeat('#', $level) . " {$item['title']}\n\n";
                if (isset($item['content'])) {
                    $markdown .= $item['content'] . "\n\n";
                }
            } elseif (is_array($item)) {
                $markdown .= str_repeat('#', $level) . " {$name}\n\n";
                $markdown .= $this->convertToMarkdown($item, $level + 1);
            }
        }

        return $markdown;
    }
} 