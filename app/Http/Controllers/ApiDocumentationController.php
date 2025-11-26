<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

class ApiDocumentationController extends Controller
{
    public function index()
    {
        $apiRoutes = $this->getApiRoutes();
        $apiEndpoints = $this->analyzeApiEndpoints();
        
        return view('api.documentation.index', compact('apiRoutes', 'apiEndpoints'));
    }

    private function getApiRoutes()
    {
        $routes = Route::getRoutes();
        $apiRoutes = [];
        
        foreach ($routes as $route) {
            if (str_starts_with($route->getPrefix(), 'api')) {
                $apiRoutes[] = [
                    'method' => $route->methods()[0],
                    'uri' => $route->uri(),
                    'name' => $route->getName(),
                    'controller' => $route->getController() ? get_class($route->getController()) : null,
                    'action' => $route->getActionMethod()
                ];
            }
        }
        
        return $apiRoutes;
    }

    private function analyzeApiEndpoints()
    {
        return [
            'user' => [
                'name' => 'User Management',
                'endpoints' => [
                    [
                        'method' => 'POST',
                        'path' => '/api/auth/login',
                        'description' => 'User login'
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/api/auth/register',
                        'description' => 'User registration'
                    ]
                ]
            ],
            'courses' => [
                'name' => 'Course Management',
                'endpoints' => [
                    [
                        'method' => 'GET',
                        'path' => '/api/courses',
                        'description' => 'List all courses'
                    ]
                ]
            ]
        ];
    }
} 