<?php

namespace App\Notifications;

use App\Models\Site;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/** A free site with no visitors and no edits for a month: warned, then paused (sites:idle). */
class SiteIdleNotice extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Site $site,
        public readonly string $kind,          // warning | paused
        public readonly ?Carbon $when = null,  // warning: when it will be paused
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $domain = $this->site->domain;

        return $this->kind === 'warning'
            ? (new MailMessage)
                ->subject("$domain will be paused for inactivity")
                ->line("$domain has had no visitors and no edits for almost a month. Free sites that stay that way are paused, so that the room goes to sites people are using.")
                ->line('It will be paused on '.$this->when?->utc()->format('l j F, H:i').' UTC. Open it or visit it before then and nothing happens.')
                ->line('Pausing deletes nothing, and you can bring it back with one click.')
                ->action('Open your dashboard', route('dashboard'))
            : (new MailMessage)
                ->subject("$domain is paused for inactivity")
                ->line("$domain had no visitors and no edits for 30 days, so it is paused: it is not served until you bring it back.")
                ->line('Nothing is deleted. One click on your dashboard brings it back exactly as it was.')
                ->action('Bring it back', route('dashboard'));
    }
}
