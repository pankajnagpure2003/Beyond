<?php

namespace Botble\ACL\Forms\Auth;

use Botble\ACL\Http\Requests\LoginRequest;
use Botble\ACL\Models\User;
use Botble\Base\Facades\BaseHelper;
use Botble\Base\Facades\Html;
use Botble\Base\Forms\FieldOptions\CheckboxFieldOption;
use Botble\Base\Forms\FieldOptions\HtmlFieldOption;
use Botble\Base\Forms\FieldOptions\TextFieldOption;
use Botble\Base\Forms\Fields\CheckboxField;
use Botble\Base\Forms\Fields\HtmlField;
use Botble\Base\Forms\Fields\PasswordField;
use Botble\Base\Forms\Fields\TextField;

class LoginForm extends AuthForm
{
    public function setup(): void
    {
        parent::setup();

        $this
            ->setValidatorClass(LoginRequest::class)
            ->setUrl(route('access.login'))
            ->heading(trans('core/acl::auth.sign_in_below'))
            ->add(
                'username',
                TextField::class,
                TextFieldOption::make()
                    ->label(trans('core/acl::auth.login.username'))
                    ->value(old(
                        'email',
                        /**
                         * Fall back to a validated ?email= query parameter, so a flow
                         * that sends a user to the login page from another domain can
                         * prefill it: old() only reads session flash data, which does
                         * not cross domains, and the email would be silently dropped.
                         *
                         * filter_var() before echoing: this value is reflected into
                         * the form.
                         */
                        filter_var(request()->query('email'), FILTER_VALIDATE_EMAIL)
                            ?: (BaseHelper::hasDemoModeEnabled() ? config('core.base.general.demo.account.username') : null),
                    ))
                    ->required()
                    ->attributes(['tabindex' => 1, 'placeholder' => trans('core/acl::auth.login.placeholder.username')])
            )
            ->add(
                'password',
                PasswordField::class,
                TextFieldOption::make()
                    ->label(trans('core/acl::auth.login.password'))
                    ->labelAttributes([
                        'description' => Html::link(route('access.password.request'), trans('core/acl::auth.lost_your_password'), ['tabindex' => 5, 'title' => trans('core/acl::auth.forgot_password.title')])->toHtml(),
                    ])
                    ->attributes(['tabindex' => 2])
                    ->when(BaseHelper::hasDemoModeEnabled(), function (TextFieldOption $option): void {
                        $option->value(config('core.base.general.demo.account.password'));
                    })
                    ->required()
                    ->placeholder(trans('core/acl::auth.login.placeholder.password'))
            )
            ->add(
                'remember',
                CheckboxField::class,
                CheckboxFieldOption::make()
                    ->label(trans('core/acl::auth.login.remember'))
                    ->value(true)
                    ->attributes(['tabindex' => 3])
            )
            ->submitButton(trans('core/acl::auth.login.login'), 'ti ti-login-2')
            ->add(
                'filters',
                HtmlField::class,
                HtmlFieldOption::make()
                    ->content(apply_filters(BASE_FILTER_AFTER_LOGIN_OR_REGISTER_FORM, null, User::class))
            );
    }
}
