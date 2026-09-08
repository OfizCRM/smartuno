<?php

namespace App\Listeners;

use App\Events\SubscriptionRenewed;
use App\Notifications\SubscriptionRenewedNotification;
use App\Services\Mail\MailService;
use App\Support\Romania;
use Illuminate\Support\Facades\Log;

class SendSubscriptionRenewedNotification
{
    public function handle(SubscriptionRenewed $event): void
    {
        $user = $event->user;
        $plan = $event->plan;
        $subscription = $event->subscription;
        // Romanian separators: the recipient reads "1.250,00 RON". With the PHP
        // defaults the same charge arrives as "1,250.00 RON", which a Romanian
        // bookkeeper reads as one and a quarter.
        $amount = number_format($event->amountCents / 100, 2, ',', '.');
        // Kept nullable: the in-app notification's payload has always carried null for
        // "no next renewal", and only the email needs a word in that hole.
        $nextRenewal = $subscription->renews_at ? Romania::longDate($subscription->renews_at, $user->timezone) : null;

        try {
            app(MailService::class)->sendWithTemplate('subscription_renewed', $user->email, [
                'app_name' => config('app.name'),
                'user_name' => Romania::greetingName($user->name),
                'plan_name' => $plan->name,
                'amount' => $amount,
                'currency' => $event->currency,
                // A missing date has to finish the sentence: an em dash in the middle of
                // Romanian prose reads as a field we failed to fill in.
                'next_renewal' => $nextRenewal ?? __('not set yet', [], 'ro'),
                'billing_url' => route('client.billing.index'),
            ]);
        } catch (\Throwable $e) {
            Log::warning('SendSubscriptionRenewedNotification: mail failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }

        try {
            $user->notify(new SubscriptionRenewedNotification($plan->name, $amount, $event->currency, $nextRenewal));
        } catch (\Throwable $e) {
            Log::warning('SendSubscriptionRenewedNotification: in-app notification failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }
    }
}
