<?php

namespace App\Http\Controllers\Api\Citizen;

use App\Http\Controllers\Controller;
use App\Support\Citizen\CitizenHomePayloadBuilder;
use Illuminate\Http\JsonResponse;

class HomeController extends Controller
{
    public function __construct(
        private readonly CitizenHomePayloadBuilder $citizenHomePayloadBuilder,
    ) {
    }

    public function show(): JsonResponse
    {
        return response()->json(
            $this->citizenHomePayloadBuilder->build(request()->user()),
        );
    }
}
