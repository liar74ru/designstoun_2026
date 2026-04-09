<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class SyncController extends Controller
{
    public function index(): View
    {
        return view('sync.index');
    }
}
