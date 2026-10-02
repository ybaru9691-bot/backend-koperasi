<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\HasApiTokens;

class Member extends Authenticatable
{
    use HasApiTokens, HasFactory;
    protected $table = 'members';
    protected $fillable = [
        'user_id',
        'member_number',
        'no_register',
        'nik',
        'name',
        'email',
        'password',
        'pin_code',

        // 1. Data Identitas Anggota
        'place_of_birth',
        'tempat_lahir',
        'date_of_birth',
        'tanggal_lahir',
        'gender',
        'jenis_kelamin',
        'phone',
        'no_hp',
        'occupation',
        'pekerjaan',
        'education',
        'pendidikan',
        'family_status',
        'status_keluarga',
        'church_sector',
        'sektor_gereja',
        'address',
        'alamat',

        // 2. Data Ahli Waris
        'heir_name',
        'nama_ahli_waris',
        'heir_relationship',
        'hubungan_ahli_waris',
        'heir_place_of_birth',
        'heir_date_of_birth',
        'heir_address',
        'alamat_ahli_waris',

        // 3. Rincian Setoran Awal Keuangan
        'registration_fee',
        'principal_savings',
        'simpanan_pokok',
        'mandatory_savings',
        'simpanan_wajib',
        'voluntary_savings',
        'simpanan_sukarela',
        'social_fund',
        'grief_fund',
        'dana_duka',
        'status',
        'daily_savings',
        'buku_putih_no',
        'has_buku_biru',
        'has_buku_putih',
        'is_white_book_active',
        'created_at',
        'updated_at',
    ];

    protected $hidden = [
        'password',
        'pin_code',
    ];

    protected $appends = [
        'full_name',
        'nia',
        'member_no',
        'church',
        'church_unit',
        'no_hp',
        'no_register',
        'tempat_lahir',
        'tanggal_lahir',
        'jenis_kelamin',
        'pekerjaan',
        'pendidikan',
        'status_keluarga',
        'sektor_gereja',
        'alamat',
        'simpanan_pokok',
        'simpanan_wajib',
        'simpanan_sukarela',
        'tabungan_harian',
        'dana_duka',
        'uang_pangkal',
        'nama_ahli_waris',
        'hubungan_ahli_waris',
        'alamat_ahli_waris',
        'total_portfolio',
        'total_portofolio',
        'total_simpanan',
        'total_portofolio_simpanan',
        'total_saldo',
        'buku_biru',
        'buku_putih',
    ];

    protected $casts = [
        'date_of_birth'        => 'date:Y-m-d',
        'heir_date_of_birth'   => 'date:Y-m-d',
        'registration_fee'     => 'decimal:2',
        'principal_savings'    => 'decimal:2',
        'mandatory_savings'    => 'decimal:2',
        'voluntary_savings'    => 'decimal:2',
        'daily_savings'        => 'decimal:2',
        'social_fund'          => 'decimal:2',
        'grief_fund'           => 'decimal:2',
        'password'             => 'hashed',
        'has_buku_biru'        => 'boolean',
        'has_buku_putih'       => 'boolean',
        'is_white_book_active' => 'boolean',
    ];

    
    // ACCESSORS (Kompatibilitas Alias Flutter UI)
    public function getFullNameAttribute()
    {
        return $this->attributes['name'] ?? null;
    }

    public function getNiaAttribute()
    {
        return $this->attributes['member_number'] ?? null;
    }

    public function getMemberNoAttribute(): ?string
    {
        return $this->attributes['member_number'] ?? null;
    }

    public function getChurchAttribute()
    {
        return $this->attributes['church_sector'] ?? null;
    }

    public function getChurchUnitAttribute()
    {
        return $this->attributes['church_sector'] ?? null;
    }

    public function getTabunganHarianAttribute()
    {
        return (float) ($this->attributes['daily_savings'] ?? 0);
    }

    public function getUangPangkalAttribute()
    {
        return (float) ($this->attributes['registration_fee'] ?? 0);
    }

    public function getTotalPortfolioAttribute()
    {
        $pokok    = (float) ($this->attributes['principal_savings'] ?? 0);
        $wajib    = (float) ($this->attributes['mandatory_savings'] ?? 0);
        $sukarela = (float) ($this->attributes['voluntary_savings'] ?? 0);
        $harian   = (float) ($this->attributes['daily_savings'] ?? 0);
        return $pokok + $wajib + $sukarela + $harian;
    }

