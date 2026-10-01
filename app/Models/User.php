<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'nik',
        'name',
        'email',
        'phone_number',
        'avatar',
        'password',
        'role',
        'pin_code',
        'is_active',
    ];

    protected $appends = [
        'avatar_url',
    ];

    public function getAvatarUrlAttribute(): ?string
    {
        if (!$this->avatar) {
            return null;
        }

        if (filter_var($this->avatar, FILTER_VALIDATE_URL)) {
            return $this->avatar;
        }

        return url('storage/' . ltrim($this->avatar, '/'));
    }

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password'          => 'hashed',
        'is_active'         => 'boolean',
    ];

    // Relasi ke transaksi yang dicatat/di-handle user ini
    public function transactions()
    {
        return $this->hasMany(Transaction::class, 'created_by');
    }

    // Relasi ke pinjaman yang disetujui user ini
    public function approvedLoans()
    {
        return $this->hasMany(Loan::class, 'approved_by');
    }

    // Relasi ke profil detail member
    public function member()
    {
        return $this->hasOne(Member::class, 'user_id');
    }
}