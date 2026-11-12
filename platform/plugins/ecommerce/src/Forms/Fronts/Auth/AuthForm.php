<?php

namespace Botble\Ecommerce\Forms\Fronts\Auth;

use Botble\Ecommerce\Forms\Fronts\CardForm;
use Botble\Theme\Facades\Theme;

abstract class AuthForm extends CardForm
{
    public function setup(): void
    {
        parent::setup();

        Theme::addBodyAttributes(['id' => 'page-auth']);

        $this->setFormOption('messagesView', 'plugins/ecommerce::customers.includes.auth-messages');
    }
}
