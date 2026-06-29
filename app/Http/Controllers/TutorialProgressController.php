<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TutorialProgressController extends Controller
{
    /**
     * Mark a guided tutorial as completed for the authenticated user.
     *
     * Shared by both the Inertia/React frontend (via router.post) and the
     * Filament panels (via fetch), so the response adapts to the caller.
     */
    public function __invoke(Request $request): Response|RedirectResponse
    {
        $validated = $request->validate([
            'key' => ['required', 'string', 'max:255'],
        ]);

        $request->user()->markTutorialCompleted($validated['key']);

        if ($request->header('X-Inertia')) {
            return back();
        }

        return response()->noContent();
    }
}
