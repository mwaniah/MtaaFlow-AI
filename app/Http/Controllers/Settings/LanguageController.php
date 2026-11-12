<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LanguageController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate(['locale' => ['required', Rule::in(['en', 'fr', 'de', 'es'])]]);
        $request->user()->update($validated);

        return response()->json(['locale' => $validated['locale']]);
    }
}
