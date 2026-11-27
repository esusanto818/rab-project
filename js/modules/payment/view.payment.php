<?php require "../../php-func/user.valid.php"; ?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <title>Customer Payment Entry</title>
  <link rel="stylesheet" href="../../css/ui.components.css">
  <script src="../../js/app.core.js"></script>
</head>
<body>

<div class="container">
  <div class="ui-header">
    <h1>Customer <em>Payment Entry</em></h1>
    <button id="btnNew" class="btn btn-primary" onclick="openNewModal()">+ Tambah Pembayaran</button>
  </div>

  <div class="ui-card">
    <div class="table-responsive">
      <table class="ui-table">
        <thead>
          <tr>
            <th>No. Bayar</th>
            <th>Tanggal</th>
            <th>Pelanggan</th>
            <th>Total Diterima</th>
            <th>Pakai Deposit</th>
            <th>Aksi</th>
          </tr>
        </thead>
        <tbody id="gridBody">
          <tr><td colspan="6" style="text-align:center">Memuat data...</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Modal Form Reusable Component -->
<div id="formOverlay" class="modal-overlay">
  <div class="modal-content">
    <div class="modal-head">
      <h3 id="modalTitle" >Penerimaan Pembayaran</h3>
      <button onclick="UIModal.hide('formOverlay')" style="; cursor:pointer; font-size:22px">&times;</button>
    </div>
    
    <div id="errBox" class="err-box"></div>

    <form id="mainForm">
      <div class="form-grid-2">
        <div class="form-group">
          <label>Customer</label>
          <select id="customerId" class="form-control" required></select>
        </div>
        <div class="form-group">
          <label>Tgl Bayar</label>
          <input type="date" id="paymentDate" class="form-control" required>
        </div>
      </div>
      
      <div class="modal-foot">
        <button type="button" class="btn btn-secondary" onclick="UIModal.hide('formOverlay')">Batal</button>
        <button type="button" id="btnSave" class="btn btn-primary">Simpan</button>
      </div>
    </form>
  </div>
</div>

<script>
  const ENDPOINT = '../../php-crud/paymentCRD.php';

  async function loadGrid() {
    const res = await apiCall(ENDPOINT, 'read');
    const tbody = $('gridBody');
    if (!res.success || !res.data.length) {
      tbody.innerHTML = '<tr><td colspan="6" style="text-align:center">Tidak ada data.</td></tr>';
      return;
    }
    tbody.innerHTML = res.data.map(i => `
      <tr>
        <td >${i.payment_no}</td>
        <td>${i.payment_date}</td>
        <td>${i.customer_name}</td>
        <td>${UIFormat.currency(i.total_amount)}</td>
        <td>${UIFormat.currency(i.deposit_applied)}</td>
        <td>
          <button class="btn btn-secondary btn-sm" onclick="openEdit(${i.id})">Edit</button>
        </td>
      </tr>
    `).join('');
  }

  function openNewModal() {
    $('mainForm').reset();
    UIModal.hideErr('errBox');
    UIModal.show('formOverlay');
  }

  document.addEventListener('DOMContentLoaded', loadGrid);
</script>

</body>
</html>