<?php

namespace App\Exceptions;

use Botble\Theme\Facades\Theme;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    public function register(): void
    {
        $this->renderable(function (NotFoundHttpException $e, $request) {
            if (! $request->expectsJson() && ! $request->is('admin/*') && ! $request->is('api/*')) {
                try {
                    return response(Theme::scope('404')->render(), 404);
                } catch (Throwable) {
                    // Fall back to default handler if Theme cannot be rendered
                }
            }
        });
    }
}
