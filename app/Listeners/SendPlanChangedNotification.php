<?php

namespace App\Listeners;

use App\Events\PlanChanged;
use App\Notifications\PlanChangedNotification;
use App\Services\Mail\MailService;
use App\Support\Romania;
use Illuminate\Support\Facades\Log;

class SendPlanChangedNotification
{
    public function handle(PlanChanged $event): void
    {
        $user = $event->user;

        try {
            app(MailService::class)->sendWithTemplate('plan_changed', $user->email, [
                'app_name' => config('app.name'),
                'user_name' => Romania::greetingName($user->name),
                'old_plan' => $event->oldPlan->name,
                'new_plan' => $event->newPlan->name,
                'subscription_url' => route('client.subscription.show'),
            ]);
        } catch (\Throwable $e) {
            Log::warning('SendPlanChangedNotification: mail failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }

        try {
            $user->notify(new PlanChangedNotification($event->oldPlan->name, $event->newPlan->name));
        } catch (\Throwable $e) {
            Log::warning('SendPlanChangedNotification: in-app notification failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }
    }
}
