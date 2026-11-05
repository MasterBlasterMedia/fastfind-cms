<?php

namespace App\Http\Controllers;

use App\Marketplace\Marketplace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class MarketplaceController extends Controller
{
    public function index(Request $request, Marketplace $marketplace)
    {
        Gate::authorize('manage-plugins');
        return response()->json($marketplace->listing($request->user()->id));
    }

    public function install(Request $request, Marketplace $marketplace)
    {
        Gate::authorize('manage-plugins');
        $selection = $request->validate(['type' => ['required', 'in:plugin,theme'], 'id' => ['required', 'string', 'max:101'],
            'version' => ['required', 'string', 'max:50'], 'sha256' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D']]);
        return response()->json($marketplace->install($selection, $request->user()->id), 201);
    }
}
