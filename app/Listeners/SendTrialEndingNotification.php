<?php

namespace App\Listeners;

use App\Events\TrialEnding;
use App\Notifications\TrialEndingNotification;
use App\Services\Mail\MailService;
use App\Support\Romania;
use Illuminate\Support\Facades\Log;

class SendTrialEndingNotification
{
    public function handle(TrialEnding $event): void
    {
        $user = $event->user;
        $plan = $event->plan;
        $trialEndsAt = Romania::longDate($event->subscription->trial_ends_at, $user->timezone);

        try {
            app(MailService::class)->sendWithTemplate('trial_ending', $user->email, [
                'app_name' => config('app.name'),
                'user_name' => Romania::greetingName($user->name),
                'plan_name' => $plan->name,
                'days_remaining' => (string) $event->daysRemaining,
                'trial_ends_at' => $trialEndsAt,
                // client.pricing, not billing: choosing a plan there is the only path in
                // the app that reaches checkout, which is where a card actually gets added.
                'pricing_url' => route('client.pricing'),
            ]);
        } catch (\Throwable $e) {
            Log::warning('SendTrialEndingNotification: mail failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }

        try {
            $user->notify(new TrialEndingNotification($plan->name, $event->daysRemaining, $trialEndsAt));
        } catch (\Throwable $e) {
            Log::warning('SendTrialEndingNotification: in-app notification failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }
    }
}
