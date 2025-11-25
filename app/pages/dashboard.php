<section class="page-head">
  <div>
    <h1>Dashboard</h1>
    <p>Ringkasan performa tim dan sistem dalam satu layar.</p>
  </div>
  <button class="btn">+ Buat Aksi Cepat</button>
</section>

<?php if (!empty($notFound)): ?>
  <div class="not-found">Halaman tidak ditemukan, kembali ke Dashboard.</div>
<?php endif; ?>

<div class="cards">
  <div class="card floating">
    <h3>Aktif</h3>
    <div class="stat-value">1.280</div>
    <div class="trend up">▲ +12% mingguan</div>
  </div>
  <div class="card floating">
    <h3>Transaksi</h3>
    <div class="stat-value">834</div>
    <div class="trend up">▲ +6% mingguan</div>
  </div>
  <div class="card floating">
    <h3>Tiket Terbuka</h3>
    <div class="stat-value">37</div>
    <div class="trend down">▼ -3 hari SLA</div>
  </div>
  <div class="card floating">
    <h3>Server</h3>
    <div class="stat-value">99.97%</div>
    <div class="trend up">▲ uptime 30d</div>
  </div>
</div>

<div class="panel-grid">
  <div class="panel">
    <h4>Roadmap Minggu Ini</h4>
    <ul class="list">
      <li class="item">
        <div>
          <div><strong>Revamp UI billing</strong></div>
          <div class="label">Deadline: Kamis</div>
        </div>
        <span class="badge light">Proses</span>
      </li>
      <li class="item">
        <div>
          <div><strong>Audit keamanan</strong></div>
          <div class="label">Owner: Sekar</div>
        </div>
        <span class="badge accent">Prioritas</span>
      </li>
      <li class="item">
        <div>
          <div><strong>Integrasi webhook</strong></div>
          <div class="label">Owner: Ardi</div>
        </div>
        <span class="badge light">Dijadwalkan</span>
      </li>
    </ul>
  </div>
  <div class="panel">
    <h4>Performa Sistem</h4>
    <div class="note">Grafik bisa diganti nanti. Sekarang placeholder progress.</div>
    <div style="margin-bottom:12px;">
      <div class="label">API Latency</div>
      <div class="progress"><div class="bar" style="width:42%;"></div></div>
    </div>
    <div style="margin-bottom:12px;">
      <div class="label">Error Rate</div>
      <div class="progress"><div class="bar" style="width:18%; background: linear-gradient(135deg, var(--danger), #fb7185);"></div></div>
    </div>
    <div>
      <div class="label">Storage Usage</div>
      <div class="progress"><div class="bar" style="width:58%;"></div></div>
    </div>
  </div>
</div>
