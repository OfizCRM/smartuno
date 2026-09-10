<?php

namespace App\Services\Billing;

use App\Models\PaymentTransaction;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class InvoiceService
{
    /**
     * Generate an invoice PDF for a payment transaction.
     * Saves to storage and returns the PDF content string, or null on failure.
     */
    public function generate(PaymentTransaction $transaction): ?string
    {
        try {
            $transaction->loadMissing(['user', 'subscription.plan', 'coupon']);

            $data = [
                'transaction' => $transaction,
                'user' => $transaction->user,
                'plan' => $transaction->subscription?->plan,
                'coupon' => $transaction->coupon,
                'app_name' => config('app.name'),
                'support_email' => config('saas.support_email'),
                'issued_at' => $transaction->created_at->format('F j, Y'),
                'invoice_number' => 'INV-'.str_pad($transaction->id, 6, '0', STR_PAD_LEFT),
            ];

            $pdf = Pdf::loadView('pdf.invoice', $data);
            $content = $pdf->output();

            $path = 'invoices/'.$transaction->id.'.pdf';

            // Pinned to 'local', not the framework default: SubscriptionController reads
            // this same path back off the local disk, so FILESYSTEM_DISK must not move it.
            $stored = Storage::disk('local')->put($path, $content);

            // The local disk is configured 'throw' => false, so a failed write returns false
            // rather than raising. Recording invoice_path anyway would have the row claim a
            // cached PDF that was never written.
            if ($stored === false) {
                Log::error('InvoiceService::generate could not store the invoice', [
                    'transaction_id' => $transaction->id,
                    'path' => $path,
                ]);

                return $content;
            }

            $transaction->update(['invoice_path' => $path]);

            return $content;
        } catch (\Throwable $e) {
            Log::error('InvoiceService::generate failed', ['transaction_id' => $transaction->id, 'error' => $e->getMessage()]);

            return null;
        }
    }
}
