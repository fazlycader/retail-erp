<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /deploy/opcache-reset (header X-Deploy-Token = OPCACHE_RESET_TOKEN), called by the deploy
 * script after switching releases: the web server's OPcache can only be cleared from a web
 * request, not from the command line.
 */
class OpcacheResetController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $token = (string) config('security.opcache_reset_token');
        abort_if($token === '' || ! hash_equals($token, (string) $request->header('X-Deploy-Token')), 404);

        $reset = function_exists('opcache_reset') && @opcache_reset();
        clearstatcache(true);

        return response()->json(['opcache_reset' => $reset]);
    }
}
