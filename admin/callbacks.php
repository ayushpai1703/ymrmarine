<?php
require_once __DIR__ . '/includes/admin_header.php';
require_once __DIR__ . '/../includes/callback_helpers.php';
$pdo = getDB();
ensureCallbackRequestsTable($pdo);

if (isset($_GET['delete'])) {
    $pdo->prepare('DELETE FROM callback_requests WHERE id = ?')->execute([(int)$_GET['delete']]);
    flash('success', 'Callback request deleted');
    redirect('callbacks.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $status = $_POST['status'] ?? 'Pending';
    if (!in_array($status, ['Pending', 'Contacted', 'Closed'], true)) {
        $status = 'Pending';
    }
    $calledAt = trim($_POST['called_at'] ?? '');
    $notes = trim($_POST['admin_notes'] ?? '');

    $pdo->prepare('UPDATE callback_requests SET status = ?, called_at = ?, admin_notes = ? WHERE id = ?')
        ->execute([$status, $calledAt !== '' ? $calledAt : null, $notes !== '' ? $notes : null, $id]);
    flash('success', 'Callback request updated');
    redirect('callbacks.php');
}

$edit = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM callback_requests WHERE id = ?');
    $stmt->execute([(int)$_GET['edit']]);
    $edit = $stmt->fetch();
}

$requests = $pdo->query('SELECT * FROM callback_requests ORDER BY created_at DESC')->fetchAll();

$statusBadge = [
    'Pending' => 'badge-warning',
    'Contacted' => 'badge-muted',
    'Closed' => 'badge-success',
];
?>
<?php if ($edit): ?>
<div class="card">
  <div class="card-title">Update Callback Request — <?= e($edit['full_name']) ?>
    <a href="callbacks.php" class="btn btn-sm-action btn-secondary">Cancel</a>
  </div>
  <div style="display:grid;gap:0.4rem;font-size:0.95rem;margin-bottom:1.25rem;">
    <p><strong>Company:</strong> <?= e($edit['company_name'] ?? '—') ?></p>
    <p><strong>Mobile Number:</strong> <a href="tel:<?= e($edit['mobile_number']) ?>"><?= e($edit['mobile_number']) ?></a></p>
    <p><strong>Alternate Mobile Number:</strong> <?= $edit['alternate_mobile_number'] ? '<a href="tel:'.e($edit['alternate_mobile_number']).'">'.e($edit['alternate_mobile_number']).'</a>' : '—' ?></p>
    <p><strong>Submitted:</strong> <?= date('d M Y, H:i', strtotime($edit['created_at'])) ?></p>
  </div>
  <form method="POST"><?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= $edit['id'] ?>">
    <div class="form-grid">
      <div class="form-group">
        <label>Status</label>
        <select name="status">
          <?php foreach (['Pending', 'Contacted', 'Closed'] as $s): ?>
          <option value="<?= $s ?>" <?= $edit['status'] === $s ? 'selected' : '' ?>><?= $s ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Called At</label>
        <input type="datetime-local" name="called_at" value="<?= $edit['called_at'] ? date('Y-m-d\TH:i', strtotime($edit['called_at'])) : '' ?>">
      </div>
      <div class="form-group full">
        <label>Admin Notes</label>
        <textarea name="admin_notes" rows="4"><?= e($edit['admin_notes'] ?? '') ?></textarea>
      </div>
    </div>
    <button type="submit" class="btn"><i class="fas fa-save"></i> Save</button>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-title">Callback Requests (<?= count($requests) ?>)</div>
  <?php if (empty($requests)): ?>
    <div class="empty"><i class="fas fa-phone-volume"></i><p>No callback requests yet</p></div>
  <?php else: ?>
  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>#</th><th>Date/Time</th><th>Full Name</th><th>Company</th><th>Mobile Number</th><th>Alternate Number</th><th>Status</th><th>Called At</th><th>Notes</th><th>Actions</th></tr>
      </thead>
      <tbody>
        <?php foreach ($requests as $r): ?>
        <tr>
          <td><?= $r['id'] ?></td>
          <td><?= date('d M Y, H:i', strtotime($r['created_at'])) ?></td>
          <td><?= e($r['full_name']) ?></td>
          <td><?= e($r['company_name'] ?? '—') ?></td>
          <td><a href="tel:<?= e($r['mobile_number']) ?>"><?= e($r['mobile_number']) ?></a></td>
          <td><?= $r['alternate_mobile_number'] ? '<a href="tel:'.e($r['alternate_mobile_number']).'">'.e($r['alternate_mobile_number']).'</a>' : '—' ?></td>
          <td><span class="badge <?= $statusBadge[$r['status']] ?? 'badge-muted' ?>"><?= e($r['status']) ?></span></td>
          <td><?= $r['called_at'] ? date('d M Y, H:i', strtotime($r['called_at'])) : '—' ?></td>
          <td><?= $r['admin_notes'] ? e(mb_strimwidth($r['admin_notes'], 0, 40, '…')) : '—' ?></td>
          <td style="white-space:nowrap;">
            <a href="?edit=<?= $r['id'] ?>" class="btn btn-sm-action btn-secondary"><i class="fas fa-edit"></i></a>
            <a href="?delete=<?= $r['id'] ?>" class="btn btn-sm-action btn-danger" onclick="return confirm('Delete this callback request?')"><i class="fas fa-trash"></i></a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
