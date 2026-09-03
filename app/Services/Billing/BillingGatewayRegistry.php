<?php

namespace App\Services\Billing;

use App\Contracts\BillingGatewayInterface;
use App\Models\PaymentGatewayConfig;

class BillingGatewayRegistry
{
    /** @var array<string, BillingGatewayInterface> */
    private array $gateways = [];

    public function __construct()
    {
        $this->registerFromDatabase();
        $this->registerFromConfig();
    }

    private function registerFromDatabase(): void
    {
        try {
            $configs = PaymentGatewayConfig::where('enabled', true)->get();
        } catch (\Throwable) {
            return;
        }

        $appUrl = config('app.url', '');
        $successUrl = rtrim($appUrl, '/').'/app/billing?checkout=success';
        $cancelUrl = rtrim($appUrl, '/').'/app/pricing?checkout=canceled';

        foreach ($configs as $row) {
            if (isset($this->gateways[$row->gateway])) {
                continue;
            }
            $creds = $row->getActiveCredentials();
            if ($row->gateway === 'stripe' && ! empty($creds['secret_key'] ?? '')) {
                $this->gateways['stripe'] = new StripeGateway(
                    $creds['secret_key'],
                    $creds['webhook_secret'] ?? '',
                    $successUrl,
                    $cancelUrl
                );
            }
            if ($row->gateway === 'paypal' && ! empty($creds['client_id'] ?? $creds['secret_key'] ?? '')) {
                $clientId = $creds['client_id'] ?? $creds['publishable_key'] ?? '';
                $clientSecret = $creds['client_secret'] ?? $creds['secret_key'] ?? '';
                if ($clientId !== '' && $clientSecret !== '') {
                    $this->gateways['paypal'] = new PayPalGateway(
                        $clientId,
                        $clientSecret,
                        $row->test_mode,
                        $successUrl,
                        $cancelUrl,
                        $creds['webhook_secret'] ?? $creds['webhook_id'] ?? ''
                    );
                }
            }
        }
    }

    private function registerFromConfig(): void
    {
        $config = config('billing.gateways', []);

        if (! isset($this->gateways['stripe']) && ! empty($config['stripe']['enabled']) && ($config['stripe']['secret_key'] ?? '')) {
            $this->gateways['stripe'] = new StripeGateway(
                $config['stripe']['secret_key'] ?? '',
                $config['stripe']['webhook_secret'] ?? '',
                $config['stripe']['success_url'] ?? (rtrim(config('app.url'), '/').'/app/billing?checkout=success'),
                $config['stripe']['cancel_url'] ?? (rtrim(config('app.url'), '/').'/app/pricing?checkout=canceled')
            );
        }

        if (! isset($this->gateways['paypal']) && ! empty($config['paypal']['enabled']) && ($config['paypal']['client_id'] ?? '')) {
            $this->gateways['paypal'] = new PayPalGateway(
                $config['paypal']['client_id'] ?? '',
                $config['paypal']['client_secret'] ?? '',
                (bool) ($config['paypal']['sandbox'] ?? true),
                $config['paypal']['success_url'] ?? '',
                $config['paypal']['cancel_url'] ?? '',
                $config['paypal']['webhook_id'] ?? ''
            );
        }
    }

    /**
     * @return array<string, BillingGatewayInterface>
     */
    public function all(): array
    {
        return $this->gateways;
    }

    /**
     * Only gateways that are configured (credentials present).
     *
     * @return array<string, BillingGatewayInterface>
     */
    public function configured(): array
    {
        return array_filter($this->gateways, fn (BillingGatewayInterface $g) => $g->isConfigured());
    }

    public function get(string $key): ?BillingGatewayInterface
    {
        return $this->gateways[$key] ?? null;
    }

    /**
     * @return array<array{key: string, name: string, configured: bool}>
     */
    public function listForFrontend(): array
    {
        $out = [];
        $labels = [
            'stripe' => 'Stripe',
            'paypal' => 'PayPal',
        ];
        foreach ($labels as $key => $label) {
            $g = $this->gateways[$key] ?? null;
            $out[] = [
                'key' => $key,
                'name' => $g ? $g->name() : $label,
                'configured' => $g ? $g->isConfigured() : false,
            ];
        }

        return $out;
    }
}
