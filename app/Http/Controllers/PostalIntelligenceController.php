<?php

namespace App\Http\Controllers;

use App\Services\SqlServerSearchService;
use Illuminate\Http\Request;

class PostalIntelligenceController extends Controller
{
    public function index(Request $request, SqlServerSearchService $searchService)
    {
        $request->validate(['codigo' => ['nullable', 'string', 'max:35']]);

        return app(SqlServerDataController::class)->index($request, $searchService);
    }
}
