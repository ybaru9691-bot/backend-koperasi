<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChartOfAccount extends Model
{
    use HasFactory;

    protected $table = 'chart_of_accounts';

    protected $fillable = [
        'account_code',
        'account_name',
        'account_type',
        'normal_balance',
        'parent_code',
        'is_active',
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('account_type', strtoupper($type));
    }

    public function parent()
    {
        return $this->belongsTo(ChartOfAccount::class, 'parent_code', 'account_code');
    }

    public function children()
    {
        return $this->hasMany(ChartOfAccount::class, 'parent_code', 'account_code');
    }
 
    public function getCodeAttribute(): ?string
    {
        return $this->account_code;
    }

    public function setCodeAttribute($value): void
    {
        $this->attributes['account_code'] = $value;
    }

    public function getDisplayLabelAttribute(): string
    {
        return "[{$this->account_code}] {$this->account_name}";
    }

    public function getNormalBalanceLabelAttribute():string
    {
        return match (strtoupper($this->normal_balance)) {
            'DEBIT'  => 'Debit',
            'CREDIT' => 'Kredit',
            default  => $this->normal_balance,
        };
    }

    public function getAccountTypeLabelAttribute(): string
    {
        return match (strtoupper($this->account_type)) {
            'ASSET'     => 'Aset',
            'LIABILITY' => 'Kewajiban',
            'EQUITY'    => 'Modal',
            'REVENUE'   => 'Pendapatan',
            'EXPENSE'   => 'Beban',
            default     => $this->account_type,
        };
    }
}
