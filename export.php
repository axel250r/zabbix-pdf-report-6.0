<?php
declare(strict_types=1);

// ORDEN CORRECTO: Todas las declaraciones y c�digo van DESPU�S de strict_types.
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline';");

session_start();
if (empty($_SESSION['zbx_auth_ok'])) { header('Location: login.php'); exit; }

// Generar token CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

require_once __DIR__ . '/lib/i18n.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/ZabbixApi.php';
header('Content-Type: text/html; charset=utf-8');

if (empty($_SESSION['zbx_user']) || empty($_SESSION['zbx_pass'])) {
    header('Location: login.php');
    exit;
}

function getZabbixTemplates() {
    try {
        $api = new ZabbixApi(ZABBIX_API_URL, $_SESSION['zbx_user'], $_SESSION['zbx_pass']);
        $response = $api->call('template.get', ['output' => ['templateid', 'name'], 'sortfield' => 'name']);
        return is_array($response) ? $response : [];
    } catch (Throwable $e) {
        return ['error' => 'Error: ' . $e->getMessage()];
    }
}

$zabbixTemplates = getZabbixTemplates();
?>
<!doctype html>
<html lang="<?= htmlspecialchars($current_lang, ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<meta name="author" content="Axel Del Canto">
<title><?= t('export_title') ?></title>
<style>
:root {
  --bg-light: #fff;
  --card-light: #f8f9fa;
  --text-dark: #333;
  --text-muted-light: #6c757d;
  --zbx-red: #e04646;
  --card-border-light: #e0e0e0;
  --input-light: #fff;

  --bg-dark: #1a1a1a;
  --card-dark: #2b2b2b;
  --text-light: #fff;
  --text-muted-dark: #ccc;
  --card-border-dark: #444;
  --input-dark: #3a3a3a;
}

body.light-theme {
  background: var(--bg-light);
  color: var(--text-dark);
}

body.dark-theme {
  background: var(--bg-dark);
  color: var(--text-light);
}

.card {
  border-radius: 14px;
  width: 100%;
  padding: 30px;
}
body.light-theme .card {
  background: var(--card-light);
  border: 1px solid var(--card-border-light);
  box-shadow: 0 8px 30px rgba(0,0,0,.15);
}
body.dark-theme .card {
  background: var(--card-dark);
  border: 1px solid var(--card-border-dark);
  box-shadow: 0 8px 30px rgba(0,0,0,.4);
}

