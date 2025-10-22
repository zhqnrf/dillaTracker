<?php
/******************************************************
 * Job Application Tracker (Turso HTTP, single-file)
 * Super-compact UI: tiny charts in one horizontal row
 * 
 * ENV required (Vercel):
 *  - TURSO_URL = https://<db>-<org>.turso.io/v2/pipeline
 *  - TURSO_TOKEN = <token>
 ******************************************************/

date_default_timezone_set('Asia/Jakarta');

// ==== ENV ====
$TURSO_URL   = getenv('TURSO_URL') ?: '';
$TURSO_TOKEN = getenv('TURSO_TOKEN') ?: '';

// Normalizer: jika user isi libsql:// → ubah ke https://.../v2/pipeline
if ($TURSO_URL) {
  if (strpos($TURSO_URL, 'libsql://') === 0) {
    $u = preg_replace('#^libsql://#','https://',$TURSO_URL);
    $u = rtrim($u, '/');
    if (!preg_match('#/v2/pipeline$#',$u)) $u .= '/v2/pipeline';
    $TURSO_URL = $u;
  } elseif (strpos($TURSO_URL, 'http') === 0) {
    $TURSO_URL = rtrim($TURSO_URL, '/');
    if (!preg_match('#/v2/pipeline$#',$TURSO_URL)) $TURSO_URL .= '/v2/pipeline';
  }
}
if (!$TURSO_URL || !$TURSO_TOKEN) {
  http_response_code(500);
  echo "<h3>Missing ENV</h3><p>Set <b>TURSO_URL</b> (HTTP + <code>/v2/pipeline</code>) & <b>TURSO_TOKEN</b> di Vercel.</p>";
  exit;
}