    public function getTotalPortofolioAttribute()
    {
        return $this->getTotalPortfolioAttribute();
    }

    public function getTotalSimpananAttribute()
    {
        return $this->getTotalPortfolioAttribute();
    }

    public function getTotalPortofolioSimpananAttribute()
    {
        return $this->getTotalPortfolioAttribute();
    }

    public function getNoHpAttribute()
    {
        return $this->attributes['phone'] ?? null;
    }

    public function getNoRegisterAttribute()
    {
        return $this->attributes['member_number'] ?? null;
    }

    public function getTempatLahirAttribute()
    {
        return $this->attributes['place_of_birth'] ?? null;
    }

    public function getTanggalLahirAttribute()
    {
        return $this->attributes['date_of_birth'] ?? null;
    }

    public function getJenisKelaminAttribute()
    {
        return $this->attributes['gender'] ?? null;
    }

    public function getPekerjaanAttribute()
    {
        return $this->attributes['occupation'] ?? null;
    }

    public function getPendidikanAttribute()
    {
        return $this->attributes['education'] ?? null;
    }

    public function getStatusKeluargaAttribute()
    {
        return $this->attributes['family_status'] ?? null;
    }

    public function getSektorGerejaAttribute()
    {
        return $this->attributes['church_sector'] ?? null;
    }

    public function getAlamatAttribute()
    {
        return $this->attributes['address'] ?? null;
    }

    public function getSimpananPokokAttribute()
    {
        return (float) ($this->attributes['principal_savings'] ?? 0);
    }

    public function getSimpananWajibAttribute()
    {
        return (float) ($this->attributes['mandatory_savings'] ?? 0);
    }

    public function getSimpananSukarelaAttribute()
    {
        return (float) ($this->attributes['voluntary_savings'] ?? 0);
    }

    public function getDanaDukaAttribute()
    {
        return (float) ($this->attributes['grief_fund'] ?? $this->attributes['social_fund'] ?? 0);
    }

    public function getNamaAhliWarisAttribute()
    {
        return $this->attributes['heir_name'] ?? null;
    }

    public function getHubunganAhliWarisAttribute()
    {
        return $this->attributes['heir_relationship'] ?? null;
    }

    public function getAlamatAhliWarisAttribute()
    {
        return $this->attributes['heir_address'] ?? null;
    }

    public function getTotalSaldoAttribute()
    {
        $pokok    = (float) ($this->attributes['principal_savings'] ?? 0);
        $wajib    = (float) ($this->attributes['mandatory_savings'] ?? 0);
        $sukarela = (float) ($this->attributes['voluntary_savings'] ?? 0);
        return $pokok + $wajib + $sukarela;
    }

    
    // MUTATORS (Menulis Alias Bahasa Indonesia)
    public function setNoRegisterAttribute($value)
    {
        $this->attributes['member_number'] = $value;
    }

    public function setTempatLahirAttribute($value)
    {
        $this->attributes['place_of_birth'] = $value;
    }

    public function setTanggalLahirAttribute($value)
    {
        $this->attributes['date_of_birth'] = $value;
    }

    public function setJenisKelaminAttribute($value)
    {
        $this->attributes['gender'] = $value;
    }

    public function setPekerjaanAttribute($value)
    {
        $this->attributes['occupation'] = $value;
    }

    public function setPendidikanAttribute($value)
    {
        $this->attributes['education'] = $value;
    }

    public function setStatusKeluargaAttribute($value)
    {
        $this->attributes['family_status'] = $value;
    }

    public function setSektorGerejaAttribute($value)
    {
        $this->attributes['church_sector'] = $value;
    }

    public function setAlamatAttribute($value)
    {
        $this->attributes['address'] = $value;
    }

    public function setSimpananPokokAttribute($value)
    {
        $this->attributes['principal_savings'] = $value;
    }

    public function setSimpananWajibAttribute($value)
    {
        $this->attributes['mandatory_savings'] = $value;
    }

    public function setSimpananSukarelaAttribute($value)
    {
        $this->attributes['voluntary_savings'] = $value;
    }

    public function setDanaDukaAttribute($value)
    {
        $this->attributes['grief_fund'] = $value;
        $this->attributes['social_fund'] = $value;
    }

    public function setNamaAhliWarisAttribute($value)
    {
        $this->attributes['heir_name'] = $value;
    }

