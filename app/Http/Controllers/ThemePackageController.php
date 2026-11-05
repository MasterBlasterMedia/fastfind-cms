<?php

namespace App\Http\Controllers;

use App\Themes\ThemePackageInstaller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class ThemePackageController extends Controller
{
    public function upload(Request $request, ThemePackageInstaller $installer)
    {
        Gate::authorize('manage-plugins');
        $input = $request->validate([
            'file' => ['required', 'file', 'max:2048'],
            'publisher' => ['required', 'string', 'regex:/^[a-z][a-z0-9-]{1,79}$/D'],
            'signature' => ['required', 'string', 'max:100'],
            'sha256' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
        ]);
        $theme = $installer->install($input['file']->getRealPath(), $input['publisher'], $input['signature'], $input['sha256'], $request->user()->id);
        return response()->json(['theme' => $theme], 201);
    }
}