/* ========= Turso HTTP client (Hrana over HTTP) ========= */
function turso_args($params) {
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
    $cols[] = is_array($c) && isset($c['name']) ? $c['name'] : $c;
  }
  $rowsOut = [];
  foreach (($result['rows'] ?? []) as $row) {
    $assoc = [];
    foreach ($row as $i => $cell) {
      if (is_array($cell)) {
        if (array_key_exists('value', $cell))      $v = $cell['value'];
        elseif (array_key_exists('base64', $cell)) $v = base64_decode($cell['base64']);
        else                                        $v = null;
      } else {
        $v = $cell;
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

/* ========= Schema bootstrap & migrations ========= */
try {
  turso_exec("CREATE TABLE IF NOT EXISTS jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    company_name TEXT NOT NULL,
    job_title    TEXT NOT NULL,
    location     TEXT,
    job_type     TEXT,
    applied_date TEXT,
    updated_date TEXT,
    status       TEXT,
    salary       TEXT,
    source_link  TEXT,
    source_text  TEXT,
    created_at   TEXT,
    updated_at   TEXT
  )");
  $colsRes = turso_exec("PRAGMA table_info(jobs)");
  $have = []; foreach ($colsRes['rows'] as $c) { $have[] = $c['name']; }
  foreach (['salary'=>'ALTER TABLE jobs ADD COLUMN salary TEXT',
            'source_text'=>'ALTER TABLE jobs ADD COLUMN source_text TEXT'] as $k=>$q) {
    if (!in_array($k,$have)) { try { turso_exec($q);} catch(Exception $e){} }
  }
} catch (Exception $e) {
  http_response_code(500);
  echo "<h3>Init DB Error</h3><pre>".htmlspecialchars($e->getMessage())."</pre>";
  exit;
}

/* ========= Helpers ========= */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function today(){ return date('Y-m-d'); }
// Parse gaji → angka IDR (best-effort)
function parse_salary($txt) {
  if (!$txt) return null;
  $s = mb_strtolower(trim($txt),'UTF-8');
  $s = str_replace(['rp',' ', "\xc2\xa0"], '', $s);
  $parts = preg_split('#\s*(?:-|–|sampai|to)\s*#u', $s);
  $nums = [];
  foreach ($parts as $p) {
    $p = trim($p); if ($p==='') continue;
    $mult = 1;
    if (preg_match('#(jt|juta)#u',$p)) $mult = 1000000;
    elseif (preg_match('#(rb|ribu|\bk\b)#u',$p)) $mult = 1000;
    if (preg_match('#(\d{1,3}(?:[.\s]\d{3})+|\d+(?:[.,]\d+)?)#u',$p,$m)) {
      $norm = str_replace('.', '', $m[1]);
      $norm = str_replace(',', '.', $norm);
      if (is_numeric($norm)) {
        $val = floatval($norm);
        if ($mult>1 && $val<10000) $val *= $mult;
        $nums[] = $val;
      }
    }
  }
  if (!$nums) return null;
  return array_sum($nums)/count($nums);
}
function format_idr_short($n){
  if ($n === null) return '—';
  if ($n >= 1e9) return number_format($n/1e9, 2).' M';
  if ($n >= 1e6) return number_format($n/1e6, 2).' Jt';
  if ($n >= 1e3) return number_format($n/1e3, 2).' Rb';
  return number_format($n,0,',','.');
}

/* ========= POST handlers ========= */
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  try {
    // 1 kolom input link/text + checkbox as_url → map ke source_text/source_link
    $mix = trim($_POST['source_mix'] ?? ''); $as_url = !empty($_POST['as_url']);
    $source_text = $mix ?: ''; $source_link = $as_url ? $mix : '';

    if ($action === 'create') {
      turso_exec(
        "INSERT INTO jobs (company_name, job_title, location, job_type, applied_date, updated_date, status, salary, source_link, source_text, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [
          trim($_POST['company_name'] ?? ''),
          trim($_POST['job_title'] ?? ''),
          trim($_POST['location'] ?? ''),
          trim($_POST['job_type'] ?? ''),
          trim($_POST['applied_date'] ?? ''),
          trim($_POST['updated_date'] ?? ''),
          trim($_POST['status'] ?? ''),
          trim($_POST['salary'] ?? ''),
          $source_link,
          $source_text,
          date('Y-m-d H:i:s'),
          date('Y-m-d H:i:s')
        ]
      );
      $flash = ['type'=>'success','text'=>'Data lamaran ditambahkan.'];

    } elseif ($action === 'update') {
      $id = (int)($_POST['id'] ?? 0);
      turso_exec(
        "UPDATE jobs SET company_name=?, job_title=?, location=?, job_type=?, applied_date=?, updated_date=?, status=?, salary=?, source_link=?, source_text=?, updated_at=? WHERE id=?",
        [
          trim($_POST['company_name'] ?? ''),
          trim($_POST['job_title'] ?? ''),
          trim($_POST['location'] ?? ''),
          trim($_POST['job_type'] ?? ''),
          trim($_POST['applied_date'] ?? ''),
          trim($_POST['updated_date'] ?? ''),
          trim($_POST['status'] ?? ''),
          trim($_POST['salary'] ?? ''),
          $source_link,
          $source_text,
          date('Y-m-d H:i:s'),
          $id
        ]
      );
      $flash = ['type'=>'success','text'=>'Data lamaran diperbarui.'];

    } elseif ($action === 'delete') {
      $id = (int)($_POST['id'] ?? 0);
      turso_exec("DELETE FROM jobs WHERE id = ?", [$id]);
      $flash = ['type'=>'success','text'=>'Data lamaran dihapus.'];
    }
  } catch (Exception $e) {
    $flash = ['type'=>'error','text'=>'Gagal: '.$e->getMessage()];
  }
  header("Location: " . strtok($_SERVER["REQUEST_URI"], '?') . '?flash=' . urlencode(json_encode($flash)));
  exit;
}

/* ========= Read data & aggregations ========= */
$rows = []; try { $rows = turso_exec("SELECT * FROM jobs ORDER BY id DESC")['rows']; } catch (Exception $e) { $rows=[]; }

$statuses_list = ['dilamar','ditolak','diterima','tidak ada respon','interview','tes tulis','psikotes','mini project'];

function kv($rows, $k){ $o=[]; foreach($rows as $r){ $o[$r[$k] ?? '']=(int)($r['cnt'] ?? 0);} return $o; }
$stats = [
  'total'     => turso_exec("SELECT COUNT(*) AS n FROM jobs")['rows'][0]['n'] ?? 0,
  'diterima'  => turso_exec("SELECT COUNT(*) AS n FROM jobs WHERE status='diterima'")['rows'][0]['n'] ?? 0,
  'ditolak'   => turso_exec("SELECT COUNT(*) AS n FROM jobs WHERE status='ditolak'")['rows'][0]['n'] ?? 0,
  'interview' => turso_exec("SELECT COUNT(*) AS n FROM jobs WHERE status='interview'")['rows'][0]['n'] ?? 0,
];
$groupStatusRaw = kv(turso_exec("SELECT COALESCE(status,'') AS grp, COUNT(*) AS cnt FROM jobs GROUP BY grp")['rows'], 'grp');
$groupStatus = []; foreach($statuses_list as $s){ $groupStatus[$s]=(int)($groupStatusRaw[$s] ?? 0); }
$groupType = kv(turso_exec("SELECT COALESCE(job_type,'') AS grp, COUNT(*) AS cnt FROM jobs GROUP BY grp")['rows'], 'grp');
$trendRows = turso_exec("SELECT substr(applied_date,1,7) AS ym, COUNT(*) AS cnt FROM jobs WHERE applied_date IS NOT NULL AND applied_date!='' GROUP BY ym ORDER BY ym")['rows'];
$trendYM=[]; $trendV=[]; foreach($trendRows as $t){ if(!empty($t['ym'])){ $trendYM[]=$t['ym']; $trendV[]=(int)$t['cnt']; } }

// Gaji rata-rata (overall & per status)
$all_salaries = []; $salary_by_status = [];
foreach ($rows as $r){
  $val = parse_salary($r['salary'] ?? '');
  if ($val !== null) {
    $all_salaries[] = $val;
    $st = strtolower($r['status'] ?? '');
    $salary_by_status[$st][] = $val;
  }
}
$avg_salary_overall = $all_salaries ? array_sum($all_salaries)/count($all_salaries) : null;
$avg_salary_status = []; foreach ($statuses_list as $st){ $avg_salary_status[$st] = !empty($salary_by_status[$st]) ? array_sum($salary_by_status[$st])/count($salary_by_status[$st]) : null; }

$types = ['kontrak','fulltime','freelance','remote','hybrid','part time'];
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Track Dilla Job</title>

<!-- Logo / Favicon -->
<link rel="icon" type="image/svg+xml" href="https://cdn.jsdelivr.net/npm/tabler-icons@latest/icons/briefcase.svg">
<link rel="alternate icon" type="image/png" href="https://cdn.jsdelivr.net/gh/twitter/twemoji@latest/assets/72x72/1f4bc.png" sizes="72x72">
<link rel="icon" type="image/svg+xml" href="https://cdn.jsdelivr.net/npm/tabler-icons@latest/icons/briefcase.svg">
<link rel="alternate icon" type="image/png" href="https://cdn.jsdelivr.net/gh/twitter/twemoji@latest/assets/72x72/1f4bc.png" sizes="72x72">

<!-- Bootstrap 5 -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<!-- Bootstrap Icons -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<!-- DataTables -->
<link rel="stylesheet" href="https://cdn.datatables.net/v/bs5/dt-2.0.7/r-3.0.2/datatables.min.css">
<!-- SweetAlert2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<style>
:root{
  --grad-1:#5b86e5; --grad-2:#36d1dc;
  --card-bg: rgba(255,255,255,.9); --card-bd: rgba(255,255,255,.6);
}
.hero { background: linear-gradient(120deg, var(--grad-1), var(--grad-2)); color:#fff; padding:18px 0 48px; }
.card-glass{ margin-top:-40px; background:var(--card-bg); border:1px solid var(--card-bd); border-radius:16px; box-shadow:0 14px 40px rgba(0,0,0,.10); }
.btn-add{ --bs-btn-padding-y:.3rem; --bs-btn-padding-x:.6rem; --bs-btn-font-size:.9rem; }
.table thead th{ white-space:nowrap; }
.badge-status{ font-weight:600; letter-spacing:.2px; }
.badge-status.dilamar{ background:#e7f1ff; color:#0d6efd; }
.badge-status.ditolak{ background:#fde8e8; color:#c1121f; }
.badge-status.diterima{ background:#e6f7ed; color:#0f8b3e; }
.badge-status.tidak\ ada\ respon{ background:#f3f4f6; color:#6b7280;}
.badge-status.interview{ background:#fff7e6; color:#b25e09; }
.badge-status.tes\ tulis{ background:#f3e8ff; color:#6f42c1; }
.badge-status.psikotes{ background:#e6fffb; color:#0aa; }
.badge-status.mini\ project{ background:#e8f5e9; color:#2e7d32; }
.badge-salary{ background:#eef9ff; color:#0b6aa6; font-weight:600; }

.kpi .box{ background:#fff; border:1px solid rgba(0,0,0,.06); border-radius:12px; padding:10px 12px; }
.kpi .title{ font-size:.82rem; color:#6b7280; }
.kpi .value{ font-size:1.25rem; font-weight:800; line-height:1.1; }

.mini-charts{ display:flex; gap:8px; overflow-x:auto; padding:6px 2px 2px; -webkit-overflow-scrolling:touch; scrollbar-width:thin; }
.mini-chart-card{ flex:0 0 auto; width:220px; max-width:70vw; background:#fff; border:1px solid rgba(0,0,0,.06); border-radius:12px; padding:8px; }
.mini-chart-title{ font-size:.85rem; font-weight:600; color:#111; margin-bottom:4px; display:flex; justify-content:space-between; }
.mini-chart-note{ font-size:.72rem; color:#6b7280; }
.mini-canvas-wrap{ height:90px; } /* tinggi chart kecil */

@media (max-width:576px){
  .kpi .value{ font-size:1.1rem; }
  .mini-chart-card{ width:190px; }
  .mini-canvas-wrap{ height:90px; }
}
</style>
</head>
<body>

<header class="hero">
  <div class="container">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
      <div>
        <h1 class="h5 mb-0">Track Lamaran Sayangku</h1>
        <div class="opacity-75 small">Bismillah dan Barokah</div>
      </div>
      <div class="d-flex align-items-center gap-2">
        <!-- Filters -->
        <select id="filterStatus" class="form-select form-select-sm" style="width:auto">
          <option value="">Status: Semua</option>
          <?php foreach($statuses_list as $s): ?>
          <option value="<?=h($s)?>"><?=h(ucwords($s))?></option>
          <?php endforeach; ?>
        </select>
        <select id="filterType" class="form-select form-select-sm" style="width:auto">
          <option value="">Tipe: Semua</option>
          <?php foreach($types as $t): ?>
          <option value="<?=h($t)?>"><?=h(ucfirst($t))?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-light btn-add shadow-sm" data-bs-toggle="modal" data-bs-target="#modalForm">
          <i class="bi bi-plus-lg me-1"></i> Tambah
        </button>
      </div>
    </div>
  </div>
</header>

<main class="container">
  <div class="card card-glass p-3 p-sm-3">

    <!-- KPI compact -->
    <div class="kpi row g-2 mb-2">
      <div class="col-6 col-md-3"><div class="box"><div class="title">Dikirim (Total)</div><div class="value"><?= (int)$stats['total'] ?></div></div></div>
      <div class="col-6 col-md-3"><div class="box"><div class="title">Diterima</div><div class="value text-success"><?= (int)$stats['diterima'] ?></div></div></div>
      <div class="col-6 col-md-3"><div class="box"><div class="title">Interview</div><div class="value text-warning"><?= (int)$stats['interview'] ?></div></div></div>
      <div class="col-6 col-md-3"><div class="box"><div class="title">Ditolak</div><div class="value text-danger"><?= (int)$stats['ditolak'] ?></div></div></div>
      <div class="col-12 col-md-3"><div class="box"><div class="title">Avg Gaji (parsable)</div><div class="value">Rp <?= format_idr_short($avg_salary_overall) ?></div></div></div>
    </div>

    <!-- MINI CHARTS: one horizontal line, tiny -->
    <div class="mini-charts mb-3">
      <div class="mini-chart-card">
        <div class="mini-chart-title">Status <span class="mini-chart-note">Bar</span></div>
        <div class="mini-canvas-wrap"><canvas id="chartStatus"></canvas></div>
      </div>
      <div class="mini-chart-card">
        <div class="mini-chart-title">Tipe Pekerjaan <span class="mini-chart-note">Doughnut</span></div>
        <div class="mini-canvas-wrap"><canvas id="chartType"></canvas></div>
      </div>
      <div class="mini-chart-card">
        <div class="mini-chart-title">Tren / Bulan <span class="mini-chart-note">Line</span></div>
        <div class="mini-canvas-wrap"><canvas id="chartTrend"></canvas></div>
      </div>
      <div class="mini-chart-card">
        <div class="mini-chart-title">Avg Gaji / Status <span class="mini-chart-note">Bar</span></div>
        <div class="mini-canvas-wrap"><canvas id="chartSalaryStatus"></canvas></div>
      </div>
    </div>

    <!-- Table -->
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
            <th>Gaji</th>
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
            <td><span class="badge badge-salary"><?=h($r['salary'] ?? '')?></span></td>
            <td>
              <?php
                $st = $r['source_text'] ?? '';
                $sl = $r['source_link'] ?? '';
                if($sl){
                  $label = $st ?: $sl;
                  echo '<a href="'.h($sl).'" target="_blank" rel="noopener" class="link-primary text-truncate" style="max-width:240px;display:inline-block">'.h($label).'</a>';
                } elseif ($st) {
                  echo '<span class="text-body">'.h($st).'</span>';
                }
              ?>
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
                  data-salary="<?=h($r['salary'] ?? '')?>"
                  data-link="<?=h($r['source_link'] ?? '')?>"
                  data-linktext="<?=h($r['source_text'] ?? '')?>"
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
          <div class="col-md-6">
            <label class="form-label">Status Lamaran</label>
            <select name="status" id="status" class="form-select">
              <option value="">— Pilih —</option>
              <?php foreach($statuses_list as $s): ?><option value="<?=h($s)?>"><?=h(ucwords($s))?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label">Gaji</label>
            <input type="text" name="salary" id="salary" class="form-control" placeholder="cth: 6.000.000 / 6–8 jt / negotiable">
          </div>
          <div class="col-12">
            <label class="form-label">Link/Asal (isi teks atau URL)</label>
            <div class="input-group">
              <input type="text" id="source_mix" name="source_mix" class="form-control" placeholder="cth: LinkedIn / https://...">
              <span class="input-group-text">
                <input class="form-check-input mt-0" type="checkbox" id="as_url" name="as_url" value="1" aria-label="Tautkan sebagai URL">
              </span>
              <span class="input-group-text small">Tautkan</span>
            </div>
            <div class="form-text">Centang jika ingin dijadikan tautan (URL). Jika tidak, disimpan sebagai teks.</div>
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

<!-- JS: jQuery + DataTables + Bootstrap -->
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
        Swal.fire({ icon: data.type==='success'?'success':'error', title: data.type==='success'?'Berhasil':'Gagal', text: data.text, timer:1500, showConfirmButton:false });
      }
      history.replaceState({}, document.title, location.pathname);
    } catch(e){}
  }

  // DataTable
  const dt = new DataTable('#jobsTable', {
    responsive: true,
    pageLength: 10,
    order: [[0, 'asc']],
    language: {
      search: "Cari:",
      lengthMenu: "Tampil _MENU_",
      info: "Menampilkan _START_–_END_ dari _TOTAL_ data",
      infoEmpty: "Tidak ada data",
      zeroRecords: "Tidak ditemukan",
      paginate: { first:"Awal", previous:"Sebelumnya", next:"Berikutnya", last:"Akhir" }
    }
  });

  // Filters: Status (col 6), Type (col 3)
  const statusColIdx = 6, typeColIdx = 3;
  $('#filterStatus').on('change', function(){
    const v = this.value;
    dt.column(statusColIdx).search(v ? '^'+v+'$' : '', true, false).draw();
  });
  $('#filterType').on('change', function(){
    const v = this.value;
    dt.column(typeColIdx).search(v || '', true, false).draw();
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
      $('#salary').val(btn.dataset.salary || '');
      if (btn.dataset.link) { $('#source_mix').val(btn.dataset.link); $('#as_url').prop('checked', true); }
      else { $('#source_mix').val(btn.dataset.linktext || ''); $('#as_url').prop('checked', false); }
    } else {
      $('#modalTitle').text('Tambah Lamaran');
      $('#formAction').val('create');
      $('#formId').val('');
      $('#jobForm')[0].reset();
      const today = new Date().toISOString().slice(0,10);
      $('#applied_date').val(today); $('#updated_date').val(today); $('#as_url').prop('checked', false);
    }
  });

  // Before submit: map source_mix → hidden text/link
  $('#jobForm').on('submit', function(e){
    const company = $('#company_name').val().trim();
    const title = $('#job_title').val().trim();
    if (!company || !title) { e.preventDefault(); return Swal.fire({icon:'error', title:'Data belum lengkap', text:'Isi minimal Nama Perusahaan & Judul Pekerjaan.'}); }
    const mix = $('#source_mix').val().trim();
    const asUrl = $('#as_url').is(':checked');
    if (asUrl && mix && !/^https?:\/\//i.test(mix)) { e.preventDefault(); return Swal.fire({icon:'error', title:'URL tidak valid', text:'Jika “Tautkan” dicentang, isi harus diawali http(s)://'}); }
    $('<input type="hidden" name="source_text">').val(mix || '').appendTo('#jobForm');
    $('<input type="hidden" name="source_link">').val(asUrl ? mix : '').appendTo('#jobForm');
  });

  // ===== Charts (tiny) =====
  const statusesList   = <?= json_encode($statuses_list, JSON_UNESCAPED_UNICODE) ?>;
  const groupStatus    = <?= json_encode($groupStatus, JSON_UNESCAPED_UNICODE) ?>;
  const groupType      = <?= json_encode($groupType, JSON_UNESCAPED_UNICODE) ?>;
  const trendLabels    = <?= json_encode($trendYM, JSON_UNESCAPED_UNICODE) ?>;
  const trendData      = <?= json_encode($trendV, JSON_UNESCAPED_UNICODE) ?>;
  const avgSalaryBySt  = <?= json_encode($avg_salary_status, JSON_UNESCAPED_UNICODE) ?>;

  // helper default tiny options
  const tinyOpts = (withLegend=false)=>({
    responsive:true, maintainAspectRatio:false,
    plugins:{ legend:{ display:withLegend }, tooltip:{ enabled:true } },
    scales:{ y:{ beginAtZero:true, ticks:{ precision:0, maxTicksLimit:3 } }, x:{ ticks:{ maxRotation:0, autoSkip:true } } }
  });

  new Chart(document.getElementById('chartStatus'), {
    type: 'bar',
    data: { labels: statusesList, datasets: [{ label:'Jumlah', data: statusesList.map(s => groupStatus[s]||0) }] },
    options: tinyOpts(false)
  });

  new Chart(document.getElementById('chartType'), {
    type: 'doughnut',
    data: { labels: Object.keys(groupType), datasets: [{ data: Object.values(groupType) }] },
    options: { responsive:true, maintainAspectRatio:false, plugins:{ legend:{ position:'bottom', labels:{ boxWidth:8, font:{ size:10 } } } } }
  });

  new Chart(document.getElementById('chartTrend'), {
    type: 'line',
    data: { labels: trendLabels, datasets: [{ label:'Lamaran', data: trendData, tension:.25, pointRadius:1 }] },
    options: tinyOpts(false)
  });

  new Chart(document.getElementById('chartSalaryStatus'), {
    type: 'bar',
    data: { labels: statusesList, datasets: [{ label:'Avg Gaji (IDR)', data: statusesList.map(s=>avgSalaryBySt[s]??null) }] },
    options: tinyOpts(false)
  });
})();
</script>
</body>
</html>
