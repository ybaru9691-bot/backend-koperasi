<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Model DividendDistribution (Mapping ke tabel shu_distributions)
 */
class DividendDistribution extends Model
{
    use HasFactory;

    protected $table = 'shu_distributions';

    protected $fillable = [
        'period_id',
        'period_name',
        'member_id',
        'member_name',
        'member_number',
        'simpanan_pokok',
        'simpanan_wajib',
        'total_saham',
        'jasa_saham',
        'deviden',
        'gross_shu',
        'potongan_duka',
        'potongan_wajib',
        'total_potongan',
        'net_shu',
        'status',
        'distributed_at',
        'notes',
    ];

    protected $casts = [
        'simpanan_pokok' => 'decimal:2',
        'simpanan_wajib' => 'decimal:2',
        'total_saham'    => 'decimal:2',
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
