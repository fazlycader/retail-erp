<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Navigation;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Placeholder for modules that later build steps will replace with real controllers.
 */
class ComingSoonController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $module = Navigation::module($request->route()->defaults['module']);

        return Inertia::render('Admin/ComingSoon', [
            'title' => $module['label'],
            'description' => $module['description'],
        ]);
    }
}
