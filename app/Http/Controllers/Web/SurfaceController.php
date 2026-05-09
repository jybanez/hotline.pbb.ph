<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\Auth\RoleRedirector;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;

class SurfaceController extends Controller
{
    public function __construct(
        private readonly RoleRedirector $roleRedirector,
    ) {
    }

    public function public(Request $request): View|RedirectResponse
    {
        if ($request->user() !== null) {
            return redirect($this->roleRedirector->homePathFor($request->user()));
        }

        return view('pages.public.home');
    }

    public function citizen(): View
    {
        return view('pages.citizen.index');
    }

    public function operator(): View
    {
        return view('pages.operator.index');
    }

    public function command(): View
    {
        return view('pages.command.index');
    }

    public function admin(): View
    {
        return view('pages.admin.index');
    }
}
