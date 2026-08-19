<?php

namespace App\Http\Controllers\Calculator;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class CalculatorController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Home');
    }
}
