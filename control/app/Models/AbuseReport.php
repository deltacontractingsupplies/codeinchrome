<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['site_id', 'url', 'reason', 'details', 'reporter_email', 'reporter_hash'])]
class AbuseReport extends Model
{
    public const REASONS = [
        'phishing' => 'Phishing or a fake sign-in page',
        'malware' => 'Malware, or a download that harms devices',
        'scam' => 'A scam or fraud',
        'adult' => 'Adult or sexual content',
        'minors' => 'Content that harms or sexualises children',
        'copyright' => 'Copyright or trademark infringement',
        'spam' => 'Spam',
        'hate' => 'Hate, violence or extremism',
        'other' => 'Something else',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
