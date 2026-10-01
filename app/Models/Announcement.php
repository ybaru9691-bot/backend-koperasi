<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Announcement extends Model
{
    use HasFactory;

    protected $table = 'announcements';

    protected $fillable = [
        'title',
        'content',
        'category',
        'author_name',
        'author_role',
        'created_by',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Scope: hanya pengumuman aktif
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Accessor: format tanggal Indonesia yang rapi
     */
    public function getFormattedDateAttribute(): string
    {
        return $this->created_at
            ? $this->created_at->locale('id')->isoFormat('D MMMM YYYY, HH:mm')
            : '-';
    }

    /**
     * Accessor: time ago format
     */
    public function getTimeAgoAttribute(): string
    {
        return $this->created_at
            ? $this->created_at->locale('id')->diffForHumans()
            : '-';
    }
}
