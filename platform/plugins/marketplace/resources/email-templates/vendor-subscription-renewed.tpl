{{ header }}

<div class="bb-main-content">
    <table class="bb-box" cellpadding="0" cellspacing="0">
        <tbody>
            <tr>
                <td class="bb-content bb-pb-0" align="center">
                    <table class="bb-icon bb-icon-lg bb-bg-green" cellspacing="0" cellpadding="0">
                        <tbody>
                        <tr>
                            <td valign="middle" align="center">
                                <img src="{{ 'check' | icon_url }}" class="bb-va-middle" width="40" height="40" alt="Icon" />
                            </td>
                        </tr>
                        </tbody>
                    </table>
                    <h1 class="bb-text-center bb-m-0 bb-mt-md">{{ 'plugins/marketplace::subscription.email_templates.vendor_subscription_renewed_title' | trans }}</h1>
                </td>
            </tr>
            <tr>
                <td class="bb-content bb-pb-0">
                    <p>{{ 'plugins/marketplace::subscription.email_templates.greeting' | trans({'vendor_name': vendor_name}) }}</p>
                    <div>{{ 'plugins/marketplace::subscription.email_templates.vendor_subscription_renewed_message' | trans({'plan_name': plan_name}) | raw }}</div>
                </td>
            </tr>
            <tr>
                <td class="bb-content bb-pb-0">
                    <table class="bb-table" cellpadding="0" cellspacing="0" width="100%">
                        <tbody>
                            <tr>
                                <td>{{ 'plugins/marketplace::subscription.subscriptions.plan' | trans }}</td>
                                <td align="right"><strong>{{ plan_name }}</strong></td>
                            </tr>
                            <tr>
                                <td>{{ 'plugins/marketplace::subscription.subscriptions.amount' | trans }}</td>
                                <td align="right"><strong>{{ plan_price }}</strong></td>
                            </tr>
                            <tr>
                                <td>{{ 'plugins/marketplace::subscription.subscriptions.ends_at' | trans }}</td>
                                <td align="right"><strong>{{ ends_at }}</strong></td>
                            </tr>
                        </tbody>
                    </table>
                </td>
            </tr>
            <tr>
                <td class="bb-content bb-text-center">
                    <a href="{{ subscription_url }}" class="bb-btn bb-bg-blue bb-border-blue">{{ 'plugins/marketplace::subscription.email_templates.manage_subscription' | trans }}</a>
                </td>
            </tr>
        </tbody>
    </table>
</div>

{{ footer }}
