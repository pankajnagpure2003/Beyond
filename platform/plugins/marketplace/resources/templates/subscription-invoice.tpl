<!DOCTYPE html>
<html {{ html_attributes }}>
    <head>
        <meta charset="UTF-8">
        <title>{{ 'plugins/marketplace::subscription.invoices.name'|trans }} {{ invoice.code }}</title>

        {{ settings.font_css }}

        <style>
            body {
                font-size: 15px;
                font-family: '{{ settings.font_family }}', Arial, sans-serif !important;
                position: relative;
            }

            .header {
                text-align: center;
                margin-bottom: 24px;
            }
            .header h1 {
                margin: 0;
                font-size: 24px;
            }
            .header .logo {
                max-width: 200px;
                margin: 0 auto 10px auto;
            }
            .header p {
                margin: 0;
                font-size: 14px;
                color: #666666;
            }

            .parties {
                width: 100%;
                margin-bottom: 20px;
            }
            .parties td {
                border: 0;
                padding: 0;
                vertical-align: top;
                width: 50%;
            }
            .parties h2 {
                margin: 0 0 6px 0;
                font-size: 16px;
            }
            .parties p {
                margin: 0;
                font-size: 14px;
                color: #666666;
            }
            .right {
                text-align: right;
            }

            table {
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 20px;
            }
            .line-items, .line-items th, .line-items td {
                border: 1px solid #dddddd;
            }
            .line-items th, .line-items td {
                padding: 8px;
                text-align: left;
            }
            .line-items th {
                background-color: #f2f2f2;
            }

            .totals {
                width: 45%;
                margin-left: auto;
            }
            .totals td {
                border: 0;
                padding: 4px 8px;
                font-size: 14px;
            }
            .totals .grand-total td {
                border-top: 2px solid #dddddd;
                font-size: 18px;
                font-weight: bold;
            }

            /* Sits below the totals rather than at 40%/40% like the payout template: a
               subscription invoice has a single line item, so a stamp in the middle of the
               page lands straight on the description and makes it unreadable. */
            .stamp {
                border: 2px solid #555;
                color: #555;
                display: inline-block;
                font-size: 18px;
                line-height: 1;
                opacity: .5;
                padding: .3rem .75rem;
                position: fixed;
                text-transform: uppercase;
                top: 62%;
                left: 12%;
                transform: rotate(-14deg);
            }

            .is-failed {
                border-color: #d23;
                color: #d23;
            }

            .is-completed {
                border-color: #0a9928;
                color: #0a9928;
            }

            body[dir=rtl] {
                direction: rtl;
            }

            body[dir=rtl] .right {
                text-align: left;
            }

            body[dir=rtl] .line-items th,
            body[dir=rtl] .line-items td {
                text-align: right;
            }

            body[dir=rtl] .line-items th:last-child,
            body[dir=rtl] .line-items td:last-child {
                text-align: left;
            }

            body[dir=rtl] .totals {
                margin-left: 0;
                margin-right: auto;
            }

            {{ settings.extra_css }}
        </style>
    </head>
    <body {{ body_attributes }}>
        <div class="header">
            {% if company.logo %}
                <img src="{{ company.logo }}" alt="{{ company.name }}" class="logo">
            {% else %}
                <h1>{{ company.name }}</h1>
            {% endif %}
            <p>{{ company.address }}</p>
            <p>{{ company.email }}</p>
            <p>{{ company.phone }}</p>
            {% if company.tax_id %}
                <p>{{ 'plugins/marketplace::subscription.billing.tax_id'|trans }}: {{ company.tax_id }}</p>
            {% endif %}
        </div>

        <table class="parties">
            <tr>
                <td>
                    <h2>{{ 'plugins/marketplace::subscription.invoices.billed_to'|trans }}</h2>
                    <p>{{ billing.name }}</p>
                    {% if billing.address %}<p>{{ billing.address }}</p>{% endif %}
                    <p>{{ billing.city }}{% if billing.state %}, {{ billing.state }}{% endif %} {{ billing.zip_code }}</p>
                    {% if billing.country %}<p>{{ billing.country }}</p>{% endif %}
                    {% if billing.email %}<p>{{ billing.email }}</p>{% endif %}
                    {% if billing.phone %}<p>{{ billing.phone }}</p>{% endif %}
                    {% if billing.tax_id %}
                        <p>{{ 'plugins/marketplace::subscription.billing.tax_id'|trans }}: {{ billing.tax_id }}</p>
                    {% endif %}
                </td>
                <td class="right">
                    <h2>{{ 'plugins/marketplace::subscription.invoices.name'|trans }} {{ invoice.code }}</h2>
                    <p>{{ 'plugins/marketplace::subscription.invoices.issued_at'|trans }}: {{ invoice.created_at }}</p>
                    {% if invoice.paid_at %}
                        <p>{{ 'plugins/marketplace::subscription.invoices.paid_at'|trans }}: {{ invoice.paid_at }}</p>
                    {% endif %}
                </td>
            </tr>
        </table>

        <table class="line-items">
            <thead>
                <tr>
                    <th>{{ 'plugins/marketplace::subscription.invoices.title'|trans }}</th>
                    <th class="right">{{ 'plugins/marketplace::subscription.invoices.amount'|trans }}</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>
                        <strong>{{ invoice.title }}</strong>
                        {% if invoice.description %}<br>{{ invoice.description }}{% endif %}
                    </td>
                    <td class="right">{{ invoice.sub_total|price_format }}</td>
                </tr>
            </tbody>
        </table>

        <table class="totals">
            <tr>
                <td>{{ 'plugins/marketplace::subscription.invoices.sub_total'|trans }}</td>
                <td class="right">{{ invoice.sub_total|price_format }}</td>
            </tr>
            {% if invoice.tax_amount > 0 %}
                <tr>
                    <td>{{ 'plugins/marketplace::subscription.invoices.tax'|trans }} ({{ invoice.tax_rate }}%)</td>
                    <td class="right">{{ invoice.tax_amount|price_format }}</td>
                </tr>
            {% endif %}
            <tr class="grand-total">
                <td>{{ 'plugins/marketplace::subscription.invoices.total'|trans }}</td>
                <td class="right">{{ invoice.amount|price_format }}</td>
            </tr>
        </table>

        {% if (get_ecommerce_setting('enable_invoice_stamp', 1) == 1) and invoice_status %}
            <span class="stamp {% if invoice.status == 'paid' %} is-completed {% elseif invoice.status == 'cancelled' %} is-failed {% endif %}">
                {{ invoice_status }}
            </span>
        {% endif %}
    </body>
</html>
