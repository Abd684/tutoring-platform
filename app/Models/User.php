<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public $timestamps = false;

    protected $fillable = [
        'name', 'phone', 'email', 'password_hash', 'role', 'status', 'region_id',
    ];

    protected $hidden = [
        'password_hash',
    ];

    protected function casts(): array
    {
        return [
            'password_hash' => 'hashed',
        ];
    }

    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    public function getAuthPassword(): string
    {
        return (string) $this->password_hash;
    }

    public function student(): HasOne { return $this->hasOne(Student::class); }
    public function region(): BelongsTo { return $this->belongsTo(Region::class); }
    public function teacher(): HasOne { return $this->hasOne(Teacher::class); }
    public function payments(): HasMany { return $this->hasMany(Payment::class, 'payer_id'); }
    public function wallets(): HasMany { return $this->hasMany(Wallet::class); }
    public function notifications(): HasMany { return $this->hasMany(Notification::class); }
    public function auditLogs(): HasMany { return $this->hasMany(AuditLog::class); }
}
