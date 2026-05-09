<?php

namespace App\Http\Controllers\Api\Caller;

use App\Http\Controllers\Controller;
use App\Support\Caller\CallerHomePayloadBuilder;
use Illuminate\Http\JsonResponse;

class HomeController extends Controller
{
    public function __construct(
        private readonly CallerHomePayloadBuilder $callerHomePayloadBuilder,
    ) {
    }

    public function show(): JsonResponse
    {
        return response()->json(
            $this->callerHomePayloadBuilder->build(request()->user()),
        );
    }
}
