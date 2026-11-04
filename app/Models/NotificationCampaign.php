<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class NotificationCampaign extends Model
{
    protected $fillable = ['created_by', 'target_scope', 'target_user_id', 'title', 'body', 'data', 'status', 'scheduled_at', 'dispatched_at'];

    protected function casts(): array
    {
        return ['data' => 'array', 'scheduled_at' => 'datetime', 'dispatched_at' => 'datetime'];
    }

    public function scopeForRecipient(Builder $query, User $user): Builder
    {
        return $query->where(function ($q) use ($user) {
            $q->where('target_scope', 'all');
            if (in_array($user->role, ['student', 'teacher'], true)) {
                $q->orWhere('target_scope', $user->role === 'student' ? 'students' : 'teachers')
                    ->orWhere(fn ($q) => $q->where('target_scope', $user->role)->where('target_user_id', $user->id));
            }
        });
    }

    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('status', 'dispatched')->where('dispatched_at', '<=', now())
            ->where('dispatched_at', '>', now()->subDays(30));
    }
}
