<?php
/******************************************************
 * Job Application Tracker (Turso HTTP, single-file)
 * Deploy-friendly on Vercel (serverless PHP), data PERSIST via Turso.
 * Docs: /v2/pipeline + Bearer token (libSQL Remote Protocol).
 ******************************************************/

date_default_timezone_set('Asia/Jakarta');

// ==== ENV (set di Vercel: TURSO_URL & TURSO_TOKEN) ====
$TURSO_URL   = getenv('TURSO_URL') ?: '';
$TURSO_TOKEN = getenv('TURSO_TOKEN') ?: '';

// Normalizer: terima libsql:// lalu ubah ke https://.../v2/pipeline
if ($TURSO_URL) {
  if (strpos($TURSO_URL, 'libsql://') === 0) {
    // libsql://host[:port][?params] -> https://host/v2/pipeline (params diabaikan)
    $u = preg_replace('#^libsql://#','https://',$TURSO_URL);
    $u = preg_replace('#/+$#','',$u);
    if (!preg_match('#/v2/pipeline$#',$u)) $u .= '/v2/pipeline';
    $TURSO_URL = $u;
  } elseif (strpos($TURSO_URL, 'http') === 0) {
    // pastikan ada /v2/pipeline
    $TURSO_URL = rtrim($TURSO_URL, '/');
    if (!preg_match('#/v2/pipeline$#',$TURSO_URL)) $TURSO_URL .= '/v2/pipeline';
  }
}

/* ========== TURSO HTTP CLIENT (Hrana over HTTP) ========== */
function turso_args($params) {
  // Convert simple PHP scalars → hrana typed values (as strings)
  $out = [];
  foreach ($params as $p) {
    if (is_null($p))        $out[] = ['type'=>'null',   'value'=>'null'];
    elseif (is_int($p))     $out[] = ['type'=>'integer','value'=>strval($p)];
    elseif (is_float($p))   $out[] = ['type'=>'float',  'value'=>strval($p)];
    else                    $out[] = ['type'=>'text',   'value'=>strval($p)];
  }
  return $out;
}

function turso_exec($sql, $params = []) {
  global $TURSO_URL, $TURSO_TOKEN;
  $payload = [
    "requests" => [
      [
        "type" => "execute",
        "stmt" => array_merge([
          "sql"  => $sql
        ], $params ? ["args" => turso_args($params)] : [])
      ],
      ["type" => "close"]
    ]
  ];

  $ch = curl_init($TURSO_URL);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => [
      "Authorization: Bearer {$TURSO_TOKEN}",
      "Content-Type: application/json"
    ],
    CURLOPT_POSTFIELDS     => json_encode($payload)
  ]);
  $res = curl_exec($ch);
  $http = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  $err = curl_error($ch);
  curl_close($ch);

  if ($res === false || $http >= 400) {
    throw new Exception("Turso HTTP error ({$http}): ".($err ?: $res));
  }
  $json = json_decode($res, true);
  if (!$json || empty($json['results'][0]['response'])) {
    throw new Exception("Unexpected response from Turso.");
  }
  $resp = $json['results'][0]['response'];
  if (($resp['type'] ?? '') !== 'execute') {
    return ["rows"=>[], "cols"=>[], "affected"=>0, "last_id"=>null];
  }
  $result = $resp['result'] ?? [];
  $cols   = [];
  foreach (($result['cols'] ?? []) as $c) {
    // cols could be array of objects with "name"
    $cols[] = is_array($c) && isset($c['name']) ? $c['name'] : $c;
  }
  $rowsOut = [];
  foreach (($result['rows'] ?? []) as $row) {
    $assoc = [];
    foreach ($row as $i => $cell) {
      // cell usually { type, value } or { base64 }
      if (is_array($cell)) {
        if (array_key_exists('value', $cell)) {
          $v = $cell['value'];
        } elseif (array_key_exists('base64', $cell)) {
          $v = base64_decode($cell['base64']);
        } else {
          $v = null;
        }
      } else {
        $v = $cell; // fallback
      }
      $assoc[ $cols[$i] ?? $i ] = $v;
    }
    $rowsOut[] = $assoc;
  }
  return [
    "rows"     => $rowsOut,
    "cols"     => $cols,
    "affected" => $result['affected_row_count'] ?? 0,
    "last_id"  => $result['last_insert_rowid'] ?? null
  ];
}

