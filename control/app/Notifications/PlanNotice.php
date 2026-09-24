<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * The emails a free account gets as its trial runs out, and the one a paid
 * account gets when its storage is over the plan. Each states the date that
 * matters in UTC, and what to do; none is sent twice for the same event.
 */
class PlanNotice extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $kind,        // ending | paused | deleted | storage | payment_failed | payment_final | downgraded
        public readonly ?Carbon $when = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $date = $this->when?->utc()->format('l j F, H:i').' UTC';
        $upgrade = route('billing');

        return match ($this->kind) {
            'ending' => (new MailMessage)
                ->subject('Your codeinchrome trial ends tomorrow')
                ->line("Your free trial ends on $date.")
                ->line('Upgrade to Starter to keep your site running. If you do not, it is paused when the trial ends and deleted soon after.')
                ->action('Upgrade to Starter', $upgrade),
            'paused' => (new MailMessage)
                ->subject('Your codeinchrome site is paused')
                ->line('Your free trial has ended, so your site is paused: it is not served, and it cannot be edited.')
                ->line("It will be deleted, with its files and database, on $date.")
                ->line('Upgrade before then and it comes back exactly as you left it. You can also download its database from the dashboard.')
                ->action('Upgrade to Starter', $upgrade),
            'deleted' => (new MailMessage)
                ->subject('Your codeinchrome site was deleted')
                ->line('Your account ended without a paid plan, so its sites, their files and their databases have been deleted.')
                ->line('Your account is still here. Upgrade to Starter any time to build again.')
                ->action('See plans', $upgrade),
            'payment_failed' => (new MailMessage)
                ->subject('Your codeinchrome payment did not go through')
                ->line('We could not take your latest payment. Nothing has changed: your sites keep running on Starter.')
                ->line("Please update your card before $date. If the payment is still missing then, your account moves to the free plan, which pauses your sites.")
                ->action('Update your card', $upgrade),
            'payment_final' => (new MailMessage)
                ->subject('Your codeinchrome sites are paused tomorrow')
                ->line("Your payment is still missing. On $date your account moves to the free plan and your sites are paused.")
                ->line('Update your card before then and nothing changes.')
                ->action('Update your card', $upgrade),
            'downgraded' => (new MailMessage)
                ->subject('Your codeinchrome sites are paused')
                ->line('Your payment is still missing, so your account has moved to the free plan and your sites are paused. Nothing has been deleted.')
                ->line("Pay before $date and they come back exactly as they were. After that they are deleted; we keep a final backup of each for 30 days.")
                ->action('Pay and bring them back', $upgrade),
            'storage' => (new MailMessage)
                ->subject('Your codeinchrome sites are over their storage')
                ->line('Your sites and their databases use more storage than your plan includes.')
                ->line('Until that is fixed, new sites cannot be created and files cannot be uploaded. Your sites keep running.')
                ->line('Laravel stores uploads on Cloudflare R2 or Amazon S3 with a few lines of configuration, which keeps them off your plan entirely. The site settings page shows how.')
                ->action('Open your dashboard', route('dashboard')),
        };
    }
}
