<?php

namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreMemberRequest;
use App\Models\Account;
use App\Models\Loan;
use App\Models\Member;
use App\Models\Transaction;
use App\Models\User;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalDetail;
use App\Services\JournalService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MemberController extends Controller
{
   // fugsi untuk member list
    private function parseCleanNumber($val, float $default = 0.0): float
    {
        if (is_null($val) || $val === '') return $default;
        if (is_int($val) || is_float($val)) return (float) $val;

        // Jika berbentuk string dengan format ribuan "300.000" atau "Rp 300.000", hapus semua non-digit
        $clean = preg_replace('/[^\d]/', '', (string) $val);
        return is_numeric($clean) ? (float) $clean : $default;
    }

    //beri list anggota untuk admin web
    // beri list anggota untuk admin web
    public function index(Request $request): JsonResponse
    {
        $search = trim((string) ($request->input('search') ?? $request->input('q') ?? $request->input('query') ?? $request->input('keyword') ?? ''));
        $sort   = $request->input('sort');  
        $status = $request->input('status');

        $query = Member::when($status, function ($q) use ($status) {
            $q->where('status', $status);
        })->when($search !== '', function ($query) use ($search) {
            $cleanNumber = ltrim($search, '0');

            $query->where(function ($q) use ($search, $cleanNumber) {
                $q->where('name', 'LIKE', "%{$search}%")
                  ->orWhere('member_number', 'LIKE', "%{$search}%");

                if ($cleanNumber !== '') {
                    $q->orWhere('member_number', $cleanNumber);
                }
            });
        });

        if ($sort === 'name') {
            $query->orderBy('name', 'asc');
        } else {
            $query->orderBy('member_number', 'asc');
        }

        $isAll = $request->boolean('all') || $request->input('per_page') === 'all' || $request->input('limit') === 'all';

        if (!$isAll && ($request->has('page') || $request->has('per_page') || $request->has('limit'))) {
            $perPage = (int) ($request->input('per_page') ?? $request->input('limit') ?? 25);
            $paginated = $query->paginate($perPage);
            return response()->json([
                'success'        => true,
                'status'         => 'success',
                'message'        => 'Data anggota berhasil diambil',
                'current_page'   => $paginated->currentPage(),
                'data'           => $paginated->items(),
                'first_page_url' => $paginated->url(1),
                'from'           => $paginated->firstItem(),
                'last_page'      => $paginated->lastPage(),
                'last_page_url'  => $paginated->url($paginated->lastPage()),
                'links'          => $paginated->linkCollection()->toArray(),
                'next_page_url'  => $paginated->nextPageUrl(),
                'path'           => $paginated->path(),
                'per_page'       => $paginated->perPage(),
                'prev_page_url'  => $paginated->previousPageUrl(),
                'to'             => $paginated->lastItem(),
                'total'          => $paginated->total(),
            ], 200);
        }

        $members = $query->get();

        return response()->json([
            'success' => true,
            'status'  => 'success',
            'message' => 'Data anggota berhasil diambil',
            'data'    => $members
        ], 200);
    }

    /**
     * Endpoint khusus untuk dropdown / pemilih anggota aktif (tanpa limit pagination ketat).
     * Endpoint: GET /api/members/active-list & GET /api/members/active
     */
    public function activeList(Request $request): JsonResponse
    {
        $search = trim((string) ($request->input('search') ?? $request->input('q') ?? $request->input('query') ?? $request->input('keyword') ?? ''));

        $query = Member::whereIn('status', ['active', 'ACTIVE'])
            ->when($search !== '', function ($query) use ($search) {
                $cleanNumber = ltrim($search, '0');
                $query->where(function ($q) use ($search, $cleanNumber) {
                    $q->where('name', 'LIKE', "%{$search}%")
                      ->orWhere('member_number', 'LIKE', "%{$search}%");
                    if ($cleanNumber !== '') {
                        $q->orWhere('member_number', $cleanNumber);
                    }
                });
            })
            ->orderBy('name', 'asc');

        $members = $query->get(['id', 'name', 'member_number', 'nik', 'phone', 'status', 'has_buku_biru', 'has_buku_putih', 'is_white_book_active']);

        $data = $members->map(function ($m) {
            return [
                'id'                   => $m->id,
                'name'                 => $m->name,
                'nama'                 => $m->name,
                'member_no'            => $m->member_number,
                'member_number'        => $m->member_number,
                'no_anggota'           => $m->member_number,
                'nik'                  => $m->nik,
                'phone'                => $m->phone,
                'no_hp'                => $m->phone,
                'status'               => $m->status,
                'has_buku_biru'        => (bool) $m->has_buku_biru,
                'has_buku_putih'       => (bool) $m->has_buku_putih,
                'is_white_book_active' => (bool) ($m->is_white_book_active ?? true),
                'white_book_active'    => (bool) ($m->is_white_book_active ?? true),
            ];
        });

        return response()->json([
            'status'  => 'success',
            'success' => true,
            'message' => 'Daftar anggota aktif berhasil diambil',
            'data'    => $data,
        ], 200);
    }

    // pencarian anggota untuk auto complete di flutter admin oleh web
    public function search(Request $request): JsonResponse
    {
        $search = trim((string) ($request->input('q') ?? $request->input('query') ?? $request->input('search') ?? $request->input('keyword') ?? ''));
        $limit  = $request->input('limit', 100);
        $statusFilter = $request->input('status', 'active');

        $memberQuery = Member::when($statusFilter !== 'all', function ($q) use ($statusFilter) {
            $q->whereIn('status', [$statusFilter, strtoupper($statusFilter)]);
        })->when($search !== '', function ($q) use ($search) {
            $cleanNumber = ltrim($search, '0');

            $q->where(function ($sub) use ($search, $cleanNumber) {
                $sub->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('member_number', 'LIKE', "%{$search}%");

                if ($cleanNumber !== '') {
                    $sub->orWhere('member_number', $cleanNumber);
                }
            });
        })->orderBy('member_number', 'asc');

        if ($limit !== 'all' && is_numeric($limit) && (int) $limit > 0) {
            $memberQuery->take((int) $limit);
        }

        $members = $memberQuery->get(['id', 'name', 'nik', 'member_number', 'status']);

        // Format to map Indonesian/English aliases as well
        $data = $members->map(function ($member) {
            return [
                'id'            => $member->id,
                'name'          => $member->name,
                'nama'          => $member->name,
                'nik'           => $member->nik,
                'member_no'     => $member->member_number,
                'member_number' => $member->member_number,
                'no_anggota'    => $member->member_number,
                'no_register'   => $member->member_number,
            ];
        });

        return response()->json([
            'success' => true,
            'status'  => 'success',
            'message' => 'Pencarian anggota berhasil',
            'data'    => $data
        ], 200);
    }

    //pendaftaran anggota baru oleh admin
    public function store(StoreMemberRequest $request): JsonResponse
    {
        Log::info('=== REGISTER MEMBER REQUEST PAYLOAD ===', $request->all());

        $authUser = $request->user();
        $userRole = strtolower($authUser->role ?? '');
        $allowedRoles = ['admin', 'manager', 'ketua', 'pengurus'];

        if (!$authUser || !in_array($userRole, $allowedRoles)) {
            return response()->json([
                'success' => false,
                'message' => 'Akses ditolak. Hanya Admin / Manager / Pengurus yang diizinkan mendaftarkan anggota baru.',
                'data'    => null,
            ], 403);
        }

        DB::beginTransaction();
        try {
            // Data Identitas Anggota
            $memberNumber = $request->no_register ?? $request->member_number ?? $request->member_no ?? $request->no_anggota;
            if (!$memberNumber) {
                $memberNumber = 'PELITA-' . date('Ym') . '-' . str_pad((string) mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
            }

            $placeOfBirth = $request->place_of_birth ?? $request->tempat_lahir ?? $request->birth_place;
            $dateOfBirth  = $request->date_of_birth ?? $request->tanggal_lahir ?? $request->birth_date;
            $gender       = $request->gender ?? $request->jenis_kelamin;
            $occupation   = $request->occupation ?? $request->pekerjaan ?? $request->job;
            $education    = $request->education ?? $request->pendidikan;
            $familyStatus = $request->family_status ?? $request->status_keluarga;
            $churchSector = $request->church_sector ?? $request->sektor_gereja ?? $request->church_unit;
            $address      = $request->address ?? $request->alamat;
            $phone        = $request->phone ?? $request->no_hp ?? $request->handphone ?? $request->wa ?? $request->no_wa ?? $request->phone_number;

            // Data Ahli Waris
            $heirName         = $request->heir_name ?? $request->nama_ahli_waris;
            $heirRelationship = $request->heir_relationship ?? $request->hubungan_ahli_waris;
            $heirPlaceOfBirth = $request->heir_place_of_birth;
            $heirDateOfBirth  = $request->heir_date_of_birth;
            $heirAddress      = $request->heir_address ?? $request->alamat_ahli_waris;

            // Validasi Pemilihan Produk Buku
            $rawBookType = strtoupper((string) ($request->book_type ?? $request->tipe_buku ?? $request->product ?? ''));
            $isBothBooks = in_array($rawBookType, ['BOTH', 'KEDUA_BUKU', 'DUA_BUKU', 'SEMUA']);
            $isBiruOnly  = in_array($rawBookType, ['BUKU_BIRU', 'BIRU', 'SAHAM']);
            $isPutihOnly = in_array($rawBookType, ['BUKU_PUTIH', 'PUTIH', 'HARIAN']);

            $hasBukuBiru  = filter_var($request->has_buku_biru, FILTER_VALIDATE_BOOLEAN);
            $hasBukuPutih = filter_var($request->has_buku_putih, FILTER_VALIDATE_BOOLEAN);

            if ($isBothBooks) {
                $hasBukuBiru = true;
                $hasBukuPutih = true;
            } elseif ($isBiruOnly) {
                $hasBukuBiru = true;
                $hasBukuPutih = false;
            } elseif ($isPutihOnly) {
                $hasBukuBiru = false;
                $hasBukuPutih = true;
            }

            // Jika keduanya tidak didefinisikan secara eksplisit, default Buku Biru aktif dan Buku Putih non-aktif
            if (!$hasBukuBiru && !$hasBukuPutih) {
                $hasBukuBiru = true;
                $hasBukuPutih = false;
            }

            $rawSP = $request->initial_principal_savings ?? $request->principal_savings ?? $request->simpanan_pokok;
            $rawSW = $request->initial_mandatory_savings ?? $request->mandatory_savings ?? $request->simpanan_wajib;
            $rawSS = $request->initial_voluntary_savings ?? $request->voluntary_savings ?? $request->simpanan_sukarela;
            $rawPangkal = $request->registration_fee ?? $request->uang_pangkal;
            $rawDuka = $request->grief_fund ?? $request->social_fund ?? $request->dana_duka;
            $rawDaily = $request->initial_daily_savings ?? $request->daily_savings ?? $request->simpanan_harian ?? $request->tabungan_harian;
            $rawTotal = $request->total_pembayaran ?? $request->total_deposit ?? $request->total_payment;

            if ($hasBukuBiru && $hasBukuPutih) {
                // 1. Paket KEDUA BUKU (Biru + Putih)
                $registrationFee  = 40000.00;
                $griefFund        = 40000.00;
                $principalSavings = 200000.00;
                $mandatorySavings = 20000.00;
                $voluntarySavings = max(10000.00, $this->parseCleanNumber($rawSS, 10000.00));
                $dailySavings     = max(50000.00, $this->parseCleanNumber($rawDaily, 50000.00));
                
                $totalBukuBiru    = 20000.00 + 20000.00 + $principalSavings + $mandatorySavings + $voluntarySavings; // 270k+
                $totalBukuPutih   = 20000.00 + 20000.00 + $dailySavings; // 90k+
            } elseif ($hasBukuBiru) {
                // 2. Paket HANYA BUKU BIRU -> Rp 270.000+
                $registrationFee  = 20000.00;
                $griefFund        = 20000.00;
                $principalSavings = 200000.00;
                $mandatorySavings = 20000.00;
                $voluntarySavings = max(10000.00, $this->parseCleanNumber($rawSS, 10000.00));
                $dailySavings     = 0.00;
                
                $totalBukuBiru    = $registrationFee + $griefFund + $principalSavings + $mandatorySavings + $voluntarySavings;
                $totalBukuPutih   = 0.00;
            } elseif ($hasBukuPutih) {
                // 3. Paket HANYA BUKU PUTIH -> Rp 90.000+
                $registrationFee  = 20000.00;
                $griefFund        = 20000.00;
                $dailySavings     = max(50000.00, $this->parseCleanNumber($rawDaily, 50000.00));
                
                $principalSavings = 0.00;
                $mandatorySavings = 0.00;
                $voluntarySavings = 0.00;
                
                $totalBukuBiru    = 0.00;
                $totalBukuPutih   = $registrationFee + $griefFund + $dailySavings;
            } else {
                $registrationFee  = 0.00;
                $griefFund        = 0.00;
                $principalSavings = 0.00;
                $mandatorySavings = 0.00;
                $voluntarySavings = 0.00;
                $dailySavings     = 0.00;
                
                $totalBukuBiru    = 0.00;
                $totalBukuPutih   = 0.00;
            }

            $emailInput = $request->email;
            if (empty($emailInput)) {
                $emailInput = $request->nik . '@koperasi.com';
                if (User::where('email', $emailInput)->orWhere('nik', $request->nik)->exists()) {
                    $emailInput = $request->nik . '.' . time() . '@koperasi.com';
                }
            }

            // PIN String Murni (Preservasi angka 0 di depan seperti "080705")
            $rawPin = (string) ($request->new_pin ?? $request->pin ?? $request->pin_code ?? '123456');
            $rawPassword = $request->password ?? $rawPin;

            // b. Insert ke tabel `members`
            $member = Member::create([
                'user_id'             => null,
                'member_number'       => $memberNumber,
                'nik'                 => $request->nik,
                'name'                => $request->name,
                'email'               => $emailInput,
                'password'            => Hash::make($rawPassword),
                'pin_code'            => $rawPin,
                'place_of_birth'      => $placeOfBirth,
                'date_of_birth'       => $dateOfBirth,
                'gender'              => $gender,
                'phone'               => $phone,
                'occupation'          => $occupation,
                'education'           => $education,
                'family_status'       => $familyStatus,
                'church_sector'       => $churchSector,
                'address'             => $address,

                // Data Ahli Waris
                'heir_name'           => $heirName,
                'heir_relationship'   => $heirRelationship,
                'heir_place_of_birth' => $heirPlaceOfBirth,
                'heir_date_of_birth'  => $heirDateOfBirth,
                'heir_address'        => $heirAddress,

                // Saldo Setoran Awal
                'registration_fee'    => $registrationFee,
                'principal_savings'   => $principalSavings,
                'mandatory_savings'   => $mandatorySavings,
                'voluntary_savings'   => $voluntarySavings,
                'social_fund'         => $griefFund,
                'grief_fund'          => $griefFund,
                'daily_savings'       => $dailySavings,
                'buku_putih_no'       => $request->buku_putih_no ?? ($hasBukuPutih ? '2021-' . str_pad((string) preg_replace('/\D/', '', (string) $memberNumber), 4, '0', STR_PAD_LEFT) : null),
                'has_buku_biru'       => $hasBukuBiru,
                'has_buku_putih'      => $hasBukuPutih,

                'status'              => 'active',
            ]);

            // c. Insert ke tabel `transactions`
            $accountKas = Account::firstOrCreate(
                ['account_number' => 'KAS-101'],
                [
                    'account_name' => 'Kas Koperasi',
                    'account_type' => 'kas',
                    'category'     => 'asset',
                    'balance'      => 0.00,
                ]
            );

            $rawRegDate = $request->input('transaction_date')
                ?? $request->input('date')
                ?? $request->input('join_date')
                ?? $request->input('registration_date')
                ?? now()->toDateString();
            $regTrxDate = \Carbon\Carbon::parse($rawRegDate)->toDateString();

            if ($hasBukuBiru && $totalBukuBiru > 0) {
                $trxNumber = 'TRX-' . date('Ymd') . '-' . str_pad((string) mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
                $receiptNo = $request->receipt_number ?? $request->no_bukti ?? str_pad((string) mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
                $trxBiru = Transaction::create([
                    'transaction_number' => $trxNumber,
                    'receipt_number'     => $receiptNo,
                    'member_id'          => $member->id,
                    'account_id'         => $accountKas->id,
                    'book_type'          => 'BUKU_BIRU',
                    'operator_id'        => $authUser->id ?? null,
                    'approved_by'        => $authUser->id ?? null,
                    'type'               => 'deposit',
                    'amount'             => $totalBukuBiru,
                    'beginning_balance'  => 0.00,
                    'ending_balance'     => $totalBukuBiru,
                    'payment_method'     => $request->input('payment_method', 'cash'),
                    'transaction_date'   => $regTrxDate,
                    'description'        => "Setoran Awal Pembukaan Rekening (Buku Biru) - {$member->name}",
                    'status'             => 'approved',
                    'approved_at'        => now(),
                ]);

                // Generate Jurnal Otomatis (Split Jurnal: SP, SW, SS, Uang Pangkal, Dana Duka)
                try {
                    app(\App\Services\JournalService::class)->generateJournal($trxBiru);
                } catch (\Exception $e) {
                    Log::warning("[MemberController] Gagal auto-generate jurnal Buku Biru: " . $e->getMessage());
                }
            }

            if ($hasBukuPutih && $totalBukuPutih > 0) {
                $trxNumber2 = 'TRX-' . date('Ymd') . '-' . str_pad((string) mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
                
                // 💡 CEK KETERSEDIAAN $receiptNo DENGAN AMAN DENGAN OPERATOR ?? null
                $baseReceipt = $receiptNo ?? null;
                $receiptNo2 = $request->receipt_number_putih ?? ($baseReceipt ? $baseReceipt . '-P' : str_pad((string) mt_rand(1, 9999), 4, '0', STR_PAD_LEFT));
                $trxPutih = Transaction::create([
                    'transaction_number' => $trxNumber2,
                    'receipt_number'     => $receiptNo2,
                    'member_id'          => $member->id,
                    'account_id'         => $accountKas->id,
                    'book_type'          => 'BUKU_PUTIH',
                    'operator_id'        => $authUser->id ?? null,
                    'approved_by'        => $authUser->id ?? null,
                    'type'               => 'deposit',
                    'amount'             => $totalBukuPutih,
                    'beginning_balance'  => 0.00,
                    'ending_balance'     => $dailySavings,
                    'payment_method'     => $request->input('payment_method', 'cash'),
                    'transaction_date'   => $regTrxDate,
                    'description'        => "Setoran Awal Pembukaan Rekening (Buku Putih) - {$member->name}",
                    'status'             => 'approved',
                    'approved_at'        => now(),
                ]);

                // Generate Jurnal Otomatis (Buku Putih -> Simpanan Harian 2021)
                try {
                    app(\App\Services\JournalService::class)->generateJournal($trxPutih);
                } catch (\Exception $e) {
                    Log::warning("[MemberController] Gagal auto-generate jurnal Buku Putih: " . $e->getMessage());
                }
            }

            DB::commit();

            
            $memberFresh = $member->fresh(['transactions']);
            return response()->json([
                'success' => true,
                'status'  => 'success',
                'message' => 'Anggota baru berhasil dibuat & transaksi setoran awal berhasil dicatat!',
                'data'    => array_merge($memberFresh->toArray(), [
                    'member'      => $memberFresh,
                    'transaction' => Transaction::where('member_id', $member->id)->latest()->first(),
                ]),
            ], 201);


        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error($e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json([
                'success' => false,
                'message' => 'Gagal menyimpan data',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get detail of a specific member
     * Endpoint: GET /api/members/{id}
     */
    public function show($id): JsonResponse
    {
        $member = Member::with('user')->find($id);

        if (!$member) {
            return response()->json([
                'status'  => 'error',
                'success' => false,
                'message' => 'Data anggota tidak ditemukan',
                'data'    => null
            ], 404);
        }

        $simpananPokok    = (int) ($member->principal_savings ?? $member->simpanan_pokok ?? 0);
        $simpananWajib    = (int) ($member->mandatory_savings ?? $member->simpanan_wajib ?? 0);
        $simpananSukarela = (int) ($member->voluntary_savings ?? $member->simpanan_sukarela ?? 0);
        $dailySavings     = (int) ($member->daily_savings ?? 0);
        $totalSaldo       = $simpananPokok + $simpananWajib + $simpananSukarela + $dailySavings;

        $isInactive = (strtolower($member->status ?? 'active') === 'inactive' || strtolower($member->status ?? 'active') === 'pasif');
        $estimatedShu = $isInactive ? 0 : max(1450000, (int)($totalSaldo * 0.10));

        return response()->json([
            'status'  => 'success',
            'success' => true,
            'message' => 'Detail anggota berhasil diambil',
            'data'    => [
                'estimated_shu'  => $estimatedShu,
                'id'             => $member->id,
                'name'           => $member->name,
                'nik'            => $member->nik,
                'phone'          => $member->phone,
                'no_hp'          => $member->phone,
                'email'          => $member->email,
                'no_register'    => $member->member_number,
                'member_number'  => $member->member_number,
                'place_of_birth' => $member->place_of_birth,
                'tempat_lahir'   => $member->place_of_birth,
                'date_of_birth'  => $member->date_of_birth ? $member->date_of_birth->format('Y-m-d') : null,
                'tanggal_lahir'  => $member->date_of_birth ? $member->date_of_birth->format('Y-m-d') : null,
                'gender'         => $member->gender,
                'jenis_kelamin'  => $member->gender,
                'occupation'     => $member->occupation,
                'pekerjaan'      => $member->occupation,
                'education'      => $member->education,
                'pendidikan'     => $member->education,
                'family_status'  => $member->family_status,
                'status_keluarga'=> $member->family_status,
                'church_sector'  => $member->church_sector,
                'sektor_gereja'  => $member->church_sector,
                'address'        => $member->address,
                'alamat'         => $member->address,
                'status'               => $member->status,
                'has_buku_biru'        => (bool) $member->has_buku_biru,
                'has_buku_putih'       => (bool) $member->has_buku_putih,
                'is_white_book_active' => (bool) ($member->is_white_book_active ?? true),
                'white_book_active'    => (bool) ($member->is_white_book_active ?? true),
                'buku_putih_no'        => $member->buku_putih_no,
                'no_buku_putih'  => $member->buku_putih_no,
                'daily_savings'  => $dailySavings,
                'simpanan'       => [
                    'simpanan_pokok'    => $simpananPokok,
                    'simpanan_wajib'    => $simpananWajib,
                    'simpanan_sukarela' => $simpananSukarela,
                    'daily_savings'     => $dailySavings,
                    'total_saldo'       => $totalSaldo,
                    'buku_biru'         => $member->buku_biru,
                    'buku_putih'        => $member->buku_putih,
                ],
                'ahli_waris'     => [
                    'nama'           => $member->heir_name ?? $member->nama_ahli_waris ?? '-',
                    'hubungan'       => $member->heir_relationship ?? $member->hubungan_ahli_waris ?? '-',
                    'tempat_lahir'   => $member->heir_place_of_birth ?? '-',
                    'tanggal_lahir'  => $member->heir_date_of_birth ? (\Carbon\Carbon::parse($member->heir_date_of_birth)->format('Y-m-d')) : '-',
                    'alamat'         => $member->heir_address ?? $member->alamat_ahli_waris ?? '-',
                ],
                'details'        => $member,
            ]
        ], 200);
    }

    /**
     * Get real-time savings balances and maximum withdrawal limit for a member
     * Endpoint: GET /api/members/{id}/balances
     */
    public function getBalances($id): JsonResponse
    {
        $member = Member::find($id);

        if (!$member) {
            return response()->json([
                'status'  => 'error',
                'success' => false,
                'message' => 'Data anggota tidak ditemukan',
                'data'    => null
            ], 404);
        }

        $saldoBukuPutih = (float) ($member->daily_savings ?? 0.00);
        $saldoPokok     = (float) ($member->principal_savings ?? 0.00);
        $saldoWajib     = (float) ($member->mandatory_savings ?? 0.00);
        $saldoSukarela  = (float) ($member->voluntary_savings ?? 0.00);
        $saldoDuka      = (float) ($member->grief_fund ?: ($member->social_fund ?? 0.00));
        $uangPangkal    = (float) ($member->registration_fee ?? 0.00);

        $hasBukuPutih   = (bool) ($member->has_buku_putih || $saldoBukuPutih > 0);
        $hasBukuBiru    = (bool) ($member->has_buku_biru || ($saldoPokok + $saldoWajib + $saldoSukarela) > 0);

        $minimalMengendap = 100000.00;
        $saldoTersediaDitarik = $hasBukuPutih ? max(0.00, $saldoBukuPutih - $minimalMengendap) : 0.00;
        $totalSaldo = $saldoPokok + $saldoWajib + $saldoSukarela + $saldoBukuPutih;

        return response()->json([
            'success' => true,
            'status'  => 'success',
            'message' => 'Saldo anggota berhasil diambil',
            'data'    => [
                'member_id'                   => $member->id,
                'id'                          => $member->id,
                'full_name'                   => $member->name,
                'name'                        => $member->name,
                'nama'                        => $member->name,
                'nia'                         => $member->member_number,
                'member_number'               => $member->member_number,
                'buku_putih_no'               => $member->buku_putih_no,
                'no_buku_putih'               => $member->buku_putih_no,
                'has_buku_putih'              => $hasBukuPutih,
                'has_buku_biru'               => $hasBukuBiru,
                'saldo_buku_putih'            => $saldoBukuPutih,
                'tabungan_harian'             => $saldoBukuPutih,
                'daily_savings'               => $saldoBukuPutih,
                'minimal_saldo_mengendap'     => $minimalMengendap,
                'saldo_minimal_mengendap'     => $minimalMengendap,
                'saldo_tersedia_ditarik'      => $saldoTersediaDitarik,
                'saldo_bisa_ditarik'          => $saldoTersediaDitarik,
                'max_withdrawal'              => $saldoTersediaDitarik,
                'saldo_buku_biru'             => $saldoPokok + $saldoWajib + $saldoSukarela,
                'simpanan_pokok'              => $saldoPokok,
                'simpanan_wajib'              => $saldoWajib,
                'simpanan_sukarela'           => $saldoSukarela,
                'dana_duka'                   => $saldoDuka,
                'uang_pangkal'                => $uangPangkal,
                'total_saldo'                 => $totalSaldo,
                'total_portfolio'             => $totalSaldo,
                'total_simpanan'              => $totalSaldo,
            ],
        ], 200);
    }

    /**
     * Get full member details including savings breakdown, heir info, and transactions
     * Endpoint: GET /api/members/{id}/details
     */
   public function showDetails(Request $request, $id): JsonResponse
    {
        $member = Member::with(['user', 'transactions' => function ($q) {
            $q->latest();
        }])->find($id);

        if (!$member) {
            return response()->json([
                'status'  => 'error',
                'success' => false,
                'message' => 'Data anggota tidak ditemukan',
                'data'    => null
            ], 404);
        }

        $simpananPokok    = (int) ($member->principal_savings ?? $member->simpanan_pokok ?? 0);
        $simpananWajib    = (int) ($member->mandatory_savings ?? $member->simpanan_wajib ?? 0);
        $simpananSukarela = (int) ($member->voluntary_savings ?? $member->simpanan_sukarela ?? 0);
        $dailySavings     = (int) ($member->daily_savings ?? 0);
        $totalSaldo       = $simpananPokok + $simpananWajib + $simpananSukarela + $dailySavings;

        // 1. Ambil Net Cashflow Manajer periode berjalan & tentukan persentase SHU (default 70%)
        $totalKM = (float) \App\Models\Transaction::where('status', 'approved')
            ->whereIn('type', ['deposit', 'in', 'kas_masuk', 'KM'])
            ->sum('amount');

        $totalKK = (float) \App\Models\Transaction::where('status', 'approved')
            ->whereIn('type', ['withdrawal', 'out', 'kas_keluar', 'KK'])
            ->sum('amount');

        $netCashflow = $totalKM - $totalKK;
        if ($netCashflow <= 0) {
            $netCashflow = 123250000; // Fallback default
        }

        $shuMemberPool = $netCashflow * 0.70;

        // 2. Hitung Total Uang Buku Biru (Pokok + Wajib) Seluruh Anggota Aktif
        $totalAllShares = (float) (Member::whereIn('status', ['active', 'ACTIVE'])
            ->selectRaw('SUM(principal_savings + mandatory_savings) as total')
            ->value('total') ?? 0.0);

        // 3. Hitung Porsi Modal Anggota Ini
        $memberShares = ($simpananPokok ?? 0) + ($simpananWajib ?? 0);

        // 4. Hasil Estimasi SHU Anggota (Jika Pasif/Resign = 0)
        $isMemberActive = (strtolower(trim((string) ($member->status ?? ''))) === 'active');
        $estimatedShu = ($isMemberActive && $totalAllShares > 0) 
            ? (int) round(($memberShares / $totalAllShares) * $shuMemberPool) 
            : 0;

        $filter = strtolower((string) ($request->query('filter') ?? $request->query('type') ?? $request->query('tab') ?? $request->query('category') ?? ''));
        $rawTransactions = $member->transactions;

        $isBungaTrx = function ($trx) {
            $desc = strtolower($trx->description ?? '');
            $type = strtolower($trx->type ?? '');
            $category = strtolower($trx->category ?? '');
            $method = strtolower($trx->payment_method ?? '');

            if ($category === 'bunga_simpanan' || $type === 'interest') {
                return true;
            }

            if ($method === 'memorial' && (str_contains($desc, 'bunga') || str_contains($desc, 'jasa simpanan'))) {
                return true;
            }

            if (str_contains($desc, 'bunga simpanan') || str_contains($desc, 'bunga buku putih') || str_contains($desc, 'jasa simpanan')) {
                return true;
            }

            return false;
        };

        if (in_array($filter, ['bunga', 'interest', 'jasa', 'bunga_simpanan', 'jasa_simpanan'])) {
            $rawTransactions = $rawTransactions->filter(function ($trx) use ($isBungaTrx) {
                return $isBungaTrx($trx);
            });
        } elseif (in_array($filter, ['setor', 'deposit', 'in', 'kas_masuk', 'setoran', 'masuk', 'pemasukan', 'simpanan'])) {
            $rawTransactions = $rawTransactions->filter(function ($trx) use ($isBungaTrx) {
                $type = strtolower($trx->type ?? '');
                $typeIn = in_array($type, ['deposit', 'in', 'kas_masuk']);
                // Hanya keluarkan jika benar-benar bunga
                return $typeIn && !$isBungaTrx($trx);
            });
        } elseif (in_array($filter, ['tarik', 'withdrawal', 'out', 'kas_keluar', 'penarikan', 'keluar', 'pengeluaran'])) {
            $rawTransactions = $rawTransactions->filter(function ($trx) {
                $type = strtolower($trx->type ?? '');
                return in_array($type, ['withdrawal', 'out', 'kas_keluar']);
            });
        }

        $transactions = $rawTransactions->map(function ($trx) {
            $typeIn = in_array($trx->type, ['deposit', 'in', 'kas_masuk']);
            $dateStr = $trx->transaction_date 
                ? (is_string($trx->transaction_date) ? substr($trx->transaction_date, 0, 10) : $trx->transaction_date->format('Y-m-d'))
                : ($trx->created_at ? $trx->created_at->format('Y-m-d') : date('Y-m-d'));

            // 1. Ambil deskripsi asli dari database
            $rawDesc = trim($trx->description ?? '');

            // 2. Daftar teks generik / aneh yang perlu dinormalisasi
            $genericTexts = [
                'kas masuk',
                'kas keluar',
                'setoran simpanan kas masuk',
                'penarikan simpanan kas keluar'
            ];

            // 3. Pengecekan generik / aneh / kosong
            $isWeird = str_starts_with(strtolower($rawDesc), 'transaksi akun') 
                    || str_contains(strtolower($rawDesc), 'penyusutan') 
                    || str_contains(strtolower($rawDesc), 'akumulasi');
            $isGeneric = empty($rawDesc) || in_array(strtolower($rawDesc), $genericTexts) || $isWeird;

            // 4. Set displayTitle
            if (!$isGeneric) {
                $displayTitle = $rawDesc;
            } else {
                $displayTitle = $typeIn ? 'Setoran Simpanan Sukarela' : 'Penarikan Simpanan Sukarela';
            }

            $receiptNumber = $trx->receipt_number;
            if (empty($receiptNumber)) {
                if ($trx->transaction_number && !str_starts_with(strtoupper($trx->transaction_number), 'TRX')) {
                    $receiptNumber = preg_replace('/[^0-9]/', '', $trx->transaction_number);
                }
            }

            return [
                'id'                   => $trx->id,
                'formatted_receipt_no' => $trx->formatted_receipt_no,
                'transaction_number'   => $trx->transaction_number ?? ('TRX-' . $trx->id),
                'proof_number'         => $trx->transaction_number ?? ('TRX-' . $trx->id),
                'ref_no'               => $trx->transaction_number ?? ('TRX-' . $trx->id),
                'receipt_number'       => $receiptNumber,
                'title'                => $displayTitle,
                'description'          => $displayTitle,
                'category'             => $displayTitle,
                'date'                 => $dateStr,
                'transaction_date'     => $dateStr,
                'tanggal'              => $dateStr,
                'amount'               => (int) $trx->amount,
                'type'                 => $typeIn ? 'kas_masuk' : 'kas_keluar',
                'book_type'            => $trx->book_type ?? 'BUKU_BIRU',
                'created_at'           => $trx->created_at ? $trx->created_at->toISOString() : null,
            ];
        })->values();

        $joinedAtStr = \Carbon\Carbon::parse($member->created_at ?? now())->locale('id')->isoFormat('DD MMMM YYYY');

        return response()->json([
            'success' => true,
            'status'  => 'success',
            'data'    => [
                'estimated_shu'  => $estimatedShu,
                'id'             => $member->id,
                'name'           => $member->name,
                'member_number'  => $member->member_number,
                'no_register'    => $member->member_number,
                'nik'            => $member->nik,
                'no_kk'          => $member->no_kk ?? $member->nik,
                'phone'          => $member->phone,
                'no_hp'          => $member->phone,
                'email'          => $member->email,
                'status'         => $member->status ?? 'active',
                'joined_at'      => $joinedAtStr,
                'created_at'     => $member->created_at ? $member->created_at->toISOString() : now()->toISOString(),
                'has_buku_biru'  => (bool) $member->has_buku_biru,
                'has_buku_putih' => (bool) $member->has_buku_putih,
                'buku_putih_no'  => $member->buku_putih_no,
                'no_buku_putih'  => $member->buku_putih_no,
                'daily_savings'  => (int) ($member->daily_savings ?? 0),
                'place_of_birth' => $member->place_of_birth,
                'date_of_birth'  => $member->date_of_birth ? $member->date_of_birth->format('Y-m-d') : null,
                'gender'         => $member->gender,
                'occupation'     => $member->occupation,
                'education'      => $member->education,
                'family_status'  => $member->family_status,
                'church_sector'  => $member->church_sector,
                'address'        => $member->address,
                'simpanan'       => [
                    'simpanan_pokok'    => $simpananPokok,
                    'simpanan_wajib'    => $simpananWajib,
                    'simpanan_sukarela' => $simpananSukarela,
                    'simpanan_harian'   => $dailySavings,
                    'daily_savings'     => $dailySavings,
                    'total_saldo'       => $totalSaldo,
                    'buku_biru'         => $member->buku_biru,
                    'buku_putih'        => $member->buku_putih,
                ],
                'ahli_waris'     => [
                    'nama'           => $member->heir_name ?? $member->nama_ahli_waris ?? '-',
                    'hubungan'       => $member->heir_relationship ?? $member->hubungan_ahli_waris ?? '-',
                    'tempat_lahir'   => $member->heir_place_of_birth ?? '-',
                    'tanggal_lahir'  => $member->heir_date_of_birth ? (\Carbon\Carbon::parse($member->heir_date_of_birth)->format('Y-m-d')) : '-',
                    'alamat'         => $member->heir_address ?? $member->alamat_ahli_waris ?? '-',
                ],
                'transactions'   => $transactions,
            ],
        ], 200);
    }

    
   //update data anggota oleh admin
    public function update(Request $request, $id): JsonResponse
    {
        $member = Member::find($id);

        if (!$member) {
            return response()->json([
                'success' => false,
                'message' => 'Data anggota tidak ditemukan',
                'data'    => null
            ], 404);
        }

        // 1. Validasi Input (Buka izin update NIK dengan rule unique mengabaikan ID member ini)
        $validatedData = $request->validate([
            'name'                => 'nullable|string|max:255',
            'email'               => 'nullable|email|unique:members,email,' . $id,
            'phone'               => 'nullable|numeric|digits_between:10,13',
            'no_hp'               => 'nullable|numeric|digits_between:10,13',
            'handphone'           => 'nullable|numeric|digits_between:10,13',
            'buku_putih_no'       => 'nullable|string|max:50',
            'no_buku_putih'       => 'nullable|string|max:50',
            'rekening_buku_putih' => 'nullable|string|max:50',
            'nomor_buku_putih'    => 'nullable|string|max:50',
            'has_buku_biru'        => 'nullable|boolean',
            'has_buku_putih'       => 'nullable|boolean',
            'is_white_book_active' => 'nullable|boolean',
            'white_book_active'    => 'nullable|boolean',
            'nik'                  => [
                'nullable',
                'string',
                'max:20',
                \Illuminate\Validation\Rule::unique('members', 'nik')->ignore($member->id),
            ],
            'no_ktp'              => [
                'nullable',
                'string',
                'max:20',
                \Illuminate\Validation\Rule::unique('members', 'nik')->ignore($member->id),
            ],
            'ktp'                 => [
                'nullable',
                'string',
                'max:20',
                \Illuminate\Validation\Rule::unique('members', 'nik')->ignore($member->id),
            ],
            'member_number'       => 'nullable|string|max:30|unique:members,member_number,' . $id,
            'place_of_birth'      => 'nullable|string|max:255',
            'tempat_lahir'        => 'nullable|string|max:255',
            'birth_place'         => 'nullable|string|max:255',
            'date_of_birth'       => 'nullable|date',
            'tanggal_lahir'       => 'nullable|date',
            'birth_date'          => 'nullable|date',
            'gender'              => 'nullable|string|max:20',
            'jenis_kelamin'       => 'nullable|string|max:20',
            'occupation'          => 'nullable|string|max:255',
            'pekerjaan'           => 'nullable|string|max:255',
            'education'           => 'nullable|string|max:255',
            'pendidikan'          => 'nullable|string|max:255',
            'family_status'       => 'nullable|string|max:255',
            'status_keluarga'     => 'nullable|string|max:255',
            'church_sector'       => 'nullable|string|max:255',
            'sektor_gereja'       => 'nullable|string|max:255',
            'address'             => 'nullable|string',
            'alamat'              => 'nullable|string',
 
            // Data Ahli Waris
            'heir_name'               => 'nullable|string|max:255',
            'nama_ahli_waris'         => 'nullable|string|max:255',
            'heir_relationship'       => 'nullable|string|max:255',
            'hubungan_ahli_waris'     => 'nullable|string|max:255',
            'heir_place_of_birth'     => 'nullable|string|max:255',
            'tempat_lahir_ahli_waris' => 'nullable|string|max:255',
            'heir_birth_place'        => 'nullable|string|max:255',
            'heir_date_of_birth'      => 'nullable|date',
            'tanggal_lahir_ahli_waris' => 'nullable|date',
            'heir_birth_date'         => 'nullable|date',
            'heir_address'            => 'nullable|string',
            'alamat_ahli_waris'       => 'nullable|string',
 
            // PIN Opsional (Jika diisi Admin)
            'new_pin'             => 'nullable|numeric|digits:6',
            'daily_savings'       => 'nullable|numeric|min:0',
            'simpanan_harian'     => 'nullable|numeric|min:0',
            'tabungan_harian'     => 'nullable|numeric|min:0',
            'pin_code'            => 'nullable|numeric|digits:6',
            'pin'                 => 'nullable|numeric|digits:6',
            'status'              => 'nullable|string|in:active,inactive,suspended',
        ], [
            'nik.unique'           => 'Nomor NIK ini sudah terdaftar untuk anggota lain!',
            'no_ktp.unique'        => 'Nomor NIK ini sudah terdaftar untuk anggota lain!',
            'ktp.unique'           => 'Nomor NIK ini sudah terdaftar untuk anggota lain!',
            'phone.numeric'        => 'Nomor HP hanya boleh berisi angka!',
            'phone.digits_between' => 'Nomor HP harus diisi antara 10 sampai 13 digit angka!',
            'no_hp.numeric'        => 'Nomor HP hanya boleh berisi angka!',
            'no_hp.digits_between' => 'Nomor HP harus diisi antara 10 sampai 13 digit angka!',
            'handphone.numeric'    => 'Nomor HP hanya boleh berisi angka!',
            'handphone.digits_between' => 'Nomor HP harus diisi antara 10 sampai 13 digit angka!',
        ]);

        // 2. Mapping Alias Parameter Bahasa Indonesia -> Database Column
        $nikInput = $request->nik ?? $request->no_ktp ?? $request->ktp;
        if ($nikInput !== null) $validatedData['nik'] = $nikInput;

        $phoneInput = $request->phone ?? $request->no_hp ?? $request->handphone;
        if ($phoneInput !== null) $validatedData['phone'] = $phoneInput;

        if ($request->has('daily_savings') || $request->has('simpanan_harian') || $request->has('tabungan_harian')) {
            $rawDaily = $request->daily_savings ?? $request->simpanan_harian ?? $request->tabungan_harian;
            $dailyVal = is_numeric($rawDaily) ? (float)$rawDaily : (float)preg_replace('/[^0-9.]/', '', (string)$rawDaily);
            $validatedData['daily_savings'] = max(0.0, $dailyVal);
            if ($validatedData['daily_savings'] > 0) {
                $validatedData['has_buku_putih'] = true;
            }
        }

        if ($request->has('buku_putih_no') || $request->has('no_buku_putih') || $request->has('rekening_buku_putih') || $request->has('nomor_buku_putih')) {
            $rawBp = $request->buku_putih_no ?? $request->no_buku_putih ?? $request->rekening_buku_putih ?? $request->nomor_buku_putih;
            $strBp = trim((string)$rawBp);
            $validatedData['buku_putih_no'] = in_array(strtolower($strBp), ['', '-', '0', 'null', 'none', 'undefined']) ? null : $strBp;
            if (!empty($validatedData['buku_putih_no'])) {
                $validatedData['has_buku_putih'] = true;
            }
        }

        if ($request->has('has_buku_biru')) $validatedData['has_buku_biru'] = filter_var($request->has_buku_biru, FILTER_VALIDATE_BOOLEAN);
        if ($request->has('has_buku_putih')) $validatedData['has_buku_putih'] = filter_var($request->has_buku_putih, FILTER_VALIDATE_BOOLEAN);
        if ($request->has('is_white_book_active')) $validatedData['is_white_book_active'] = filter_var($request->is_white_book_active, FILTER_VALIDATE_BOOLEAN);
        if ($request->has('white_book_active')) $validatedData['is_white_book_active'] = filter_var($request->white_book_active, FILTER_VALIDATE_BOOLEAN);
        unset($validatedData['white_book_active']);

        if ($request->has('tempat_lahir')) $validatedData['place_of_birth'] = $request->tempat_lahir;
        if ($request->has('birth_place')) $validatedData['place_of_birth'] = $request->birth_place;
        if ($request->has('tanggal_lahir')) $validatedData['date_of_birth'] = $request->tanggal_lahir;
        if ($request->has('birth_date')) $validatedData['date_of_birth'] = $request->birth_date;
        if ($request->has('jenis_kelamin')) $validatedData['gender'] = $request->jenis_kelamin;
        if ($request->has('pekerjaan')) $validatedData['occupation'] = $request->pekerjaan;
        if ($request->has('pendidikan')) $validatedData['education'] = $request->pendidikan;
        if ($request->has('status_keluarga')) $validatedData['family_status'] = $request->status_keluarga;
        if ($request->has('sektor_gereja')) $validatedData['church_sector'] = $request->sektor_gereja;
        if ($request->has('alamat')) $validatedData['address'] = $request->alamat;

        if ($request->has('nama_ahli_waris')) $validatedData['heir_name'] = $request->nama_ahli_waris;
        if ($request->has('hubungan_ahli_waris')) $validatedData['heir_relationship'] = $request->hubungan_ahli_waris;
        if ($request->has('tempat_lahir_ahli_waris')) $validatedData['heir_place_of_birth'] = $request->tempat_lahir_ahli_waris;
        if ($request->has('heir_birth_place')) $validatedData['heir_place_of_birth'] = $request->heir_birth_place;
        if ($request->has('tanggal_lahir_ahli_waris')) $validatedData['heir_date_of_birth'] = $request->tanggal_lahir_ahli_waris;
        if ($request->has('heir_birth_date')) $validatedData['heir_date_of_birth'] = $request->heir_birth_date;
        if ($request->has('alamat_ahli_waris')) $validatedData['heir_address'] = $request->alamat_ahli_waris;

        // 3. Tangani Update PIN jika Admin mengisi form PIN Baru di layar yang sama
        $pinInput = $request->new_pin ?? $request->pin_code ?? $request->pin;
        if (!empty($pinInput)) {
            $validatedData['pin_code'] = (string) $pinInput;
            $validatedData['password'] = Hash::make((string) $pinInput);
        }

        // Hapus key alias yang tidak ada di tabel members
        unset(
            $validatedData['no_ktp'], $validatedData['ktp'],
            $validatedData['no_hp'], $validatedData['handphone'], $validatedData['no_register'],
            $validatedData['no_buku_putih'], $validatedData['rekening_buku_putih'], $validatedData['nomor_buku_putih'],
            $validatedData['simpanan_harian'], $validatedData['tabungan_harian'],
            $validatedData['tempat_lahir'], $validatedData['tanggal_lahir'], $validatedData['jenis_kelamin'],
            $validatedData['pekerjaan'], $validatedData['pendidikan'], $validatedData['status_keluarga'], $validatedData['sektor_gereja'], $validatedData['alamat'],
            $validatedData['nama_ahli_waris'], $validatedData['hubungan_ahli_waris'], $validatedData['alamat_ahli_waris'],
            $validatedData['tempat_lahir_ahli_waris'], $validatedData['tanggal_lahir_ahli_waris'],
            $validatedData['birth_place'], $validatedData['birth_date'],
            $validatedData['heir_birth_place'], $validatedData['heir_birth_date'],
            $validatedData['new_pin'], $validatedData['pin']
        );

        // 4. Update data anggota
        $member->update($validatedData);

        // 5. Pastikan mutasi saldo awal tercatat di tabel transactions jika daily_savings > 0
        if ((float)($member->daily_savings ?? 0) > 0) {
            $hasPutihTrx = Transaction::where('member_id', $member->id)
                ->where('book_type', 'BUKU_PUTIH')
                ->exists();

            if (!$hasPutihTrx) {
                $accountKas = Account::firstOrCreate(
                    ['account_number' => 'KAS-101'],
                    ['account_name' => 'Kas Koperasi', 'account_type' => 'kas', 'category' => 'asset', 'balance' => 0.00]
                );
                Transaction::create([
                    'transaction_number' => 'TRX-BP-INIT-' . $member->id . '-' . mt_rand(1000, 9999),
                    'receipt_number'     => 'KM-BP-' . $member->id,
                    'member_id'          => $member->id,
                    'account_id'         => $accountKas->id,
                    'book_type'          => 'BUKU_PUTIH',
                    'operator_id'        => $request->user()?->id,
                    'approved_by'        => $request->user()?->id,
                    'type'               => 'deposit',
                    'amount'             => (float) $member->daily_savings,
                    'beginning_balance'  => 0.00,
                    'ending_balance'     => (float) $member->daily_savings,
                    'payment_method'     => 'cash',
                    'transaction_date'   => now()->toDateString(),
                    'description'        => "Saldo Awal Simpanan Harian (Buku Putih) - {$member->name}",
                    'status'             => 'approved',
                    'approved_at'        => now(),
                ]);
            }
        }

        // Update juga ke tabel users jika terhubung
        if ($member->user) {
            $userUpdate = [];
            if (isset($validatedData['name'])) $userUpdate['name'] = $validatedData['name'];
            if (isset($validatedData['email'])) $userUpdate['email'] = $validatedData['email'];
            if (isset($validatedData['nik'])) $userUpdate['nik'] = $validatedData['nik'];
            if (isset($validatedData['pin_code'])) {
                $userUpdate['pin_code'] = $validatedData['pin_code'];
                $userUpdate['password'] = $validatedData['password'];
            }
            if (!empty($userUpdate)) {
                $member->user->update($userUpdate);
            }
        }

        return response()->json([
            'success' => true,
            'status'  => 'success',
            'message' => 'Data profil anggota berhasil diperbarui!',
            'data'    => $member->fresh()
        ], 200);
    }

    
    //hapus data anggota &user login terkait
    public function destroy($id): JsonResponse
    {
        $member = Member::find($id);

        if (!$member) {
            return response()->json([
                'success' => false,
                'message' => 'Data anggota tidak ditemukan',
                'data'    => null
            ], 404);
        }

        if ($member->transactions()->exists() || \DB::table('journal_entries')->where('description', 'like', '%Anggota ID: ' . $id . '%')->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Anggota tidak dapat dihapus karena memiliki riwayat transaksi akuntansi. Silakan ubah status menjadi Non-Aktif.',
            ], 400);
        }

        if ($member->user) {
            $member->user->delete();
        }

        $member->delete();

        return response()->json([
            'success' => true,
            'message' => 'Data anggota & User login berhasil dihapus!'
        ], 200);
    }

    //Reset password anggota dengan validasi password baru 
    public function resetPassword(Request $request, $id): JsonResponse
    {
        $request->validate([
            'password' => 'required|string|min:6|confirmed',
        ]);

        $member = Member::find($id);

        if (!$member) {
            return response()->json([
                'success' => false,
                'message' => 'Data anggota tidak ditemukan',
                'data'    => null
            ], 404);
        }

        $pinCode = (strlen($request->password) === 6 && is_numeric($request->password)) ? (string) $request->password : null;

        $member->update([
            'pin_code' => $pinCode ?? $member->pin_code,
        ]);

        if ($member->user) {
            $member->user->update([
                'password' => Hash::make($request->password),
                'pin_code' => $pinCode ?? $member->user->pin_code,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'PIN/Password anggota berhasil diperbarui!',
        ], 200);
    }

    //reset pin aggota dengan validasi pin baru 6 digit
    public function resetPin(Request $request, $id): JsonResponse
    {
        $request->validate([
            'new_pin'          => 'required_without:pin|nullable|numeric|digits:6',
            'pin'              => 'required_without:new_pin|nullable|numeric|digits:6',
            'pin_confirmation' => 'nullable',
        ]);

        $member = Member::find($id);

        if (!$member) {
            return response()->json([
                'success' => false,
                'message' => 'Data anggota tidak ditemukan',
                'data'    => null
            ], 404);
        }

        // Integritas String: 
        // PIN diambil murni sebagai string 6 digit tanpa dikonversi ke Integer
        $pinStr = (string) ($request->new_pin ?? $request->pin);

        // Update ke database (pin_code disimpan sebagai string, 
        // password dihash langsung)
        $member->update([
            'pin_code' => $pinStr,
            'password' => Hash::make($pinStr),
        ]);

        if ($member->user) {
            $member->user->update([
                'pin_code' => $pinStr,
                'password' => Hash::make($pinStr),
            ]);
        }

        return response()->json([
            'success' => true,
            'status'  => 'success',
            'message' => 'PIN 6-digit anggota (' . $pinStr . ') berhasil diperbarui & di-hash ke autentikasi!',
            'data'    => [
                'pin_code' => $pinStr,
            ],
        ], 200);
    }

    /**
     * Helper Kalkulasi Tier Biaya Potongan Keluar / Penutupan Rekening
     * - Saldo < Rp 1.000.000 -> Potongan Rp 100.000
     * - Saldo Rp 1.000.001 - Rp 10.000.000 -> Potongan Rp 150.000
     * - Saldo Rp 10.000.001 - Rp 50.000.000 -> Potongan Rp 200.000
     * - Saldo > Rp 50.000.000 -> Potongan Rp 300.000
     */
    public static function calculateExitFee(float $amount): float
    {
        return MemberResignationController::calculateExitFee($amount);
    }

    public function previewCloseWhiteBook(Request $request, $id): JsonResponse
    {
        return app(MemberResignationController::class)->previewCloseWhiteBook($request, $id);
    }

    public function closeWhiteBook(Request $request, $id): JsonResponse
    {
        return app(MemberResignationController::class)->closeWhiteBook($request, $id);
    }

    public function previewResignTotal(Request $request, $id): JsonResponse
    {
        return app(MemberResignationController::class)->previewResignTotal($request, $id);
    }

    public function resignTotal(Request $request, $id): JsonResponse
    {
        return app(MemberResignationController::class)->resignTotal($request, $id);
    }

    public function resignMember(Request $request, $id): JsonResponse
    {
        return app(MemberResignationController::class)->resignMember($request, $id);
    }

    /**
     * Download Excel / CSV Template for Initial Member Migration
     * Endpoint: GET /api/members/migration/template
     */
    public function downloadTemplate(Request $request)
    {
        return app(MemberMigrationController::class)->downloadTemplate($request);
    }

    public function downloadMigrationTemplate(Request $request)
    {
        return app(MemberMigrationController::class)->downloadTemplate($request);
    }

    /**
     * Import Initial Members and Balances from Excel/CSV (Bulk Upload)
     * Endpoint: POST /api/members/import-initial
     */
    public function importInitial(Request $request): JsonResponse
    {
        return $this->importInitialMembers($request);
    }

    /**
     * Import Master Anggota Awal beserta Saldo Awal (Bulk Create Members + Initial Balances)
     * Endpoint: POST /api/members/import-initial
     */
    public function importInitialMembers(Request $request): JsonResponse
    {
        @set_time_limit(300);
        DB::disableQueryLog();
        $rows = [];

        // 1. Jika ada file upload Excel / CSV, simpan ke storage dan dispatch ke background Queue Job
        if ($request->hasFile('file') || $request->hasFile('excel')) {
            $file = $request->file('file') ?? $request->file('excel');
            
            // Simpan ke storage/app/imports/
            $storedPath = $file->store('imports');
            $authUser = $request->user();
            \App\Jobs\ImportMembersJob::dispatch($storedPath, $authUser?->id);

            return response()->json([
                'success' => true,
                'message' => 'File berhasil diunggah dan sedang diproses di antrean latar belakang.',
                'data'    => [
                    'queued' => true,
                    'file'   => basename($storedPath),
                ]
            ], 200);
        }

        // 2. Ambil data dari JSON payload jika di-pass langsung
        if ($request->has('members')) {
            $rows = $request->input('members');
        }

        if (empty($rows)) {
            return response()->json([
                'success' => false,
                'status'  => 'error',
                'message' => 'Tidak ada data anggota yang ditemukan untuk diimpor.'
            ], 400);
        }

        $authUser = $request->user();

        // Ambil Periode Akuntansi yang OPEN
        $activePeriod = DB::table('periods')
            ->whereIn('status', ['open', 'OPEN', 'terbuka'])
            ->where('is_locked', false)
            ->latest('id')
            ->first() ?? DB::table('accounting_periods')
            ->whereIn('status', ['open', 'OPEN', 'terbuka'])
            ->where('is_locked', false)
            ->latest('id')
            ->first();

        if (!$activePeriod) {
            return response()->json([
                'success' => false,
                'status'  => 'error',
                'message' => 'Gagal impor: Periode akuntansi aktif (OPEN) tidak ditemukan.'
            ], 400);
        }

        $accountKas = Account::firstOrCreate(
            ['account_number' => 'KAS-101'],
            [
                'account_name' => 'Kas Koperasi',
                'account_type' => 'kas',
                'category'     => 'asset',
                'balance'      => 0.00,
            ]
        );

        $journalService = app(JournalService::class);
        $totalConsolidatedBalances = 0.00;
        $importedCount = 0;

        try {
            DB::beginTransaction();

            foreach ($rows as $index => $row) {
                // 1. Lewati jika index baris pertama (0) dan berupa header
                if ($index === 0) {
                    $nikVal = $row['nik'] ?? $row[1] ?? null;
                    if ($nikVal && is_numeric(trim((string)$nikVal)) && strlen(trim((string)$nikVal)) === 16) {
                        // Data valid NIK, maka ini real member data di index 0. Lanjutkan.
                    } else {
                        $firstCol = strtolower(trim((string)($row[0] ?? $row['member_number'] ?? '')));
                        $secondCol = strtolower(trim((string)($row[1] ?? $row['nik'] ?? '')));
                        if (in_array($firstCol, ['member_number', 'no_anggota', 'no']) || in_array($secondCol, ['nik', 'no_ktp'])) {
                            continue;
                        }
                    }
                }

                // 2. Fallback cek: jika isi kolom adalah teks judul
                $firstCol = strtolower(trim((string)($row[0] ?? $row['member_number'] ?? '')));
                $secondCol = strtolower(trim((string)($row[1] ?? $row['nik'] ?? '')));
                if (in_array($firstCol, ['member_number', 'no_anggota', 'no']) || in_array($secondCol, ['nik', 'no_ktp'])) {
                    continue; // Lewati baris header
                }

                // 3. Lewati jika baris benar-benar kosong
                if (empty(array_filter($row))) {
                    continue;
                }

                // 1. Pemetaan Kolom Berdasarkan Header File Excel
                $memberNumber = trim((string)($row['member_number'] ?? $row['no_anggota'] ?? $row[0] ?? ''));
                $nikRaw       = $row['nik'] ?? $row['no_ktp'] ?? $row['ktp'] ?? $row[1] ?? '';
                $nik          = trim((string)$nikRaw);
                if (is_numeric($nik) && (str_contains(strtolower($nik), 'e+') || str_contains($nik, '.'))) {
                    $nik = sprintf('%.0f', (float)$nik);
                }
                $name         = trim((string)($row['name'] ?? $row['nama'] ?? $row['nama_lengkap'] ?? $row[2] ?? ''));

                if (empty($name) && empty($nik) && empty($memberNumber)) {
                    continue; // Lewati jika baris kosong / footer
                }

                if (empty($name)) {
                    throw new \Exception("Nama wajib diisi pada baris ke-" . ($index + 1));
                }

                $rawBukuPutih = $row['buku_putih_no'] ?? $row['buku_putih'] ?? $row['no_buku_putih'] ?? $row['nomor_buku_putih'] ?? $row['rekening_buku_putih'] ?? $row[3] ?? null;
                $bukuPutihNo = null;
                if (!is_null($rawBukuPutih)) {
                    $strBP = trim((string) $rawBukuPutih);
                    if (!in_array(strtolower($strBP), ['', '-', '0', 'null', 'none', 'undefined'])) {
                        $bukuPutihNo = $strBP;
                    }
                }

                $phoneVal = $row['phone_number'] ?? $row['phone'] ?? $row['no_hp'] ?? $row['handphone'] ?? $row[4] ?? null;
                $phone    = !empty($phoneVal) ? trim((string)$phoneVal) : '-';

                $rawStatus = $row['status'] ?? $row[5] ?? 'active';
                $status    = !empty($rawStatus) ? strtolower(trim((string)$rawStatus)) : 'active';
                if (!in_array($status, ['active', 'inactive', 'suspended'])) {
                    $status = 'active';
                }

                $pinCode   = (string) ($row['pin_code'] ?? '123456');

                $mandatory = (float)($row['mandatory_savings'] ?? $row['simpanan_wajib'] ?? $row[6] ?? 0);
                $voluntary = (float)($row['voluntary_savings'] ?? $row['simpanan_sukarela'] ?? $row[7] ?? 0);
                $principal = (float)($row['principal_savings'] ?? $row['simpanan_pokok'] ?? $row[8] ?? 0);
                $rawDaily  = $row['daily_savings'] ?? $row['tabungan_harian'] ?? $row['simpanan_harian'] ?? $row[9] ?? 0;
                $dailySavings = is_numeric($rawDaily) ? (float)$rawDaily : (float)preg_replace('/[^0-9.]/', '', (string)$rawDaily);
                if ($dailySavings < 0 || empty($dailySavings)) {
                    $dailySavings = 0.00;
                }

                // 2. Aturan Khusus Nilai Pinjaman (Opsional / Default 0)
                $outstandingLoan = (float)($row['outstanding_loan'] ?? $row['pinjaman'] ?? $row['sisa_pinjaman'] ?? $row[10] ?? 0);
                if ($outstandingLoan < 0 || empty($outstandingLoan)) {
                    $outstandingLoan = 0.00;
                }
                $griefFund        = (float)($row['grief_fund'] ?? $row['social_fund'] ?? 0);
                $registrationFee  = (float)($row['registration_fee'] ?? 0);

                // 3. Penanganan Baris Tanpa Nomor Anggota / NIK (Baris Buku Putih Murni)
                $hasExplicitShares = ($principal > 0 || $mandatory > 0 || $voluntary > 0);
                $isPureBukuPutih   = ($dailySavings > 0 || !empty($bukuPutihNo)) && ((empty($memberNumber) && empty($nik)) || (!$hasExplicitShares && (empty($memberNumber) || empty($nik))));

                if ($isPureBukuPutih) {
                    $principal = 0.00;
                    $mandatory = 0.00;
                    $voluntary = 0.00;
                    $registrationFee = 0.00;
                    $griefFund = 0.00;

                    if (empty($nik)) {
                        $nik = 'TEMP' . str_pad((string)($index + 1), 12, '0', STR_PAD_LEFT);
                        if (Member::where('nik', $nik)->exists()) {
                            $nik = 'TMP' . str_pad((string)($index + 1), 4, '0', STR_PAD_LEFT) . rand(10000000, 99999999);
                            $nik = substr($nik, 0, 16);
                        }
                    }
                } else {
                    // Auto-generate member number if not provided
                    if (empty($memberNumber)) {
                        $count = Member::count() + $importedCount + 1;
                        $memberNumber = str_pad((string)$count, 4, '0', STR_PAD_LEFT);
                    }

                    // Penanganan NIK Duplikat / Kosong (Gunakan Fallback NIK Unik Maksimal 16 Karakter)
                    $existingNik = !empty($nik) && Member::where('nik', $nik)->where('member_number', '!=', $memberNumber)->exists();
                    if ($existingNik || empty($nik)) {
                        $cleanNum = preg_replace('/[^0-9A-Za-z]/', '', $memberNumber);
                        $nik = 'TEMP' . str_pad(substr($cleanNum, -12), 12, '0', STR_PAD_LEFT);
                        if (Member::where('nik', $nik)->where('member_number', '!=', $memberNumber)->exists()) {
                            $nik = 'TMP' . str_pad((string)($index + 1), 5, '0', STR_PAD_LEFT) . rand(10000000, 99999999);
                            $nik = substr($nik, 0, 16);
                        }
                    }
                }

                $emailVal = $row['email'] ?? null;
                $email    = !empty($emailVal) ? trim((string)$emailVal) : "{$nik}@pelita.local";

                $hasBukuBiru = ($principal + $mandatory + $voluntary) > 0 || $outstandingLoan > 0;
                $hasBukuPutih = $dailySavings > 0 || !empty($bukuPutihNo);
                if (!$hasBukuBiru && !$hasBukuPutih) {
                    $hasBukuBiru = true;
                }

                // Cari apakah anggota sudah terdaftar berdasarkan member_number, buku_putih_no, atau NIK
                $existingMember = null;
                if (!empty($memberNumber)) {
                    $existingMember = Member::where('member_number', $memberNumber)->first();
                }
                if (!$existingMember && !empty($bukuPutihNo)) {
                    $existingMember = Member::where('buku_putih_no', $bukuPutihNo)->first();
                }
                if (!$existingMember && !empty($nik) && !str_starts_with($nik, 'TEMP') && !str_starts_with($nik, 'TMP')) {
                    $existingMember = Member::where('nik', $nik)->first();
                }

                if ($existingMember) {
                    if (!empty($bukuPutihNo)) $existingMember->buku_putih_no = $bukuPutihNo;
                    if ($dailySavings > 0) $existingMember->daily_savings = $dailySavings;
                    $existingMember->has_buku_putih = $hasBukuPutih || $existingMember->has_buku_putih;
                    $existingMember->has_buku_biru = $hasBukuBiru || $existingMember->has_buku_biru;
                    if ($principal > 0) $existingMember->principal_savings = $principal;
                    if ($mandatory > 0) $existingMember->mandatory_savings = $mandatory;
                    if ($voluntary > 0) $existingMember->voluntary_savings = $voluntary;
                    if (!empty($name)) $existingMember->name = $name;
                    if (!empty($phone) && $phone !== '-') $existingMember->phone = $phone;
                    if (!empty($status)) $existingMember->status = $status;
                    $existingMember->save();
                    $member = $existingMember;
                } else {
                    $member = Member::create([
                        'member_number'     => !empty($memberNumber) ? $memberNumber : null,
                        'nik'               => $nik,
                        'name'              => $name,
                        'email'             => $email,
                        'phone'             => $phone,
                        'status'            => $status,
                        'principal_savings' => $principal,
                        'mandatory_savings' => $mandatory,
                        'voluntary_savings' => $voluntary,
                        'social_fund'       => $griefFund,
                        'grief_fund'        => $griefFund,
                        'registration_fee'  => $registrationFee,
                        'daily_savings'     => $dailySavings,
                        'buku_putih_no'     => $bukuPutihNo,
                        'has_buku_biru'     => $hasBukuBiru,
                        'has_buku_putih'    => $hasBukuPutih,
                        'password'          => Hash::make($pinCode),
                        'pin_code'          => $pinCode,
                        'place_of_birth'    => $row['place_of_birth'] ?? '-',
                        'date_of_birth'     => $row['date_of_birth'] ?? now()->toDateString(),
                        'gender'            => $row['gender'] ?? 'Laki-laki',
                        'occupation'        => $row['occupation'] ?? '-',
                        'education'         => $row['education'] ?? '-',
                        'family_status'     => $row['family_status'] ?? '-',
                        'church_sector'     => $row['church_sector'] ?? 'HKBP Dame Duri',
                        'address'           => $row['address'] ?? '-',
                        'heir_name'         => $row['heir_name'] ?? '-',
                        'heir_relationship' => $row['heir_relationship'] ?? '-',
                        'heir_place_of_birth' => $row['heir_place_of_birth'] ?? '-',
                        'heir_date_of_birth'  => $row['heir_date_of_birth'] ?? now()->toDateString(),
                        'heir_address'      => $row['heir_address'] ?? '-',
                    ]);
                }

                // 1. Outstanding Loan
                if ($outstandingLoan > 0) {
                    $loanCode = 'LND-INIT-' . $member->id . '-' . mt_rand(100, 999);
                    \App\Models\Loan::updateOrCreate(
                        [
                            'member_id' => $member->id,
                            'status'    => 'active',
                        ],
                        [
                            'loan_code'           => $loanCode,
                            'amount'              => $outstandingLoan,
                            'interest_rate'       => 1.5,
                            'interest_method'     => 'declining_balance',
                            'duration_months'     => 12,
                            'tenor_months'        => 12,
                            'monthly_installment' => ceil($outstandingLoan / 12),
                            'remaining_amount'    => $outstandingLoan,
                            'remaining_principal' => $outstandingLoan,
                            'application_date'    => now()->toDateString(),
                        ]
                    );

                    $trxNumberLoan = sprintf(
                        'TRX-IMP-%s-%s-%s-%s',
                        now()->format('YmdHis'),
                        $member->id ?? ($index + 1),
                        'LON',
                        \Illuminate\Support\Str::upper(\Illuminate\Support\Str::random(5))
                    );
                    $receiptNoLoan = $row['receipt_number'] ?? sprintf(
                        'KK-IMP-LON-%s-%s-%s',
                        now()->format('YmdHis'),
                        $member->id ?? ($index + 1),
                        \Illuminate\Support\Str::upper(\Illuminate\Support\Str::random(4))
                    );
                    $trxLoan = Transaction::create([
                        'transaction_number' => $trxNumberLoan,
                        'receipt_number'     => $receiptNoLoan,
                        'member_id'          => $member->id,
                        'account_id'         => $accountKas->id,
                        'book_type'          => 'BUKU_BIRU',
                        'operator_id'        => $authUser->id ?? null,
                        'approved_by'        => $authUser->id ?? null,
                        'type'               => 'withdrawal',
                        'amount'             => $outstandingLoan,
                        'beginning_balance'  => 0.00,
                        'ending_balance'     => $outstandingLoan,
                        'payment_method'     => 'cash',
                        'transaction_date'   => now()->toDateString(),
                        'description'        => "Pencairan Pinjaman Awal (Outstanding) - {$member->name}",
                        'status'             => 'approved',
                        'approved_at'        => now(),
                    ]);
                    if (\Illuminate\Support\Facades\Schema::hasColumn('transactions', 'period_id')) {
                        $trxLoan->period_id = $activePeriod->id;
                        $trxLoan->save();
                    }
                    try {
                        $journalService->generateJournal($trxLoan);
                    } catch (\Throwable $eJ) {
                        Log::warning("Jurnal Outstanding Loan gagal untuk member impor ID {$member->id}: " . $eJ->getMessage());
                    }
                }

                // 2. Buku Biru Simpanan
                $savingsTotal = $principal + $mandatory + $voluntary + $registrationFee + $griefFund;
                if ($savingsTotal > 0) {
                    $trxNumber = sprintf(
                        'TRX-IMP-%s-%s-%s-%s',
                        now()->format('YmdHis'),
                        $member->id ?? ($index + 1),
                        'BIR',
                        \Illuminate\Support\Str::upper(\Illuminate\Support\Str::random(5))
                    );
                    $receiptNoSavings = $row['receipt_number'] ?? sprintf(
                        'KM-IMP-%s-%s-%s',
                        now()->format('YmdHis'),
                        $member->id ?? ($index + 1),
                        \Illuminate\Support\Str::upper(\Illuminate\Support\Str::random(4))
                    );
                    $trx = Transaction::create([
                        'transaction_number' => $trxNumber,
                        'receipt_number'     => $receiptNoSavings,
                        'member_id'          => $member->id,
                        'account_id'         => $accountKas->id,
                        'book_type'          => 'BUKU_BIRU',
                        'operator_id'        => $authUser->id ?? null,
                        'approved_by'        => $authUser->id ?? null,
                        'type'               => 'deposit',
                        'amount'             => $savingsTotal,
                        'beginning_balance'  => 0.00,
                        'ending_balance'     => $savingsTotal,
                        'payment_method'     => 'cash',
                        'transaction_date'   => now()->toDateString(),
                        'description'        => "Saldo Awal Simpanan (Buku Biru) - {$member->name}",
                        'status'             => 'approved',
                        'approved_at'        => now(),
                    ]);
                    if (\Illuminate\Support\Facades\Schema::hasColumn('transactions', 'period_id')) {
                        $trx->period_id = $activePeriod->id;
                        $trx->save();
                    }
                    try {
                        $journalService->generateJournal($trx);
                    } catch (\Throwable $eJ) {
                        Log::warning("Jurnal Saldo Awal gagal untuk member impor ID {$member->id}: " . $eJ->getMessage());
                    }
                }

                // 3. Tabungan Harian (Buku Putih)
                if ($dailySavings > 0) {
                    $existingTrxPutih = Transaction::where('member_id', $member->id)
                        ->where('book_type', 'BUKU_PUTIH')
                        ->where(function ($q) {
                            $q->where('description', 'like', '%Saldo Awal%')
                              ->orWhere('receipt_number', 'like', 'KM-IMP-P-%');
                        })->first();

                    $historicalTxDate = \Carbon\Carbon::create(2026, 8, 20)->toDateString();

                    if ($existingTrxPutih) {
                        $existingTrxPutih->amount = $dailySavings;
                        $existingTrxPutih->ending_balance = $dailySavings;
                        $existingTrxPutih->description = "Saldo Awal Buku Putih - {$member->name}";
                        if ($existingTrxPutih->transaction_date != $historicalTxDate) {
                            $existingTrxPutih->transaction_date = $historicalTxDate;
                        }
                        $existingTrxPutih->save();
                    } else {
                        $trxNumberPutih = sprintf(
                            'TRX-IMP-%s-%s-%s-%s',
                            now()->format('YmdHis'),
                            $member->id ?? ($index + 1),
                            'PUT',
                            \Illuminate\Support\Str::upper(\Illuminate\Support\Str::random(5))
                        );
                        $baseReceiptPutih = $row['receipt_number'] ?? $row['no_bukti'] ?? null;
                        $receiptNoPutih = $row['receipt_number_putih'] ?? ($baseReceiptPutih ? $baseReceiptPutih . '-P' : null) ?? sprintf(
                            'KM-IMP-P-%s-%s-%s',
                            now()->format('YmdHis'),
                            $member->id ?? ($index + 1),
                            \Illuminate\Support\Str::upper(\Illuminate\Support\Str::random(4))
                        );
                        $trxPutih = Transaction::create([
                            'transaction_number' => $trxNumberPutih,
                            'receipt_number'     => $receiptNoPutih,
                            'member_id'          => $member->id,
                            'account_id'         => $accountKas->id,
                            'book_type'          => 'BUKU_PUTIH',
                            'operator_id'        => $authUser->id ?? null,
                            'approved_by'        => $authUser->id ?? null,
                            'type'               => 'deposit',
                            'amount'             => $dailySavings,
                            'beginning_balance'  => 0.00,
                            'ending_balance'     => $dailySavings,
                            'payment_method'     => 'cash',
                            'transaction_date'   => $historicalTxDate,
                            'description'        => "Saldo Awal Buku Putih - {$member->name}",
                            'status'             => 'approved',
                            'approved_at'        => now(),
                        ]);
                        if (\Illuminate\Support\Facades\Schema::hasColumn('transactions', 'period_id')) {
                            $trxPutih->period_id = $activePeriod->id;
                            $trxPutih->save();
                        }
                        try {
                            $journalService->generateJournal($trxPutih);
                        } catch (\Throwable $eJ) {
                            Log::warning("Jurnal Buku Putih gagal untuk member impor ID {$member->id}: " . $eJ->getMessage());
                        }
                    }
                }

                $totalConsolidatedBalances += ($principal + $mandatory + $voluntary + $registrationFee + $griefFund + $dailySavings + $outstandingLoan);
                $importedCount++;
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'status'  => 'success',
                'message' => "Berhasil mengimpor {$importedCount} data anggota baru beserta saldo awal!",
                'data'    => [
                    'imported_count' => $importedCount,
                    'total_imported' => $importedCount,
                    'total_amount'   => $totalConsolidatedBalances,
                    'period_id'      => $activePeriod->id,
                    'period_name'    => $activePeriod->period_name,
                ]
            ], 200);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error("Import Initial Members Error: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'status'  => 'error',
                'message' => "Gagal mengimpor data pada baris ke-" . ($index + 1) . ": " . $e->getMessage(),
                'failed_row_index' => $index + 1
            ], 400);
        }
    }
}