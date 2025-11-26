<?php

namespace App\Http\Controllers;

use App\Models\InstructorApplication;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class InstructorApplicationController extends Controller
{
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|email|max:255',
                'phone' => 'nullable|string|max:50',
                'expertise' => 'required|string|max:255',
                'bio' => 'required|string',
                'experience' => 'nullable|string',
                'topics' => 'nullable|string',
                'cv' => 'nullable|file|mimes:pdf,doc,docx|max:2048',
                'video' => 'nullable|url',
                'kvkk' => 'required|accepted',
            ]);

            $cvPath = null;
            if ($request->hasFile('cv')) {
                $cvPath = $request->file('cv')->store('instructor_cvs', 'public');
            }

            $application = InstructorApplication::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'phone' => $validated['phone'] ?? null,
                'expertise' => $validated['expertise'],
                'bio' => $validated['bio'],
                'experience' => $validated['experience'] ?? null,
                'topics' => $validated['topics'] ?? null,
                'cv_path' => $cvPath,
                'video' => $validated['video'] ?? null,
                'kvkk_onay' => true,
            ]);

            return redirect()->back()->with('success', 'Your application has been submitted successfully! You will be redirected to the homepage.');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'An error occurred while submitting your application. Please try again.');
        }
    }
} 