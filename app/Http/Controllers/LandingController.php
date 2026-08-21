<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LandingController extends Controller
{
    public function __invoke(Request $request): View|RedirectResponse
    {
        if ($request->user() !== null) {
            return redirect()->route('events.index');
        }

        return view('landing');
    }
}