    public function setHubunganAhliWarisAttribute($value)
    {
        $this->attributes['heir_relationship'] = $value;
    }

    public function setAlamatAhliWarisAttribute($value)
    {
        $this->attributes['heir_address'] = $value;
    }

    public function getBukuBiruAttribute()
    {
        $pokok    = (float) ($this->attributes['principal_savings'] ?? 0);
        $wajib    = (float) ($this->attributes['mandatory_savings'] ?? 0);
        $sukarela = (float) ($this->attributes['voluntary_savings'] ?? 0);
        $pangkal  = (float) ($this->attributes['registration_fee'] ?? 0);
        $duka     = (float) ($this->attributes['grief_fund'] ?? $this->attributes['social_fund'] ?? 0);
        $totalSaham = $pokok + $wajib + $sukarela;

        return [
            'simpanan_pokok'    => $pokok,
            'simpanan_wajib'    => $wajib,
            'simpanan_sukarela' => $sukarela,
            'principal_savings' => $pokok,
            'mandatory_savings' => $wajib,
            'voluntary_savings' => $sukarela,
            'uang_pangkal'      => $pangkal,
            'registration_fee'  => $pangkal,
            'dana_duka'         => $duka,
            'grief_fund'        => $duka,
            'social_fund'       => $duka,
            'total_saham'       => $totalSaham,
            'total'             => $totalSaham + $pangkal + $duka,
        ];
    }

    public function getBukuPutihAttribute()
    {
        $harian  = (float) ($this->attributes['daily_savings'] ?? 0);
        $pangkal = (float) ($this->attributes['registration_fee'] ?? 0);
        $duka    = (float) ($this->attributes['grief_fund'] ?? $this->attributes['social_fund'] ?? 0);

        return [
            'tabungan_harian'   => $harian,
            'simpanan_harian'   => $harian,
            'daily_savings'     => $harian,
            'saldo_buku_putih'  => $harian,
            'minimal_mengendap' => 100000.00,
            'saldo_bisa_ditarik'=> max(0.00, $harian - 100000.00),
            'total'             => $harian,
        ];
    }

    
    // RELASI MODEL
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'member_id');
    }

    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class, 'member_id');
    }

    /**
     * Scope a query to only include active members.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Ambil saldo simpanan anggota pada tanggal cut-off tertentu (SP, SW, SS, atau Total Saham)
     */
    public function getSavingsBalanceAt(string $type, string $date): float
    {
        $type = strtoupper($type);
        $current = match ($type) {
            'SP' => (float) ($this->principal_savings ?? $this->simpanan_pokok ?? 0.0),
            'SW' => (float) ($this->mandatory_savings ?? $this->simpanan_wajib ?? 0.0),
            'SS' => (float) ($this->voluntary_savings ?? $this->simpanan_sukarela ?? 0.0),
            'TOTAL', 'SAHAM' => (float) (($this->principal_savings ?? 0.0) + ($this->mandatory_savings ?? 0.0) + ($this->voluntary_savings ?? 0.0)),
            default => 0.0,
        };

        // Mutasi setelah tanggal cut-off $date
        $futureNet = (float) Transaction::where('member_id', $this->id)
            ->where('book_type', 'BUKU_BIRU')
            ->where('status', 'approved')
            ->whereDate('transaction_date', '>', $date)
            ->where(function ($q) use ($type) {
                if ($type === 'SP') {
                    $q->where('category', 'simpanan_pokok')->orWhere('description', 'like', '%pokok%');
                } elseif ($type === 'SW') {
                    $q->where('category', 'simpanan_wajib')->orWhere('description', 'like', '%wajib%');
                } elseif ($type === 'SS') {
                    $q->where('category', 'simpanan_sukarela')
                      ->orWhere('category', 'bunga_saham')
                      ->orWhere(function ($sub) {
                          $sub->whereNotIn('category', ['simpanan_pokok', 'simpanan_wajib'])
                              ->where('description', 'not like', '%pokok%')
                              ->where('description', 'not like', '%wajib%');
                      });
                }
            })
            ->selectRaw("
                SUM(CASE WHEN type IN ('deposit', 'in', 'kas_masuk', 'KM') THEN amount 
                         WHEN type IN ('withdrawal', 'out', 'kas_keluar', 'KK') THEN -amount 
                         ELSE 0 END) as net
            ")
            ->value('net') ?? 0.0;

        return max(0.0, round($current - $futureNet, 2));
    }
}