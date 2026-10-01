<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InitialAccountBalance extends Model
{
    use HasFactory;

    protected $table = 'initial_account_balances';

    protected $fillable = [
        'cutoff_date',
        'account_code',
        'debit',
        'credit',
    ];

    protected $casts = [
        'cutoff_date' => 'date:Y-m-d',
        'debit'       => 'float',
        'credit'      => 'float',
        'created_at'  => 'datetime',
        'updated_at'  => 'datetime',
    ];

    public function chartOfAccount()
    {
        return $this->belongsTo(ChartOfAccount::class, 'account_code', 'account_code');
    }
}
