<?php

namespace App\Listeners;

use App\Events\SubscriptionExpired;
use App\Notifications\SubscriptionExpiredNotification;
use App\Services\Mail\MailService;
use App\Support\Romania;
use Illuminate\Support\Facades\Log;

class SendSubscriptionExpiredNotification
{
    public function handle(SubscriptionExpired $event): void
    {
        $user = $event->user;
        $plan = $event->plan;

        try {
            app(MailService::class)->sendWithTemplate('subscription_expired', $user->email, [
                'app_name' => config('app.name'),
                'user_name' => Romania::greetingName($user->name),
                'plan_name' => $plan->name,
                // The subscription is over, so there is nothing to double up on: pricing
                // is where reactivating actually happens.
                'pricing_url' => route('client.pricing'),
            ]);
        } catch (\Throwable $e) {
            Log::warning('SendSubscriptionExpiredNotification: mail failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }

        try {
            $user->notify(new SubscriptionExpiredNotification($plan->name));
        } catch (\Throwable $e) {
            Log::warning('SendSubscriptionExpiredNotification: in-app notification failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }
    }
}
