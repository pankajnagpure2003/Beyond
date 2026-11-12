<?php

namespace Botble\Base\Http\Controllers;

use Botble\ACL\Models\UserMeta;
use Botble\Base\Facades\AdminHelper;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ToggleThemeModeController extends BaseController
{
    public function __invoke(Request $request): RedirectResponse
    {
        $request->validate(['theme' => ['required', Rule::in(AdminHelper::themeModes())]]);

        $themeMode = $request->query('theme');

        UserMeta::setMeta('theme_mode', $themeMode);

        return redirect()->back();
    }
}
