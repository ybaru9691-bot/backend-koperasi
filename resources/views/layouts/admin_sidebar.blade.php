<aside class="main-sidebar sidebar-dark-primary elevation-4">
    <!-- Brand Logo -->
    <a href="{{ url('/admin/dashboard') }}" class="brand-link">
        <span class="brand-text font-weight-light">Koperasi CUM Pelita</span>
    </a>

    <!-- Sidebar -->
    <div class="sidebar">
        <!-- Sidebar Menu -->
        <nav class="mt-2">
            <ul class="nav nav-pills nav-sidebar flex-column" data-widget="treeview" role="menu" data-accordion="false">
                <li class="nav-item">
                    <a href="{{ url('/admin/dashboard') }}" class="nav-link">
                        <i class="nav-icon fas fa-tachometer-alt"></i>
                        <p>Beranda Admin</p>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="{{ url('/admin/members') }}" class="nav-link">
                        <i class="nav-icon fas fa-users"></i>
                        <p>KEANGGOTAAN</p>
                    </a>
                </li>

                <!-- KEUANGAN & KAS -->
                <li class="nav-item has-treeview menu-open">
                    <a href="#" class="nav-link">
                        <i class="nav-icon fas fa-wallet"></i>
                        <p>
                            KEUANGAN & KAS
                            <i class="right fas fa-angle-left"></i>
                        </p>
                    </a>
                    <ul class="nav nav-treeview">
                        <li class="nav-item">
                            <a href="{{ url('/admin/transactions/daily') }}" class="nav-link">
                                <i class="far fa-circle nav-icon"></i>
                                <p>Kas Masuk & Kas Keluar</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="{{ url('/admin/journal') }}" class="nav-link">
                                <i class="far fa-circle nav-icon"></i>
                                <p>Jurnal Umum</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="{{ url('/admin/ledger') }}" class="nav-link">
                                <i class="far fa-circle nav-icon"></i>
                                <p>Buku Besar</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="{{ url('/admin/worksheet') }}" class="nav-link">
                                <i class="far fa-circle nav-icon"></i>
                                <p>Neraca Lajur (10 Kolom)</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="{{ url('/admin/tabelaris') }}" class="nav-link">
                                <i class="far fa-circle nav-icon"></i>
                                <p>Jurnal Tabelaris (29 Kolom)</p>
                            </a>
                        </li>
                    </ul>
                </li>

                <!-- OPERASIONAL PINJAMAN -->
                <li class="nav-item has-treeview">
                    <a href="#" class="nav-link">
                        <i class="nav-icon fas fa-hand-holding-usd"></i>
                        <p>
                            OPERASIONAL PINJAMAN
                            <i class="right fas fa-angle-left"></i>
                        </p>
                    </a>
                    <ul class="nav nav-treeview">
                        <li class="nav-item">
                            <a href="{{ url('/admin/loans/approval') }}" class="nav-link">
                                <i class="far fa-circle nav-icon"></i>
                                <p>Persetujuan Pinjaman</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="{{ url('/admin/loans/card') }}" class="nav-link">
                                <i class="far fa-circle nav-icon"></i>
                                <p>Kartu Pinjaman & Angsuran</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="{{ url('/admin/loans/history') }}" class="nav-link">
                                <i class="far fa-circle nav-icon"></i>
                                <p>Riwayat Transaksi Pinjaman</p>
                            </a>
                        </li>
                    </ul>
                </li>

                <li class="nav-item">
                    <a href="{{ url('/admin/profile') }}" class="nav-link">
                        <i class="nav-icon fas fa-user-cog"></i>
                        <p>Profil Admin</p>
                    </a>
                </li>
            </ul>
        </nav>
    </div>
</aside>
