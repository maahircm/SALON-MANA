<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        // Analytics widgets arrive with the reporting phase; until then show the signed-in user's access summary.
        return view('dashboard', ['user' => $request->user()->load('roles')]);
    }
}
