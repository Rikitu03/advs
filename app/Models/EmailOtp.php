<?php

namespace App\Models;

use Database\Factories\EmailOtpFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class EmailOtp extends Model
{
    /** @use HasFactory<EmailOtpFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'challenge_id', 'code', 'attempts', 'max_attempts', 'expires_at', 'verified_at', 'sent_at'];

    protected $hidden = ['code'];

    protected function casts(): array
    {
        return ['attempts' => 'integer', 'max_attempts' => 'integer', 'expires_at' => 'datetime', 'verified_at' => 'datetime', 'sent_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at instanceof Carbon && $this->expires_at->isPast();
    }

    public function isUsable(): bool
    {
        return $this->verified_at === null && ! $this->isExpired() && $this->attempts < $this->max_attempts;
    }
}
