<?php

namespace Botble\Marketplace\Services;

use Botble\Base\Supports\Pdf;
use Botble\Ecommerce\Facades\InvoiceHelper;
use Botble\Ecommerce\Supports\TwigExtension;
use Botble\Marketplace\Enums\SubscriptionInvoiceStatusEnum;
use Botble\Marketplace\Models\VendorSubscriptionInvoice;
use Botble\Marketplace\Services\Concerns\HasInvoiceCompanyData;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

/**
 * Renders a subscription invoice as a PDF.
 *
 * Mirrors GeneratePayoutInvoiceService so both marketplace documents share one pipeline
 * and one customization path under storage/app/templates/marketplace/. Every figure comes
 * off the invoice row, which was frozen when the charge happened — nothing is recomputed
 * here, so an admin editing the tax rate cannot rewrite an issued document.
 */
class GenerateSubscriptionInvoiceService
{
    use HasInvoiceCompanyData;

    public function __construct(protected ?VendorSubscriptionInvoice $invoice = null)
    {
    }

    public function invoice(VendorSubscriptionInvoice $invoice): self
    {
        $this->invoice = $invoice;

        return $this;
    }

    public function stream(?string $fileName = null): Response
    {
        return $this->generateInvoice()->stream($this->fileName($fileName));
    }

    public function download(?string $fileName = null): Response
    {
        return $this->generateInvoice()->download($this->fileName($fileName));
    }

    protected function fileName(?string $fileName): string
    {
        return $fileName ?: ($this->invoice?->code ?: 'invoice') . '.pdf';
    }

    protected function generateInvoice(): Pdf
    {
        return (new Pdf())
            ->templatePath($this->getTemplatePath())
            ->destinationPath($this->getCustomizedTemplatePath())
            ->supportLanguage(InvoiceHelper::getLanguageSupport())
            ->paperSizeA4()
            ->data($this->getInvoiceData())
            ->twigExtensions([new TwigExtension()])
            ->setProcessingLibrary(get_ecommerce_setting('invoice_processing_library', 'dompdf'));
    }

    protected function getInvoiceData(): array
    {
        return [
            'company' => $this->companyData(),
            'invoice' => $this->invoice,
            'invoice_status' => $this->invoice->status->label(),
            'billing' => $this->invoice->billingAddress(),
        ];
    }

    /**
     * The sample the admin sees while editing the template. Not persisted — the model is
     * built in memory purely to give every variable something to render.
     */
    public function preview(): Response
    {
        $invoice = new VendorSubscriptionInvoice([
            'code' => 'SUB-000001',
            'title' => trans('plugins/marketplace::subscription.invoices.titles.subscribed'),
            'description' => trans('plugins/marketplace::subscription.invoices.descriptions.subscribed', [
                'plan' => 'Pro',
                'date' => Carbon::now()->addMonth()->translatedFormat('M j, Y'),
            ]),
            'sub_total' => 100,
            'tax_rate' => 10,
            'tax_amount' => 10,
            'amount' => 110,
            'currency' => get_application_currency()->title,
            'status' => SubscriptionInvoiceStatusEnum::PAID,
            'billing_name' => 'John Doe',
            'billing_email' => 'john@example.com',
            'billing_phone' => '+1 555 0100',
            'billing_address' => '123 Example Street',
            'billing_city' => 'San Francisco',
            'billing_state' => 'California',
            'billing_country' => 'US',
            'billing_zip_code' => '94103',
            'billing_tax_id' => 'US123456789',
        ]);

        $invoice->id = 1;
        $invoice->created_at = Carbon::now();
        $invoice->paid_at = Carbon::now();

        return $this->invoice($invoice)->stream();
    }

    public function getContent(): string
    {
        return (new Pdf())
            ->twigExtensions([new TwigExtension()])
            ->getContent($this->getTemplatePath(), $this->getCustomizedTemplatePath());
    }

    public function getTemplatePath(): string
    {
        return plugin_path('marketplace/resources/templates/subscription-invoice.tpl');
    }

    public function getCustomizedTemplatePath(): string
    {
        return storage_path('app/templates/marketplace/subscription-invoice.tpl');
    }

    public function getVariables(): array
    {
        $prefix = 'plugins/marketplace::subscription.invoices.variables.';

        return [
            'company.logo' => trans("{$prefix}company_logo"),
            'company.name' => trans("{$prefix}company_name"),
            'company.address' => trans("{$prefix}company_address"),
            'company.phone' => trans("{$prefix}company_phone"),
            'company.email' => trans("{$prefix}company_email"),
            'company.tax_id' => trans("{$prefix}company_tax_id"),
            'invoice.code' => trans("{$prefix}invoice_code"),
            'invoice.created_at' => trans("{$prefix}invoice_created_at"),
            'invoice.paid_at' => trans("{$prefix}invoice_paid_at"),
            'invoice.title' => trans("{$prefix}invoice_title"),
            'invoice.description' => trans("{$prefix}invoice_description"),
            'invoice.sub_total' => trans("{$prefix}invoice_sub_total"),
            'invoice.tax_rate' => trans("{$prefix}invoice_tax_rate"),
            'invoice.tax_amount' => trans("{$prefix}invoice_tax_amount"),
            'invoice.amount' => trans("{$prefix}invoice_amount"),
            'invoice_status' => trans("{$prefix}invoice_status"),
            'billing.name' => trans("{$prefix}billing_name"),
            'billing.email' => trans("{$prefix}billing_email"),
            'billing.phone' => trans("{$prefix}billing_phone"),
            'billing.address' => trans("{$prefix}billing_address"),
            'billing.city' => trans("{$prefix}billing_city"),
            'billing.state' => trans("{$prefix}billing_state"),
            'billing.country' => trans("{$prefix}billing_country"),
            'billing.zip_code' => trans("{$prefix}billing_zip_code"),
            'billing.tax_id' => trans("{$prefix}billing_tax_id"),
        ];
    }
}