/* ========== SCHEMA BOOTSTRAP ========== */
try {
  turso_exec("CREATE TABLE IF NOT EXISTS jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    company_name TEXT NOT NULL,
    job_title    TEXT NOT NULL,
    location     TEXT,
    job_type     TEXT,   -- kontrak, fulltime, freelance, remote, hybrid, part time
    applied_date TEXT,   -- YYYY-MM-DD
    updated_date TEXT,   -- YYYY-MM-DD
    status       TEXT,   -- dilamar, ditolak, diterima, tidak ada respon, interview, tes tulis, psikotes, mini project
    source_link  TEXT,
    created_at   TEXT,
    updated_at   TEXT
  )");
} catch (Exception $e) {
  http_response_code(500);
  echo "<h3>Init DB Error</h3><pre>".htmlspecialchars($e->getMessage())."</pre>";
  exit;
}

/* ========== HELPERS ========== */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function today(){ return date('Y-m-d'); }

/* ========== POST HANDLERS ========== */
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  try {
    if ($action === 'create') {
      turso_exec(
        "INSERT INTO jobs (company_name, job_title, location, job_type, applied_date, updated_date, status, source_link, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [
          trim($_POST['company_name'] ?? ''),
          trim($_POST['job_title'] ?? ''),
          trim($_POST['location'] ?? ''),
          trim($_POST['job_type'] ?? ''),
          trim($_POST['applied_date'] ?? ''),
          trim($_POST['updated_date'] ?? ''),
          trim($_POST['status'] ?? ''),
          trim($_POST['source_link'] ?? ''),
          date('Y-m-d H:i:s'),
          date('Y-m-d H:i:s')
        ]
      );
      $flash = ['type'=>'success','text'=>'Data lamaran berhasil ditambahkan.'];

    } elseif ($action === 'update') {
      $id = (int)($_POST['id'] ?? 0);
      turso_exec(
        "UPDATE jobs SET company_name=?, job_title=?, location=?, job_type=?, applied_date=?, updated_date=?, status=?, source_link=?, updated_at=? WHERE id=?",
        [
          trim($_POST['company_name'] ?? ''),
          trim($_POST['job_title'] ?? ''),
          trim($_POST['location'] ?? ''),
          trim($_POST['job_type'] ?? ''),
          trim($_POST['applied_date'] ?? ''),
          trim($_POST['updated_date'] ?? ''),
          trim($_POST['status'] ?? ''),
          trim($_POST['source_link'] ?? ''),
          date('Y-m-d H:i:s'),
          $id
        ]
      );
      $flash = ['type'=>'success','text'=>'Data lamaran berhasil diperbarui.'];

    } elseif ($action === 'delete') {
      $id = (int)($_POST['id'] ?? 0);
      turso_exec("DELETE FROM jobs WHERE id = ?", [$id]);
      $flash = ['type'=>'success','text'=>'Data lamaran berhasil dihapus.'];
    }
  } catch (Exception $e) {
    $flash = ['type'=>'error','text'=>'Gagal memproses data: '.$e->getMessage()];
  }

  header("Location: " . strtok($_SERVER["REQUEST_URI"], '?') . '?flash=' . urlencode(json_encode($flash)));
  exit;
}

/* ========== READ DATA ========== */
$rows = [];
try {
  $res  = turso_exec("SELECT * FROM jobs ORDER BY id DESC");
  $rows = $res['rows'];
} catch (Exception $e) {
  $rows = [];
}

/* ========== OPTIONS ========== */
$types    = ['kontrak','fulltime','freelance','remote','hybrid','part time'];
$statuses = ['dilamar','ditolak','diterima','tidak ada respon','interview','tes tulis','psikotes','mini project'];

?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Tracker Lamaran Kerja</title>

<!-- Bootstrap 5 -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<!-- Bootstrap Icons -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<!-- DataTables -->
<link rel="stylesheet" href="https://cdn.datatables.net/v/bs5/dt-2.0.7/r-3.0.2/datatables.min.css">
<!-- SweetAlert2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
:root{
  --grad-1:#5b86e5;
  --grad-2:#36d1dc;
  --card-bg: rgba(255,255,255,.86);
  --card-bd: rgba(255,255,255,.55);
}

