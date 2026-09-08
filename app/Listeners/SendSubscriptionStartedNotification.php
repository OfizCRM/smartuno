<?php

namespace App\Listeners;

use App\Events\SubscriptionStarted;
use App\Notifications\SubscriptionStartedNotification;
use App\Services\Mail\MailService;
use App\Support\Romania;
use Illuminate\Support\Facades\Log;

class SendSubscriptionStartedNotification
{
    public function handle(SubscriptionStarted $event): void
    {
        $user = $event->user;
        $plan = $event->plan;
        $subscription = $event->subscription;

        try {
            app(MailService::class)->sendWithTemplate('subscription_started', $user->email, [
                'app_name' => config('app.name'),
                'user_name' => Romania::greetingName($user->name),
                'plan_name' => $plan->name,
                'billing_cycle' => $subscription->billing_cycle ?? 'month',
                // The recipient's own timezone, because the button below lands them on the
                // page that renders this very column in it.
                'starts_at' => Romania::longDate($subscription->starts_at ?? now(), $user->timezone),
                'subscription_url' => route('client.subscription.show'),
            ]);
        } catch (\Throwable $e) {
            Log::warning('SendSubscriptionStartedNotification: mail failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }

        try {
            $user->notify(new SubscriptionStartedNotification($plan->name, $subscription->billing_cycle ?? 'month'));
        } catch (\Throwable $e) {
            Log::warning('SendSubscriptionStartedNotification: in-app notification failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }
    }
}
