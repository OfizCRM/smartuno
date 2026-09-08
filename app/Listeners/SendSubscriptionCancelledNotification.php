<?php

namespace App\Listeners;

use App\Events\SubscriptionCancelled;
use App\Notifications\SubscriptionCancelledNotification;
use App\Services\Mail\MailService;
use App\Support\Romania;
use Illuminate\Support\Facades\Log;

class SendSubscriptionCancelledNotification
{
    public function handle(SubscriptionCancelled $event): void
    {
        $user = $event->user;
        $plan = $event->plan;
        $subscription = $event->subscription;
        // Kept nullable for the in-app notification, whose payload has always carried
        // null for "ends now". The email cannot: its copy reads "Accesul se încheie:
        // {{ends_at}}", so the empty case has to be a word that finishes the sentence.
        $endsAt = $subscription->ends_at ? Romania::longDate($subscription->ends_at, $user->timezone) : null;

        try {
            app(MailService::class)->sendWithTemplate('subscription_cancelled', $user->email, [
                'app_name' => config('app.name'),
                'user_name' => Romania::greetingName($user->name),
                'plan_name' => $plan->name,
                'ends_at' => $endsAt ?? __('immediately', [], 'ro'),
                'subscription_url' => route('client.subscription.show'),
            ]);
        } catch (\Throwable $e) {
            Log::warning('SendSubscriptionCancelledNotification: mail failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }

        try {
            $user->notify(new SubscriptionCancelledNotification($plan->name, $endsAt));
        } catch (\Throwable $e) {
            Log::warning('SendSubscriptionCancelledNotification: in-app notification failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }
    }
}
