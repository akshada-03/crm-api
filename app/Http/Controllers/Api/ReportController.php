<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RepPerformanceResource;
use App\Queries\RepPerformanceReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ReportController extends Controller
{
    public function __invoke(Request $request, RepPerformanceReport $report): AnonymousResourceCollection
    {
        return RepPerformanceResource::collection($report->forViewer($request->user()));
    }
}
