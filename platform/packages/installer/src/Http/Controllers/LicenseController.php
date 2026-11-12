<?php

namespace Botble\Installer\Http\Controllers;

use Botble\Base\Exceptions\LicenseInvalidException;
use Botble\Base\Exceptions\LicenseIsAlreadyActivatedException;
use Botble\Base\Http\Controllers\BaseController;
use Botble\Base\Supports\Core;
use Botble\Setting\Facades\Setting;
use Botble\Setting\Http\Requests\LicenseSettingRequest;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class LicenseController extends BaseController
{
    public function index(): View|RedirectResponse
    {
        return view('packages/installer::license');
    }

    /**
     * Where the wizard goes after the licence screen. Stock: the closing screen.
     *
     * The steps before this are a chain of hardcoded redirects with no extension
     * point, so a product that adds install steps of its own (Ecommerce SaaS
     * inserts five: control-plane domain, operator account, automated setup,
     * queue & cron, first store) hooks `cms_installer_step_after_license` and
     * returns its first step's route name. A route that does not exist falls back
     * to the closing screen instead of 404ing the wizard.
     */
    protected function nextStepAfterLicense(): string
    {
        $route = (string) apply_filters('cms_installer_step_after_license', 'installers.final');

        return Route::has($route) ? $route : 'installers.final';
    }

    public function store(LicenseSettingRequest $request, Core $core): RedirectResponse
    {
        $buyer = $request->input('buyer');

        if (filter_var($buyer, FILTER_VALIDATE_URL)) {
            $username = Str::afterLast($buyer, '/');

            throw ValidationException::withMessages([
                'buyer' => sprintf('Envato username must not a URL. Please try with username "%s".', $username),
            ]);
        }

        try {
            $licenseKey = $request->input('purchase_code');

            $core->activateLicense($licenseKey, $buyer);

            Setting::forceSet('licensed_to', $buyer)->save();

            $nextUrl = URL::temporarySignedRoute($this->nextStepAfterLicense(), Carbon::now()->addMinutes(30));

            return redirect()->to($nextUrl);
        } catch (LicenseInvalidException|LicenseIsAlreadyActivatedException $exception) {
            throw ValidationException::withMessages([
                'purchase_code' => [$exception->getMessage()],
            ]);
        } catch (Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'purchase_code' => ['Something went wrong. Please try again later.'],
            ]);
        }
    }

    public function skip(): RedirectResponse
    {
        Core::make()->skipLicenseReminder();

        return redirect()->to(URL::temporarySignedRoute($this->nextStepAfterLicense(), Carbon::now()->addMinutes(30)));
    }
}