* { box-sizing: border-box; }
body { margin: 0; font: 14px/1.45 system-ui,Segoe UI,Arial; transition: background .3s, color .3s; }
.wrap { max-width: 980px; margin: 40px auto; padding: 0 16px; }
.header-container { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
.logo-container { display: flex; align-items: center; gap: 10px; }
.custom-logo { max-width: 100px; height: auto; }
h1 { margin: 0; font-size: 22px; color: var(--zbx-red); }
.muted { font-size: 13px; }
body.light-theme .muted { color: var(--text-muted-light); }
body.dark-theme .muted { color: var(--text-muted-dark); }
label { display: block; margin: .6rem 0 .3rem; }
body.light-theme label { color: var(--text-dark); }
body.dark-theme label { color: var(--text-light); }

input, textarea, select { width: 100%; padding: .6rem .7rem; border-radius: 10px; border: 1px solid; }
body.light-theme input, body.light-theme textarea, body.light-theme select { border-color: #ccc; background: var(--input-light); color: var(--text-dark); }
body.dark-theme input, body.dark-theme textarea, body.dark-theme select { border-color: #444; background: var(--input-dark); color: var(--text-light); }

.grid { display: grid; gap: 14px; } .g2 { grid-template-columns: 1fr 1fr; } .g3 { grid-template-columns: repeat(3, 1fr); }
.btn { display: inline-block; margin-top: 14px; padding: .7rem 1rem; border: 0; border-radius: 10px; background: var(--zbx-red); color: #fff; font-weight: 700; cursor: pointer; }
.btn:hover { opacity: .9; }
small { }
body.light-theme small { color: var(--text-muted-light); }
body.dark-theme small { color: var(--text-muted-dark); }

.chk { display: flex; align-items: center; gap: 8px; padding: 8px; border: 1px solid; border-radius: 10px; }
body.light-theme .chk { border-color: #ccc; background: #fff; }
body.dark-theme .chk { border-color: #444; background: #3a3a3a; }
.chk input { width: auto; }

.badge { display: inline-block; border: 1px solid; padding: .25rem .5rem; border-radius: 999px; font-size: 12px; margin-left: 8px; }
body.light-theme .badge { border-color: #ccc; color: var(--text-muted-light); background: #f0f0f0; }
body.dark-theme .badge { border-color: #444; color: var(--text-muted-dark); background: #3b3b3b; }

.zabbix-logo { background: var(--zbx-red); color: #fff; padding: 5px 10px; border-radius: 5px; font-weight: bold; font-size: 1.2rem; display: inline-block; }

.theme-switcher { position: absolute; top: 20px; right: 20px; background: none; border: 1px solid; padding: 5px 10px; border-radius: 5px; font-size: 14px; cursor: pointer; }
body.light-theme .theme-switcher { color: #555; background-color: #f0f0f0; border-color: #ccc; }
body.dark-theme .theme-switcher { color: #ccc; background-color: #3a3a3a; border-color: #444; }

.hosts-container, .templates-container, .hostgroups-container {
  display: flex;
  gap: 8px;
  align-items: flex-start;
}
.hosts-container textarea, .templates-container textarea, .hostgroups-container textarea {
  flex-grow: 1;
}

.modal {
  display: none; 
  position: fixed;
  z-index: 1000;
  left: 0;
  top: 0;
  width: 100%;
  height: 100%;
  overflow: auto;
  background-color: rgba(0,0,0,0.4);
}
.modal-content {
  background-color: var(--card-light);
  margin: 10% auto;
  padding: 20px;
  border: 1px solid var(--card-border-light);
  width: 80%;
  max-width: 600px;
  border-radius: 14px;
}
body.dark-theme .modal-content {
  background-color: var(--card-dark);
  border: 1px solid var(--card-border-dark);
}
.close {
  color: var(--text-muted-light);
  float: right;
  font-size: 28px;
  font-weight: bold;
}
body.dark-theme .close {
  color: var(--text-muted-dark);
}
.close:hover,
.close:focus {
  color: var(--zbx-red);
  text-decoration: none;
  cursor: pointer;
}
.modal-footer {
  text-align: right;
  margin-top: 15px;
}
.modal h2 {
    margin-top: 0;
}
.modal .btn {
    margin-top: 0;
}
.modal-filter {
  width: 100%;
  padding: 8px;
  margin-bottom: 10px;
  border: 1px solid #ccc;
  border-radius: 5px;
  box-sizing: border-box;
}

.credit {
  margin-top: 20px;
  font-size: 12px;
  text-align: center;
}

.pagination-controls {
    display: inline-flex;
    border-radius: 8px;
    overflow: hidden;
    border: 1px solid #ccc;
}
body.dark-theme .pagination-controls {
    border-color: #444;
}
.pagination-controls button, .pagination-controls span {
    padding: 6px 12px;
    margin: 0;
    border: none;
    border-right: 1px solid #ccc;
    background-color: var(--bg-light);
    color: var(--text-dark);
    font-size: 14px;
}
.pagination-controls span.ellipsis {
    padding-top: 8px;
}
body.dark-theme .pagination-controls button, body.dark-theme .pagination-controls span {
    background-color: var(--input-dark);
    color: var(--text-light);
    border-right: 1px solid #444;
}
.pagination-controls button:last-child {
    border-right: none;
}
.pagination-controls button {
    cursor: pointer;
}
.pagination-controls button:hover {
    background-color: #e9e9e9;
}
body.dark-theme .pagination-controls button:hover {
    background-color: #555;
}
.pagination-controls button.active {
    background-color: var(--zbx-red);
    color: white;
    border-color: var(--zbx-red);
}
.bulk-ops-controls button {
    font-size: 12px;
    padding: .4rem .8rem;
    margin-top: 0;
    margin-right: 5px;
}
</style>
<?php if (defined('APPLY_LOGO_BLEND_MODE') && APPLY_LOGO_BLEND_MODE): ?>
<style>
body.dark-theme .custom-logo {
  mix-blend-mode: multiply;
}
</style>
<?php endif; ?>
</head>
<body class="light-theme">
<button id="theme-toggle" class="theme-switcher"><?= t('theme_dark') ?></button>
<div class="wrap">
  <div class="card">
    <div class="header-container">
      <div class="logo-container">
        <img src="<?= htmlspecialchars(defined('CUSTOM_LOGO_PATH') ? CUSTOM_LOGO_PATH : 'assets/sonda.png', ENT_QUOTES, 'UTF-8') ?>" alt="Logo" class="custom-logo" />
        <span class="zabbix-logo">Zabbix</span>
      </div>
      <div>
        <h1><?= t('export_title') ?></h1>
        <div class="muted"><?= t('export_logged_in_as') ?> <b><?=htmlspecialchars($_SESSION['zbx_user'],ENT_QUOTES,'UTF-8')?></b> Front: <?=htmlspecialchars(ZABBIX_URL,ENT_QUOTES,'UTF-8')?></div>
      </div>
    </div>

    <form method="post" action="generate.php" target="_blank" id="form-export">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
      
      <label><?= t('export_hosts_label') ?></label>
      <div class="hosts-container">
        <textarea name="hostnames" id="hostnames-textarea" rows="4" placeholder="<?= t('export_hosts_placeholder') ?>"></textarea>
        <button type="button" class="btn" id="open-host-modal"><?= t('modal_select_button') ?></button>
      </div>

      <label><?= t('export_groups_label') ?></label>
      <div class="hostgroups-container">
        <textarea name="hostgroups" id="hostgroups-textarea" rows="4" placeholder="<?= t('export_groups_placeholder') ?>"></textarea>
        <button type="button" class="btn" id="open-hostgroup-modal"><?= t('modal_select_button') ?></button>
      </div>
      
      <label><?= t('export_templates_items_label') ?></label>
      <div class="templates-container">
        <textarea name="template_and_items_txt" id="templates-and-items-textarea" rows="4" placeholder="<?= t('export_templates_items_placeholder') ?>"></textarea>
        <button type="button" class="btn" id="open-template-item-modal"><?= t('modal_select_button') ?></button>
      </div>

      <input type="hidden" name="item_keys" id="itemkeys-hidden-input" />
      <input type="hidden" name="templateids" id="templateids-hidden-input" />
      <input type="hidden" name="hostids" id="hostids-hidden-input" />
      <input type="hidden" name="hostgroupids" id="hostgroupids-hidden-input" />

      <div class="grid g2">
        <div>
          <label><?= t('export_from_label') ?></label>
          <input type="datetime-local" name="from_dt" id="from_dt" />
        </div>
        <div>
          <label><?= t('export_to_label') ?></label>
          <input type="datetime-local" name="to_dt" id="to_dt" />
        </div>
      </div>
      <div style="text-align: right;">
        <button type="button" class="btn" id="24h-btn" style="background-color: #6c757d;"><?= t('export_last_24h') ?></button>
      </div>
      <small><?= t('export_time_range_note') ?></small>


      <input type="hidden" name="client_tz" id="client_tz">
      <input type="hidden" name="client_offset_min" id="client_offset_min">

      <button class="btn" type="submit" id="generate-pdf-btn"><?= t('export_generate_pdf_button') ?></button>
    </form>
  </div>
  <div class="credit muted">
      <?= t('common_author_credit') ?>
  </div>
</div>

<div id="host-modal" class="modal">
  <div class="modal-content">
    <span class="close">&times;</span>
    <h2><?= t('modal_select_hosts_title') ?></h2>
    <input type="text" id="host-filter" class="modal-filter" placeholder="<?= t('modal_filter_hosts_placeholder') ?>" />
    <div class="bulk-ops-controls" style="margin-bottom: 10px;">
        <button type="button" class="btn" id="host-select-all" style="background-color: #3498db;"><?= t('modal_select_page_button') ?></button>
        <button type="button" class="btn" id="host-deselect-all" style="background-color: #95a5a6;"><?= t('modal_deselect_page_button') ?></button>
    </div>
    <div id="host-list" style="max-height: 250px; overflow-y: auto; margin-bottom: 10px; border: 1px solid #ddd; padding: 5px; border-radius: 10px;"></div>
    <div style="text-align: center;">
      <div id="host-pagination" class="pagination-controls"></div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn" id="select-hosts"><?= t('modal_select_button') ?></button>
      <button type="button" class="btn" id="cancel-host-selection" style="background-color: #6c757d;"><?= t('modal_cancel_button') ?></button>
    </div>
  </div>
</div>

<div id="hostgroup-modal" class="modal">
  <div class="modal-content">
    <span class="close">&times;</span>
    <h2><?= t('modal_select_groups_title') ?></h2>
    <input type="text" id="hostgroup-filter" class="modal-filter" placeholder="<?= t('modal_filter_groups_placeholder') ?>" />
    <div class="bulk-ops-controls" style="margin-bottom: 10px;">
        <button type="button" class="btn" id="hostgroup-select-all" style="background-color: #3498db;"><?= t('modal_select_page_button') ?></button>
        <button type="button" class="btn" id="hostgroup-deselect-all" style="background-color: #95a5a6;"><?= t('modal_deselect_page_button') ?></button>
    </div>
    <div id="hostgroup-list" style="max-height: 250px; overflow-y: auto; margin-bottom: 10px; border: 1px solid #ddd; padding: 5px; border-radius: 10px;"></div>
    <div style="text-align: center;">
      <div id="hostgroup-pagination" class="pagination-controls"></div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn" id="select-hostgroups"><?= t('modal_select_button') ?></button>
      <button type="button" class="btn" id="cancel-hostgroup-selection" style="background-color: #6c757d;"><?= t('modal_cancel_button') ?></button>
    </div>
  </div>
</div>

<div id="template-item-modal" class="modal">
  <div class="modal-content">
    <span class="close">&times;</span>
    <h2 id="modal-title"><?= t('modal_select_templates_title') ?></h2>
    <div id="modal-step-1">
      <input type="text" id="template-filter" class="modal-filter" placeholder="<?= t('modal_filter_templates_placeholder') ?>" />
      <div class="bulk-ops-controls" style="margin-bottom: 10px;">
        <button type="button" class="btn" id="template-select-all" style="background-color: #3498db;"><?= t('modal_select_page_button') ?></button>
        <button type="button" class="btn" id="template-deselect-all" style="background-color: #95a5a6;"><?= t('modal_deselect_page_button') ?></button>
      </div>
      <div id="template-list" style="max-height: 250px; overflow-y: auto; margin-bottom: 10px; border: 1px solid #ddd; padding: 5px; border-radius: 10px;"></div>
      <div style="text-align: center;">
        <div id="template-pagination" class="pagination-controls"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn" id="next-to-items"><?= t('modal_next_button') ?></button>
        <button type="button" class="btn" id="cancel-template-selection" style="background-color: #6c757d;"><?= t('modal_cancel_button') ?></button>
      </div>
    </div>
    <div id="modal-step-2" style="display:none;">
      <input type="text" id="item-filter" class="modal-filter" placeholder="<?= t('modal_filter_items_placeholder') ?>" />
      <div id="item-list" style="max-height: 300px; overflow-y: auto; border: 1px solid #ddd; padding: 5px; border-radius: 10px;"></div>
      <div class="modal-footer">
        <button type="button" class="btn" id="select-items"><?= t('modal_add_items_button') ?></button>
        <button type="button" class="btn" id="back-to-templates" style="background-color: #6c757d;"><?= t('modal_back_button') ?></button>
      </div>
    </div>
  </div>
</div>

<script>
  const T = <?= json_encode($translations) ?>;
  
  /**
   * L�GICA DE PAGINACI�N COMPLETA Y REUTILIZABLE
   */
  const itemsPerPage = 10;

  // NUEVA FUNCI�N DE PAGINACI�N INTELIGENTE
  function renderPagination(container, currentPage, totalItems, onPageClick) {
    container.innerHTML = '';
    const totalPages = Math.ceil(totalItems / itemsPerPage);
    if (totalPages <= 1) return;

    const createButton = (text, page) => {
        const btn = document.createElement('button');
        btn.innerHTML = text; // Usar innerHTML para los s�mbolos
        if (page) {
            btn.dataset.page = page;
            if (page === currentPage) btn.classList.add('active');
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                onPageClick(page);
            });
        } else {
            btn.disabled = true;
        }
        return btn;
    };
    
    const createEllipsis = () => {
        const span = document.createElement('span');
        span.className = 'ellipsis';
        span.textContent = '...';
        return span;
    };

    container.appendChild(createButton('&laquo;', currentPage > 1 ? currentPage - 1 : null));

    const pagesToShow = new Set();
    pagesToShow.add(1);
    if (totalPages > 1) pagesToShow.add(totalPages);
    pagesToShow.add(currentPage);
    if (currentPage > 1) pagesToShow.add(currentPage - 1);
    if (currentPage < totalPages) pagesToShow.add(currentPage + 1);

    const sortedPages = Array.from(pagesToShow).sort((a,b) => a - b);
    let lastPage = 0;

    sortedPages.forEach(page => {
        if (page > lastPage + 1) {
            container.appendChild(createEllipsis());
        }
        container.appendChild(createButton(String(page), page));
        lastPage = page;
    });

    container.appendChild(createButton('&raquo;', currentPage < totalPages ? currentPage + 1 : null));
  }
  
  // --- L�GICA DE MODALES INDIVIDUAL Y CORREGIDA ---

  // 1. MODAL DE HOSTS
  (() => {
    const modal = document.getElementById('host-modal');
    const openBtn = document.getElementById('open-host-modal');
    const closeBtn = modal.querySelector('.close');
    const cancelBtn = document.getElementById('cancel-host-selection');
    const selectBtn = document.getElementById('select-hosts');
    const filterInput = document.getElementById('host-filter');
    const listContainer = document.getElementById('host-list');
    const paginationContainer = document.getElementById('host-pagination');
    const selectAllBtn = document.getElementById('host-select-all');
    const deselectAllBtn = document.getElementById('host-deselect-all');
    const textarea = document.getElementById('hostnames-textarea');
    const hiddenInput = document.getElementById('hostids-hidden-input');
    
    let allData = [];
    let currentPage = 1;

    const populate = (filter = '') => {
        const filtered = allData.filter(item => item.name.toLowerCase().includes(filter.toLowerCase()));
        listContainer.innerHTML = '';
        const startIndex = (currentPage - 1) * itemsPerPage;
        const pageData = filtered.slice(startIndex, startIndex + itemsPerPage);

        if (pageData.length === 0) { listContainer.innerHTML = `<p>${T.modal_no_results}</p>`; }
        pageData.forEach(item => {
            const label = document.createElement('label');
            label.className = 'chk';
            label.innerHTML = `<input type="checkbox" name="host[]" value="${item.hostid}" data-name="${item.name}"> ${item.name}`;
            listContainer.appendChild(label);
        });

        renderPagination(paginationContainer, currentPage, filtered.length, page => {
            currentPage = page;
            populate(filterInput.value);
        });
    };

    openBtn.onclick = () => {
        modal.style.display = 'block';
        if (allData.length === 0) {
            listContainer.innerHTML = `<p>${T.modal_loading}</p>`;
            fetch('get_hosts.php').then(res => res.json()).then(data => {
                allData = data.error ? [] : data;
                currentPage = 1;
                populate();
            });
        } else {
            currentPage = 1;
            populate(filterInput.value = '');
        }
    };
    
    filterInput.onkeyup = () => { currentPage = 1; populate(filterInput.value); };
    selectAllBtn.onclick = () => listContainer.querySelectorAll('input').forEach(cb => cb.checked = true);
    deselectAllBtn.onclick = () => listContainer.querySelectorAll('input').forEach(cb => cb.checked = false);
    closeBtn.onclick = () => modal.style.display = 'none';
    cancelBtn.onclick = () => modal.style.display = 'none';

    selectBtn.onclick = () => {
        const checkboxes = modal.querySelectorAll('input[type="checkbox"]:checked');
        const selectedNames = Array.from(checkboxes).map(cb => cb.dataset.name);
        const selectedIds = Array.from(checkboxes).map(cb => cb.value);
        let currentNames = textarea.value.trim() ? textarea.value.split(', ').filter(Boolean) : [];
        let currentIds = hiddenInput.value.trim() ? hiddenInput.value.split(',') : [];
        textarea.value = Array.from(new Set([...currentNames, ...selectedNames])).join(', ');
        hiddenInput.value = Array.from(new Set([...currentIds, ...selectedIds])).join(',');
        modal.style.display = 'none';
    };
  })();

  // 2. MODAL DE GRUPOS DE HOSTS
  (() => {
    const modal = document.getElementById('hostgroup-modal');
    const openBtn = document.getElementById('open-hostgroup-modal');
    const closeBtn = modal.querySelector('.close');
    const cancelBtn = document.getElementById('cancel-hostgroup-selection');
    const selectBtn = document.getElementById('select-hostgroups');
    const filterInput = document.getElementById('hostgroup-filter');
    const listContainer = document.getElementById('hostgroup-list');
    const paginationContainer = document.getElementById('hostgroup-pagination');
    const selectAllBtn = document.getElementById('hostgroup-select-all');
    const deselectAllBtn = document.getElementById('hostgroup-deselect-all');
    const textarea = document.getElementById('hostgroups-textarea');
    const hiddenInput = document.getElementById('hostgroupids-hidden-input');

    let allData = [];
    let currentPage = 1;

    const populate = (filter = '') => {
        const filtered = allData.filter(item => item.name.toLowerCase().includes(filter.toLowerCase()));
        listContainer.innerHTML = '';
        const startIndex = (currentPage - 1) * itemsPerPage;
        const pageData = filtered.slice(startIndex, startIndex + itemsPerPage);

        if (pageData.length === 0) { listContainer.innerHTML = `<p>${T.modal_no_results}</p>`; }
        pageData.forEach(item => {
            const label = document.createElement('label');
            label.className = 'chk';
            label.innerHTML = `<input type="checkbox" name="hostgroup[]" value="${item.groupid}" data-name="${item.name}"> ${item.name}`;
            listContainer.appendChild(label);
        });

        renderPagination(paginationContainer, currentPage, filtered.length, page => {
            currentPage = page;
            populate(filterInput.value);
        });
    };

    openBtn.onclick = () => {
        modal.style.display = 'block';
        if (allData.length === 0) {
            listContainer.innerHTML = `<p>${T.modal_loading}</p>`;
            fetch('get_host_groups.php').then(res => res.json()).then(data => {
                allData = data.error ? [] : data;
                currentPage = 1;
                populate();
            });
        } else {
            currentPage = 1;
            populate(filterInput.value = '');
        }
    };
    
    filterInput.onkeyup = () => { currentPage = 1; populate(filterInput.value); };
    selectAllBtn.onclick = () => listContainer.querySelectorAll('input').forEach(cb => cb.checked = true);
    deselectAllBtn.onclick = () => listContainer.querySelectorAll('input').forEach(cb => cb.checked = false);
    closeBtn.onclick = () => modal.style.display = 'none';
    cancelBtn.onclick = () => modal.style.display = 'none';

    selectBtn.onclick = () => {
        const checkboxes = modal.querySelectorAll('input[type="checkbox"]:checked');
        const selectedNames = Array.from(checkboxes).map(cb => cb.dataset.name);
        const selectedIds = Array.from(checkboxes).map(cb => cb.value);
        let currentNames = textarea.value.trim() ? textarea.value.split(', ').filter(Boolean) : [];
        let currentIds = hiddenInput.value.trim() ? hiddenInput.value.split(',') : [];
        textarea.value = Array.from(new Set([...currentNames, ...selectedNames])).join(', ');
        hiddenInput.value = Array.from(new Set([...currentIds, ...selectedIds])).join(',');
        modal.style.display = 'none';
    };
  })();

  // 3. MODAL DE PLANTILLAS Y ITEMS
  (() => {
    const modal = document.getElementById('template-item-modal');
    const openBtn = document.getElementById('open-template-item-modal');
    const closeBtn = modal.querySelector('.close');
    const cancelBtn = document.getElementById('cancel-template-selection');
    
    const step1 = document.getElementById('modal-step-1');
    const filterInput1 = document.getElementById('template-filter');
    const listContainer1 = document.getElementById('template-list');
    const paginationContainer1 = document.getElementById('template-pagination');
    const selectAllBtn1 = document.getElementById('template-select-all');
    const deselectAllBtn1 = document.getElementById('template-deselect-all');
    const nextBtn = document.getElementById('next-to-items');
    
    const step2 = document.getElementById('modal-step-2');
    const itemFilter = document.getElementById('item-filter');
    const itemList = document.getElementById('item-list');
    const selectItemsBtn = document.getElementById('select-items');
    const backBtn = document.getElementById('back-to-templates');
    const modalTitle = document.getElementById('modal-title');
    
    const allTemplates = <?php echo json_encode($zabbixTemplates); ?>;
    let allItems = [];
    let currentPage = 1;

    const populateTemplates = (filter = '') => {
        const filtered = allTemplates.filter(item => item.name.toLowerCase().includes(filter.toLowerCase()));
        listContainer1.innerHTML = '';
        const startIndex = (currentPage - 1) * itemsPerPage;
        const pageData = filtered.slice(startIndex, startIndex + itemsPerPage);

        if (pageData.length === 0) { listContainer1.innerHTML = `<p>${T.modal_no_results}</p>`; }
        pageData.forEach(item => {
            const label = document.createElement('label');
            label.className = 'chk';
            label.innerHTML = `<input type="checkbox" name="template[]" value="${item.templateid}" data-name="${item.name}"> ${item.name}`;
            listContainer1.appendChild(label);
        });

        renderPagination(paginationContainer1, currentPage, filtered.length, page => {
            currentPage = page;
            populateTemplates(filterInput1.value);
        });
    };

    openBtn.onclick = () => {
        modal.style.display = 'block';
        step1.style.display = 'block';
        step2.style.display = 'none';
        modalTitle.textContent = T.modal_select_templates_title;
        currentPage = 1;
        populateTemplates(filterInput1.value = '');
    };
    
    filterInput1.onkeyup = () => { currentPage = 1; populateTemplates(filterInput1.value); };
    selectAllBtn1.onclick = () => listContainer1.querySelectorAll('input').forEach(cb => cb.checked = true);
    deselectAllBtn1.onclick = () => listContainer1.querySelectorAll('input').forEach(cb => cb.checked = false);
    closeBtn.onclick = () => modal.style.display = 'none';
    cancelBtn.onclick = () => modal.style.display = 'none';
    backBtn.onclick = () => {
        step1.style.display = 'block';
        step2.style.display = 'none';
        modalTitle.textContent = T.modal_select_templates_title;
    };
    
    nextBtn.onclick = () => {
        const checkboxes = listContainer1.querySelectorAll('input[type="checkbox"]:checked');
        if (checkboxes.length === 0) {
            alert(T.alert_select_template);
            return;
        }
        const selectedTemplateIds = Array.from(checkboxes).map(cb => cb.value);

        modalTitle.textContent = T.modal_select_items_title;
        step1.style.display = 'none';
        step2.style.display = 'block';
        itemList.innerHTML = `<p>${T.modal_loading}</p>`;

        fetch('get_items.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ templateids: selectedTemplateIds })
        }).then(res => res.json()).then(items => {
            allItems = items.error ? [] : items;
            populateItems();
        });
    };
    
    const populateItems = (filter = '') => {
        const filtered = allItems.filter(item => item.name.toLowerCase().includes(filter.toLowerCase()) || item.key_.toLowerCase().includes(filter.toLowerCase()));
        itemList.innerHTML = '';
        if (filtered.length === 0) { itemList.innerHTML = `<p>${T.modal_no_results}</p>`; }
        filtered.forEach(item => {
            const label = document.createElement('label');
            label.className = 'chk';
            label.innerHTML = `<input type="checkbox" name="item[]" value="${item.itemid}" data-name="${item.name}" data-key="${item.key_}"> ${item.name} <small>${item.key_}</small>`;
            itemList.appendChild(label);
        });
    };
    
    itemFilter.onkeyup = () => populateItems(itemFilter.value);
    
    selectItemsBtn.onclick = () => {
        const itemCheckboxes = itemList.querySelectorAll('input[type="checkbox"]:checked');
        if (itemCheckboxes.length === 0) {
            alert(T.alert_select_item);
            return;
        }
        const templatesAndItemsTextarea = document.getElementById('templates-and-items-textarea');
        const itemkeysHiddenInput = document.getElementById('itemkeys-hidden-input');
        
        const newSelectedItemNames = Array.from(itemCheckboxes).map(cb => cb.dataset.name);
        const newSelectedItemKeys = Array.from(itemCheckboxes).map(cb => cb.dataset.key);

        let currentItemKeys = itemkeysHiddenInput.value.split(',').filter(Boolean);
        let currentText = templatesAndItemsTextarea.value;
        let currentItemNames = [];
        let currentTemplateNamesStr = '';
        
        if (currentText.includes('| Items:')) {
            let parts = currentText.split('| Items:');
            currentTemplateNamesStr = parts[0];
            currentItemNames = parts[1].split(', ').filter(Boolean);
        } else if (currentText.includes('Plantillas:')) {
            currentTemplateNamesStr = currentText;
        }

        const combinedItemKeys = Array.from(new Set([...currentItemKeys, ...newSelectedItemKeys]));
        const combinedItemNames = Array.from(new Set([...currentItemNames, ...newSelectedItemNames]));
        
        itemkeysHiddenInput.value = combinedItemKeys.join(',');
        templatesAndItemsTextarea.value = `${currentTemplateNamesStr} | Items: ${combinedItemNames.join(', ')}`;
        
        modal.style.display = 'none';
    };
  })();
  
  // --- C�DIGO GENERAL DE LA P�GINA ---
  const themeToggle = document.getElementById('theme-toggle');
  const body = document.body;

  function setTheme(theme) {
    if (theme === 'dark') {
      body.classList.remove('light-theme');
      body.classList.add('dark-theme');
      themeToggle.textContent = T.theme_light;
    } else {
      body.classList.remove('dark-theme');
      body.classList.add('light-theme');
      themeToggle.textContent = T.theme_dark;
    }
    localStorage.setItem('theme', theme);
  }

  themeToggle.addEventListener('click', () => {
    const currentTheme = body.classList.contains('dark-theme') ? 'dark' : 'light';
    const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
    setTheme(newTheme);
  });

  const savedTheme = localStorage.getItem('theme');
  if (savedTheme) {
    setTheme(savedTheme);
  } else {
    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    setTheme(prefersDark ? 'dark' : 'light');
  }

  (function(){
    const tz = Intl.DateTimeFormat().resolvedOptions().timeZone || '';
    const offMin = - new Date().getTimezoneOffset();
    document.getElementById('client_tz').value = tz;
    document.getElementById('client_offset_min').value = offMin;
  })();
  
  window.onclick = function(event) {
    if (event.target.matches('.modal')) {
        event.target.style.display = 'none';
    }
  }
  
  document.getElementById('24h-btn').addEventListener('click', () => {
    const now = new Date();
    const from = new Date(now.getTime() - 24 * 60 * 60 * 1000);
    
    const formatDate = (date) => {
      const yyyy = date.getFullYear();
      const mm = (date.getMonth() + 1).toString().padStart(2, '0');
      const dd = date.getDate().toString().padStart(2, '0');
      const hh = date.getHours().toString().padStart(2, '0');
      const min = date.getMinutes().toString().padStart(2, '0');
      return `${yyyy}-${mm}-${dd}T${hh}:${min}`;
    };

    document.getElementById('from_dt').value = formatDate(from);
    document.getElementById('to_dt').value = formatDate(now);
  });

  document.getElementById('form-export').addEventListener('submit', function() {
      setTimeout(function() {
          window.location.reload();
      }, 500);
  });
</script>
</body>
</html>