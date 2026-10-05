<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class DevToolsEnabled
{
    public function handle(Request $request, Closure $next)
    {
        if (! config('tickets.dev_tools_enabled')) {
            abort(404);
        }

        return $next($request);
    }
}
