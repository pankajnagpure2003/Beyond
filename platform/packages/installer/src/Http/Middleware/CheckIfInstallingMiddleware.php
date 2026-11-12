<?php

namespace Botble\Installer\Http\Middleware;

use Botble\Base\Facades\BaseHelper;
use Carbon\Carbon;
use Closure;
use Exception;
use Illuminate\Http\Request;

class CheckIfInstallingMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        try {
            $content = BaseHelper::getFileData(storage_path(INSTALLING_SESSION_NAME));

            // Carbon 3 made diffInMinutes() SIGNED (v2 defaulted to absolute), so the
            // previous `Carbon::now()->diffInMinutes($startingDate) > 30` compared a
            // NEGATIVE number against 30 for a marker that is always in the past: the
            // 30-minute window never closed and a stale marker kept the installer
            // answering forever. addMinutes(30)->isPast() means the same thing under
            // both major versions.
            if (! $content || Carbon::parse($content)->addMinutes(30)->isPast()) {
                return redirect()->to('/');
            }
        } catch (Exception) {
            return redirect()->to('/');
        }

        return $next($request);
    }
}
