<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateCurrencyPreferenceRequest;
use Illuminate\Http\RedirectResponse;

class CurrencyPreferenceController extends Controller
{
    public function __invoke(UpdateCurrencyPreferenceRequest $request): RedirectResponse
    {
        $request->user()->forceFill([
            'preferred_currency' => $request->validated('currency'),
        ])->save();

        return back();
    }
}
