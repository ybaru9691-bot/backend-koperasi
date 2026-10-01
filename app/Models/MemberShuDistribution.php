<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MemberShuDistribution extends Model
{
    use HasFactory;

    protected $fillable = [
        'period_id',
        'member_id',
        'jasa_saham',
        'deviden',
        'gross_shu',
        'potongan_duka',
        'potongan_wajib',
        'total_potongan',
        'net_shu',
        'status',
        'distributed_at',
    ];

    protected $casts = [
        'jasa_saham'     => 'decimal:2',
        'deviden'        => 'decimal:2',
        'gross_shu'      => 'decimal:2',
        'potongan_duka'  => 'decimal:2',
        'potongan_wajib' => 'decimal:2',
        'total_potongan' => 'decimal:2',
        'net_shu'        => 'decimal:2',
        'distributed_at' => 'datetime',
    ];

    public function period()
    {
        return $this->belongsTo(Period::class);
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }
}