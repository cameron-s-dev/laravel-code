<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class MainController extends Controller
{
    public function admin() {
        return view('admin');
    }

    public function index(Request $request) {
        return view('index');
    }
}
