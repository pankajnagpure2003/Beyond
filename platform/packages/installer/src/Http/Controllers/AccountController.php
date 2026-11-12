<?php

namespace Botble\Installer\Http\Controllers;

use Botble\ACL\Models\User;
use Botble\ACL\Services\ActivateUserService;
use Botble\Base\Http\Controllers\BaseController;
use Botble\Installer\Http\Requests\SaveAccountRequest;
use Carbon\Carbon;
use Exception;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;

class AccountController extends BaseController
{
    public function index(Request $request): View|RedirectResponse
    {
        if (! URL::hasValidSignature($request)) {
            return redirect()->route('installers.requirements.index');
        }

        return view('packages/installer::account');
    }

    public function store(SaveAccountRequest $request, ActivateUserService $activateUserService): RedirectResponse
    {
        try {
            // Clear any account left behind by an earlier install attempt.
            // MySQL refuses TRUNCATE on a table referenced by a foreign key, and
            // plugin tables routinely point at `users` through audit columns
            // (who verified a vendor, who approved a payout), which made a fresh
            // install die here with SQLSTATE[42000] 1701. Constraints are
            // restored by withoutForeignKeyConstraints() even if this throws.
            Schema::withoutForeignKeyConstraints(static fn () => User::query()->truncate());

            $user = new User();
            $user->fill(
                $request->only([
                    'first_name',
                    'last_name',
                    'username',
                    'email',
                ])
            );
            $user->super_user = 1;
            $user->{ACL_ROLE_MANAGE_SUPERS} = 1;
            $user->password = Hash::make($request->input('password'));
            $user->save();

            $activateUserService->activate($user);

            Auth::login($user);

            return redirect()
                ->to(URL::temporarySignedRoute('installers.licenses.index', Carbon::now()->addMinutes(30)));
        } catch (Exception $exception) {
            return back()->withInput()->withErrors([
                'first_name' => [$exception->getMessage()],
            ]);
        }
    }
}