.hero { background: linear-gradient(120deg, var(--grad-1), var(--grad-2)); color:#fff; padding:28px 0 90px; }
.card-glass{ margin-top:-60px; background:var(--card-bg); border:1px solid var(--card-bd); border-radius:20px; box-shadow:0 20px 60px rgba(0,0,0,.12); }
.btn-add{ --bs-btn-padding-y:.35rem; --bs-btn-padding-x:.65rem; --bs-btn-font-size:.9rem; }
.table thead th{ white-space:nowrap; }
.dt-container .row:nth-child(1) > div{ margin-bottom:.5rem; }
.badge-status{ font-weight:600; letter-spacing:.2px; }
.badge-status.dilamar{ background:#e7f1ff; color:#0d6efd; }
.badge-status.ditolak{ background:#fde8e8; color:#c1121f; }
.badge-status.diterima{ background:#e6f7ed; color:#0f8b3e; }
.badge-status.tidak\ ada\ respon{ background:#f3f4f6; color:#6b7280;}
.badge-status.interview{ background:#fff7e6; color:#b25e09; }
.badge-status.tes\ tulis{ background:#f3e8ff; color:#6f42c1; }
.badge-status.psikotes{ background:#e6fffb; color:#0aa; }
.badge-status.mini\ project{ background:#e8f5e9; color:#2e7d32; }
@media (max-width: 576px){ .table td{ font-size:.92rem; } }
</style>
</head>
<body>

<header class="hero">
  <div class="container">
    <div class="d-flex align-items-center justify-content-between">
      <div>
        <h1 class="h3 mb-1">Tracker Lamaran Kerja</h1>
        <div class="opacity-75">Pantau semua proses rekrutmen di satu tempat</div>
      </div>
      <button class="btn btn-light btn-add shadow-sm" data-bs-toggle="modal" data-bs-target="#modalForm">
        <i class="bi bi-plus-lg me-1"></i> Tambah
      </button>
    </div>
  </div>
</header>

<main class="container">
  <div class="card card-glass p-3 p-sm-4">
    <div class="table-responsive">
      <table id="jobsTable" class="table table-hover align-middle" style="width:100%">
        <thead class="table-light">
          <tr>
            <th>Nama Perusahaan</th>
            <th>Judul Pekerjaan</th>
            <th>Lokasi</th>
            <th>Tipe</th>
            <th>Tgl Melamar</th>
            <th>Tgl Update</th>
            <th>Status</th>
            <th>Link/Asal</th>
            <th style="width:90px">Aksi</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach($rows as $r): ?>
          <tr>
            <td><?=h($r['company_name'] ?? '')?></td>
            <td><?=h($r['job_title'] ?? '')?></td>
            <td><?=h($r['location'] ?? '')?></td>
            <td><span class="badge text-bg-secondary"><?=h($r['job_type'] ?? '')?></span></td>
            <td><?=h($r['applied_date'] ?? '')?></td>
            <td><?=h($r['updated_date'] ?? '')?></td>
            <td>
              <?php $cls = strtolower((string)($r['status'] ?? '')); ?>
              <span class="badge badge-status <?=str_replace(' ', ' ', $cls)?>"><?=h($r['status'] ?? '')?></span>
            </td>
            <td>
              <?php if(!empty($r['source_link'])): ?>
                <a href="<?=h($r['source_link'])?>" target="_blank" rel="noopener" class="link-primary text-truncate" style="max-width:240px;display:inline-block">
                  <?=h($r['source_link'])?>
                </a>
              <?php endif; ?>
            </td>
            <td>
              <div class="d-flex gap-2">
                <button class="btn btn-sm btn-outline-primary btn-edit"
                  data-id="<?= (int)($r['id'] ?? 0)?>"
                  data-company="<?=h($r['company_name'] ?? '')?>"
                  data-title="<?=h($r['job_title'] ?? '')?>"
                  data-location="<?=h($r['location'] ?? '')?>"
                  data-type="<?=h($r['job_type'] ?? '')?>"
                  data-applied="<?=h($r['applied_date'] ?? '')?>"
                  data-updated="<?=h($r['updated_date'] ?? '')?>"
                  data-status="<?=h($r['status'] ?? '')?>"
                  data-link="<?=h($r['source_link'] ?? '')?>"
                  data-bs-toggle="modal" data-bs-target="#modalForm"
                  title="Edit">
                  <i class="bi bi-pencil-square"></i>
                </button>
                <form method="post" class="m-0 p-0 d-inline-block form-delete">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)($r['id'] ?? 0)?>">
                  <button type="button" class="btn btn-sm btn-outline-danger btn-delete" title="Hapus">
                    <i class="bi bi-trash"></i>
                  </button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</main>

<!-- Modal Add/Edit -->
<div class="modal fade" id="modalForm" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-scrollable">
    <form class="modal-content" method="post" id="jobForm">
      <div class="modal-header">
        <h5 class="modal-title" id="modalTitle">Tambah Lamaran</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="action" id="formAction" value="create">
        <input type="hidden" name="id" id="formId" value="">
        <div class="row g-3">
          <div class="col-12">
            <label class="form-label">Nama Perusahaan <span class="text-danger">*</span></label>
            <input type="text" name="company_name" id="company_name" class="form-control" required>
          </div>
          <div class="col-12">
            <label class="form-label">Judul Pekerjaan <span class="text-danger">*</span></label>
            <input type="text" name="job_title" id="job_title" class="form-control" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Lokasi</label>
            <input type="text" name="location" id="location" class="form-control">
          </div>
          <div class="col-md-6">
            <label class="form-label">Tipe</label>
            <select name="job_type" id="job_type" class="form-select">
              <option value="">— Pilih —</option>
              <?php foreach($types as $t): ?><option value="<?=h($t)?>"><?=h(ucfirst($t))?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label">Tanggal Melamar</label>
            <input type="date" name="applied_date" id="applied_date" class="form-control" value="<?=h(today())?>">
          </div>
          <div class="col-md-6">
            <label class="form-label">Tanggal Update</label>
            <input type="date" name="updated_date" id="updated_date" class="form-control" value="<?=h(today())?>">
          </div>
          <div class="col-md-12">
            <label class="form-label">Status Lamaran</label>
            <select name="status" id="status" class="form-select">
              <option value="">— Pilih —</option>
              <?php foreach($statuses as $s): ?><option value="<?=h($s)?>"><?=h(ucwords($s))?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label">Link/Asal Lamaran</label>
            <input type="url" name="source_link" id="source_link" class="form-control" placeholder="https://...">
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Batal</button>
        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i> Simpan</button>
      </div>
    </form>
  </div>
</div>

<!-- JS -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/v/bs5/dt-2.0.7/r-3.0.2/datatables.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
(function(){
  // Flash
  const urlParams = new URLSearchParams(window.location.search);
  if (urlParams.has('flash')) {
    try {
      const data = JSON.parse(decodeURIComponent(urlParams.get('flash')));
      if (data && data.text) {
        Swal.fire({ icon: data.type==='success'?'success':'error', title: data.type==='success'?'Berhasil':'Gagal', text: data.text, timer:1800, showConfirmButton:false });
      }
      const clean = location.protocol + '//' + location.host + location.pathname;
      history.replaceState({}, document.title, clean);
    } catch(e){}
  }

  // DataTable
  const dt = new DataTable('#jobsTable', {
    responsive: true,
    pageLength: 10,
    order: [[0, 'asc']],
    language: {
      search: "Cari:",
      lengthMenu: "Tampil MENU",
      info: "Menampilkan START–END dari TOTAL data",
      infoEmpty: "Tidak ada data",
      zeroRecords: "Tidak ditemukan",
      paginate: { first:"Awal", previous:"Sebelumnya", next:"Berikutnya", last:"Akhir" }
    }
  });

  // Delete confirm
  $(document).on('click','.btn-delete', function(){
    const $form = $(this).closest('form');
    Swal.fire({ icon:'warning', title:'Hapus data?', text:'Tindakan ini tidak bisa dibatalkan.', showCancelButton:true, confirmButtonText:'Ya, hapus', cancelButtonText:'Batal' })
    .then((res)=>{ if(res.isConfirmed){ $form.trigger('submit'); }});
  });

  // Modal add/edit
  const modalEl = document.getElementById('modalForm');
  modalEl.addEventListener('show.bs.modal', (ev) => {
    const btn = ev.relatedTarget;
    if (btn && btn.classList.contains('btn-edit')) {
      $('#modalTitle').text('Edit Lamaran');
      $('#formAction').val('update');
      $('#formId').val(btn.dataset.id);
      $('#company_name').val(btn.dataset.company);
      $('#job_title').val(btn.dataset.title);
      $('#location').val(btn.dataset.location);
      $('#job_type').val(btn.dataset.type);
      $('#applied_date').val(btn.dataset.applied || '');
      $('#updated_date').val(btn.dataset.updated || '');
      $('#status').val(btn.dataset.status);
      $('#source_link').val(btn.dataset.link);
    } else {
      $('#modalTitle').text('Tambah Lamaran');
      $('#formAction').val('create');
      $('#formId').val('');
      $('#jobForm')[0].reset();
      const today = new Date().toISOString().slice(0,10);
      $('#applied_date').val(today);
      $('#updated_date').val(today);
    }
  });

  // Basic validation
  $('#jobForm').on('submit', function(e){
    const company = $('#company_name').val().trim();
    const title = $('#job_title').val().trim();
    if (!company || !title) {
      e.preventDefault();
      Swal.fire({icon:'error', title:'Data belum lengkap', text:'Isi minimal Nama Perusahaan & Judul Pekerjaan.'});
    }
  });
})();
</script>
</body>
</html>