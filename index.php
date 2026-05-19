<?php
require 'db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }

$u_id = intval($_SESSION['user_id']);
$today = date('Y-m-d');
$msg = '';
$error = '';

require_once 'functions.php';

// --- AJAX ОБРАБОТКА СПИСАНИЯ БОНУСОВ ---
if (isset($_POST['use_bonuses_ajax'])) {
    header('Content-Type: application/json');
    $inv_id = intval($_POST['inv_id']);
    $use_byn = floatval($_POST['bonuses_byn'] ?? 0);
    $use = intval(round($use_byn * 100));

    $invStmt = $pdo->prepare("SELECT * FROM invoices WHERE id = ? AND receiver_id1 = ?");
    $invStmt->execute([$inv_id, $u_id]);
    $invoice = $invStmt->fetch(PDO::FETCH_ASSOC);

    if (!$invoice) {
        echo json_encode(['success' => false, 'error' => 'Счет не найден.']);
        exit;
    } elseif ($invoice['status'] == 'Оплачен') {
        echo json_encode(['success' => false, 'error' => 'Счет уже оплачен.']);
        exit;
    } elseif ($use <= 0) {
        echo json_encode(['success' => false, 'error' => 'Введите корректную сумму.']);
        exit;
    } else {
        $uStmt = $pdo->prepare("SELECT bonus_balance FROM users1 WHERE id = ?");
        $uStmt->execute([$u_id]);
        $uRow = $uStmt->fetch(PDO::FETCH_ASSOC);
        $balance = intval($uRow['bonus_balance'] ?? 0);

        if ($use > $balance) {
            echo json_encode(['success' => false, 'error' => 'Недостаточно бонусов.']);
            exit;
        } else {
            $final_amount_byn = calculateFinalAmount($invoice);
            $final_cop = intval(round($final_amount_byn * 100));
            $use = min($use, $final_cop);

            try {
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE users1 SET bonus_balance = bonus_balance - ? WHERE id = ?")->execute([$use, $u_id]);
                $pdo->prepare("UPDATE invoices SET bonuses_spent = COALESCE(bonuses_spent,0) + ?, pending_status = ? WHERE id = ?")
                    ->execute([$use, 'Бонусы списаны', $inv_id]);
                $pdo->commit();

                // Получаем новые итоги для обновления UI
                $uStmt->execute([$u_id]);
                $new_balance = $uStmt->fetchColumn();
                
                echo json_encode([
                    'success' => true, 
                    'msg' => 'Бонусы списаны!',
                    'new_balance_formatted' => number_format($new_balance / 100, 2) . ' BYN',
                    'new_balance_raw' => $new_balance
                ]);
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => 'Ошибка БД.']);
                exit;
            }
        }
    }
}

// --- ОДОБРЕНИЕ/ОТКЛОНЕНИЕ СКИДКИ ---
if (isset($_POST['handle_discount'])) {
    $inv_id = intval($_POST['inv_id']);
    $action = $_POST['action'] ?? '';

    if ($action == 'approve') {
        $st = $pdo->prepare("SELECT amount, requested_discount FROM invoices WHERE id = ? AND sender_id1 = ?");
        $st->execute([$inv_id, $u_id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        if ($row && $row['requested_discount'] > 0) {
            $pdo->prepare(
                "UPDATE invoices 
                 SET amount = amount - requested_discount,
                     requested_discount = 0,
                     discount_status = 'Одобрено'
                 WHERE id = ?"
            )->execute([$inv_id]);
            $msg = 'Скидка одобрена.';
        }

    } elseif ($action == 'reject') {
        $pdo->prepare(
            "UPDATE invoices 
             SET discount_status = 'Отклонено',
                 requested_discount = 0
             WHERE id = ?"
        )->execute([$inv_id]);
        $msg = 'Скидка отклонена.';
    }
    header("Location: index.php"); exit;
}

// --- СПИСАТЬ БОНУСЫ СЕЙЧАС (Оставляем для совместимости, если JS отключен) ---
if (isset($_POST['use_bonuses'])) {
    $inv_id = intval($_POST['inv_id']);
    $use_byn = floatval($_POST['bonuses_byn'] ?? 0);
    $use = intval(round($use_byn * 100));

    $invStmt = $pdo->prepare("SELECT * FROM invoices WHERE id = ? AND receiver_id1 = ?");
    $invStmt->execute([$inv_id, $u_id]);
    $invoice = $invStmt->fetch(PDO::FETCH_ASSOC);

    if (!$invoice) {
        $error = 'Счет не найден или доступ запрещен.';
    } elseif ($invoice['status'] == 'Оплачен') {
        $error = 'Счет уже оплачен.';
    } elseif ($use <= 0) {
        $error = 'Введите корректную сумму бонусов.';
    } else {
        $uStmt = $pdo->prepare("SELECT bonus_balance FROM users1 WHERE id = ?");
        $uStmt->execute([$u_id]);
        $uRow = $uStmt->fetch(PDO::FETCH_ASSOC);
        $balance = intval($uRow['bonus_balance'] ?? 0);

        if ($use > $balance) {
            $error = 'У вас недостаточно бонусов.';
        } else {
            $final_amount_byn = calculateFinalAmount($invoice);
            $final_cop = intval(round($final_amount_byn * 100));
            $use = min($use, $final_cop);

            try {
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE users1 SET bonus_balance = bonus_balance - ? WHERE id = ?")->execute([$use, $u_id]);
                $pdo->prepare("UPDATE invoices SET bonuses_spent = COALESCE(bonuses_spent,0) + ?, pending_status = ? WHERE id = ?")
                    ->execute([$use, 'Бонусы списаны', $inv_id]);
                $pdo->commit();
                $msg = 'Бонусы списаны.';
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'Ошибка при списании бонусов.';
            }
        }
    }
    header('Location: index.php'); exit;
}

// --- пользователь ---
$u_stmt = $pdo->prepare("SELECT bonus_balance, loyalty_level, card_number, temp_auth_code FROM users1 WHERE id = ?");
$u_stmt->execute([$u_id]);
$curr_user = $u_stmt->fetch(PDO::FETCH_ASSOC);

// --- счета ---
$sql_base = "SELECT i.*, u.username as contact_name,
            (SELECT GROUP_CONCAT(CONCAT(description, ' (', ROUND(original_amount, 2), ' ', currency, ')') SEPARATOR '\n')
            FROM invoice_items WHERE invoice_id = i.id) as items_summary
            FROM invoices i JOIN users1 u ON ";

$sent_stmt = $pdo->prepare($sql_base . "i.receiver_id1 = u.id WHERE i.sender_id1 = ? ORDER BY i.created_at DESC");
$sent_stmt->execute([$u_id]);
$sent_invoices = $sent_stmt->fetchAll(PDO::FETCH_ASSOC);

$recv_stmt = $pdo->prepare($sql_base . "i.sender_id1 = u.id WHERE i.receiver_id1 = ? ORDER BY i.created_at DESC");
$recv_stmt->execute([$u_id]);
$received_invoices = $recv_stmt->fetchAll(PDO::FETCH_ASSOC);

// Загружаем полные детали позиций для модала
$items_data = [];
foreach (array_merge($sent_invoices, $received_invoices) as $inv) {
    $itemsStmt = $pdo->prepare("SELECT description, original_amount, currency FROM invoice_items WHERE invoice_id = ? ORDER BY id");
    $itemsStmt->execute([$inv['id']]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
    $items_data[$inv['id']] = json_encode($items);
}

// --- ИСТОРИЯ ЧЕКОВ ---
$receipts_stmt = $pdo->prepare("
    SELECT r.*, i.invoice_number, u.username as partner_name
    FROM receipts r
    JOIN invoices i ON r.invoice_id = i.id
    JOIN users1 u ON (u.id = r.payer_id OR u.id = r.payee_id) AND u.id != ?
    WHERE r.payer_id = ? OR r.payee_id = ?
    ORDER BY r.created_at DESC
");
$receipts_stmt->execute([$u_id, $u_id, $u_id]);
$receipts = $receipts_stmt->fetchAll(PDO::FETCH_ASSOC);

// --- уведомления ---
$deadline_alerts = [];
$status_requests = [];
$new_invoice_alerts = [];
$discount_requests = [];

foreach ($received_invoices as $inv) {
    if ($inv['status'] == 'Новый') {
        $new_invoice_alerts[] = "🔔 Новый счет №<b>{$inv['invoice_number']}</b> от {$inv['contact_name']}";
    }
    if ($inv['status'] != 'Оплачен' && !empty($inv['due_date']) && strtotime($inv['due_date']) < strtotime($today)) {
        $deadline_alerts[] = "❌ Срок оплаты счета <b>{$inv['invoice_number']}</b> истек!";
    }
}

foreach ($sent_invoices as $inv) {
    if (!empty($inv['pending_status'])) {
        $status_requests[] = $inv;
    }
    if ($inv['discount_status'] == 'Ожидает') {
        $discount_requests[] = $inv;
    }
}

// --- суммы ---
$total_sent = 0;
foreach($sent_invoices as $i) {
    if($i['status']!='Оплачен' && $i['status']!='Отменен') {
        $total_sent += calculateFinalAmount($i);
    }
}

$total_recv = 0;
foreach($received_invoices as $i) {
    if($i['status']!='Оплачен' && $i['status']!='Отменен') {
        $total_recv += calculateFinalAmount($i);
    }
}

// --- статус ---

function getDiscountBadge($invoice) {
    if ($invoice['discount_status'] === 'Нет' || empty($invoice['discount_status'])) {
        return '';
    }
    $discount_amount = floatval($invoice['requested_discount']);
    $status_text = '';
    $status_class = '';
    if ($invoice['discount_status'] === 'Ожидает') {
        $status_text = '⏳ Ожидание';
        $status_class = 'badge-warning';
    } elseif ($invoice['discount_status'] === 'Одобрено') {
        $status_text = '✅ Одобрено';
        $status_class = 'badge-success';
    } elseif ($invoice['discount_status'] === 'Отклонено') {
        $status_text = '❌ Отклонено';
        $status_class = 'badge-danger';
    }
    return "<span class='badge {$status_class} mt-1'>Скидка: -" . number_format($discount_amount,2) . " BYN ({$status_text})</span>";
}

include 'header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-5 mt-2">
    <div>
        <h2 class="fw-bold mb-0">Долговая Кобала</h2>
        <p class="text-muted small mb-0">Управление взаиморасчетами</p>
    </div>
    <a href="create_invoice.php" class="btn btn-primary btn-lg shadow px-4">✨ Выставить счет</a>
</div>

<div id="ajax-alerts"></div>
<?php if($msg): ?><div class="alert alert-success"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>
<?php if($error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

<!-- УВЕДОМЛЕНИЯ (ЗАГРУЖАЮТСЯ ЧЕРЕЗ AJAX) -->
<div id="notifications-container">
    <?php include 'api_get_notifications.php'; ?>
</div>

<!-- СТАТИСТИКА -->
<div class="row mb-5 g-4 text-center">
    <div class="col-md-4">
        <div class="card p-4 shadow-sm border-0 rounded-4" style="background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);">
            <div class="text-muted small fw-bold">💰 Сколько мне должны</div>
            <h3 class="text-success fw-bold m-0" id="stat-total-sent"><?php echo number_format($total_sent, 2); ?> BYN</h3>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card p-4 shadow-sm border-0 rounded-4" style="background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);">
            <div class="text-muted small fw-bold">📊 Я должен</div>
            <h3 class="text-danger fw-bold m-0" id="stat-total-recv"><?php echo number_format($total_recv, 2); ?> BYN</h3>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card p-4 shadow-sm border-0 rounded-4" style="background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);">
            <div class="text-muted small fw-bold">🎁 Бонусы</div>
            <h3 class="text-warning fw-bold m-0" id="bonus-balance-display"><?php echo number_format((floatval($curr_user['bonus_balance'] ?? 0) / 100), 2); ?> BYN</h3>
        </div>
    </div>
</div>

<!-- ВЫСТАВЛЕННЫЕ СЧЕТА -->
<div class="card bg-white shadow-sm border-0 mb-5 overflow-hidden rounded-4">
    <div class="card-header bg-dark text-white p-3 fw-bold">📤 Сколько я сдер денег (<?php echo count($sent_invoices); ?>)</div>
    <div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead class="table-light small"><tr><th>№</th><th>ЗАКАЗЧИК</th><th>СОДЕРЖАНИЕ</th><th>ИСХОДНАЯ</th><th>СКИДКА</th><th>К ОПЛАТЕ</th><th>СРОК</th><th>СТАТУС</th><th>ДЕЙСТВИЯ</th></tr></thead>
        <tbody id="sent-invoices-tbody">
            <?php foreach($sent_invoices as $i):
                $original = floatval($i['amount']);
                $discount_amount = (($i['discount_status'] ?? '') === 'Одобрено' && !empty($i['requested_discount'])) ? floatval($i['requested_discount']) : 0;
                $final = calculateFinalAmount($i);
            ?>
            <tr class="<?php echo getStatusColor($i['status']); ?> bg-opacity-10">
                <td><code class="fw-bold"><?php echo safeGet($i, 'invoice_number'); ?></code></td>
                <td><b><?php echo safeGet($i, 'contact_name'); ?></b></td>
                <td style="max-width:300px;">
                    <div class="invoice-items-preview">
                        <small class="text-dark d-block mb-1"><?php echo nl2br(htmlspecialchars($i['items_summary'] ?? 'Нет позиций')); ?></small>
                        <?php if(!empty($items_data[$i['id']])): ?>
                        <button type="button" class="btn btn-sm btn-link p-0 toggle-items-details" data-items='<?php echo htmlspecialchars($items_data[$i['id']]); ?>'>
                            📋 Показать полностью
                        </button>
                        <?php endif; ?>
                    </div>
                </td>
                <td><span class="text-muted"><?php echo number_format($original, 2); ?> BYN</span></td>
                <td><?php echo ($discount_amount > 0) ? '<span class="badge bg-success">-'.number_format($discount_amount, 2).'</span>' : '<span class="text-muted small">—</span>'; ?></td>
                <td><b><?php echo number_format($final, 2); ?> BYN</b></td>
                <td>
                    <?php if(!empty($i['due_date'])): 
                        $is_overdue = (strtotime($i['due_date']) < strtotime($today)) && ($i['status'] != 'Оплачен');
                    ?>
                        <span class="<?php echo $is_overdue ? 'text-danger fw-bold' : 'text-muted'; ?>"><?php echo date('d.m.Y', strtotime($i['due_date'])); ?></span>
                    <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                </td>
                <td>
                    <form action="update_status.php" method="POST" class="d-inline">
                        <input type="hidden" name="id" value="<?php echo intval($i['id']); ?>">
                        <select name="status" onchange="this.form.submit()" class="form-select form-select-sm fw-bold border-0 shadow-none" style="max-width: 140px;">
                            <?php foreach(['Новый','В обработке','Оплачен','Отменен'] as $s) echo "<option " . ($i['status'] == $s ? 'selected' : '') . ">$s</option>"; ?>
                        </select>
                    </form>
                    <?php echo getDiscountBadge($i); ?>
                </td>
                <td>
                    <div class="btn-group btn-group-sm" role="group">
                        <a href="print_invoice.php?id=<?php echo intval($i['id']); ?>" class="btn btn-outline-primary" target="_blank" title="Печать">🖨️</a>
                        <a href="edit_invoice.php?id=<?php echo intval($i['id']); ?>" class="btn btn-outline-warning" title="Редактировать">✏️</a>
                        <a href="delete_invoice.php?id=<?php echo intval($i['id']); ?>" class="btn btn-outline-danger" onclick="return confirm('Удалить счет?')" title="Удалить">✕</a>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table></div>
</div>

<!-- ПОЛУЧЕННЫЕ СЧЕТА -->
<div class="card bg-white shadow-sm border-0 mb-5 overflow-hidden rounded-4">
    <div class="card-header bg-primary text-white p-3 fw-bold">📥 Сколько я должен (<?php echo count($received_invoices); ?>)</div>
    <div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead class="table-light small"><tr><th>№</th><th>ИСПОЛНИТЕЛЬ</th><th>СОДЕРЖАНИЕ</th><th>ИСХОДНАЯ</th><th>СКИДКА / БОНУСЫ</th><th>К ОПЛАТЕ</th><th>СРОК</th><th>ДЕЙСТВИЕ</th><th>ПЕЧАТЬ</th></tr></thead>
        <tbody id="received-invoices-tbody">
            <?php 
            foreach($received_invoices as $i):
                $original = floatval($i['amount']);
                $discount_amount = (($i['discount_status'] ?? '') === 'Одобрено' && !empty($i['requested_discount'])) ? floatval($i['requested_discount']) : 0;
                $bonus_spent = (floatval($i['bonuses_spent'] ?? 0) / 100);
                $final = calculateFinalAmount($i);
            ?>
            <tr class="<?php echo getStatusColor($i['status']); ?> bg-opacity-10" id="invoice-row-<?php echo $i['id']; ?>">
                <td><code class="fw-bold"><?php echo safeGet($i, 'invoice_number'); ?></code></td>
                <td><b><?php echo safeGet($i, 'contact_name'); ?></b></td>
                <td style="max-width:300px;">
                    <div class="invoice-items-preview">
                        <small class="text-dark d-block mb-1"><?php echo nl2br(htmlspecialchars($i['items_summary'] ?? 'Нет позиций')); ?></small>
                        <?php if(!empty($items_data[$i['id']])): ?>
                        <button type="button" class="btn btn-sm btn-link p-0 toggle-items-details" data-items='<?php echo htmlspecialchars($items_data[$i['id']]); ?>'>
                            📋 Показать полностью
                        </button>
                        <?php endif; ?>
                    </div>
                </td>
                <td><span class="text-muted"><?php echo number_format($original, 2); ?> BYN</span></td>
                <td class="discount-bonus-cell">
                    <div class="small">
                        <?php if($discount_amount > 0): ?><span class="badge bg-success d-block mb-1">Скидка: -<?php echo number_format($discount_amount, 2); ?></span><?php endif; ?>
                        <span class="bonus-spent-badge"><?php if($bonus_spent > 0): ?><span class="badge bg-warning">Бонусы: -<?php echo number_format($bonus_spent, 2); ?></span><?php endif; ?></span>
                        <?php if($discount_amount === 0 && $bonus_spent === 0): ?><span class="no-discounts text-muted">—</span><?php endif; ?>
                    </div>
                </td>
                <td><b class="text-primary fs-5 final-amount-cell"><?php echo number_format($final, 2); ?> BYN</b></td>
                <td>
                    <?php if(!empty($i['due_date'])): 
                        $is_overdue = (strtotime($i['due_date']) < strtotime($today)) && ($i['status'] != 'Оплачен');
                    ?>
                        <span class="<?php echo $is_overdue ? 'text-danger fw-bold' : 'text-muted'; ?>"><?php echo date('d.m.Y', strtotime($i['due_date'])); ?></span>
                    <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                </td>
                <td>
                    <div class="mb-2">
                        <div class="status-container">
                            <?php if($i['status'] != 'Оплачен' && empty($i['pending_status'])): ?>
                            <?php else: ?>
                                <span class="badge bg-light text-dark border w-100 p-2 mb-2 pending-status-badge"><?php echo safeGet($i, 'pending_status') ?: safeGet($i, 'status'); ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if($i['status'] != 'Оплачен' && $final > 0): ?>
                            <form method="POST" class="d-flex flex-column gap-2 ajax-use-bonuses">
                                <div class="input-group input-group-sm">
                                    <input type="hidden" name="inv_id" value="<?php echo intval($i['id']); ?>">
                                    <input type="number" name="bonuses_byn" step="0.01" min="0.01" max="<?php echo number_format($final, 2); ?>" class="form-control bonus-input" placeholder="Списать (BYN)" title="Введите сумму бонусов для списания">
                                    <button type="submit" name="use_bonuses" class="btn btn-warning fw-bold">🎁 Списать</button>
                                </div>
                                <small class="text-muted">Доступно: <span class="bonus-available-display"><?php echo number_format((floatval($curr_user['bonus_balance'] ?? 0) / 100), 2); ?></span> BYN</small>
                            </form>
                        <?php endif; ?>
                    </div>
                    <form action="update_status.php" method="POST" class="d-inline w-100">
                        <input type="hidden" name="id" value="<?php echo intval($i['id']); ?>">
                        <select name="status" onchange="this.form.submit()" class="form-select form-select-sm small border-0 shadow-none">
                            <?php foreach(['Новый','В обработке','Оплачен'] as $s) echo "<option " . ($i['status'] == $s ? 'selected' : '') . ">$s</option>"; ?>
                        </select>
                    </form>
                </td>
                <td><a href="print_invoice.php?id=<?php echo intval($i['id']); ?>" class="btn btn-sm btn-dark px-3" target="_blank" title="Печать">🖨️</a></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table></div>
</div>

<style>
.invoice-items-preview {
    position: relative;
}

.toggle-items-details {
    color: #0d6efd;
    text-decoration: none;
    font-size: 0.85rem;
    cursor: pointer;
}

.toggle-items-details:hover {
    text-decoration: underline;
}

.items-modal-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.5);
    z-index: 1050;
}

.items-modal-overlay.active {
    display: flex;
    align-items: center;
    justify-content: center;
}

.items-modal-content {
    background: white;
    border-radius: 8px;
    padding: 20px;
    max-width: 600px;
    width: 90%;
    max-height: 80vh;
    overflow-y: auto;
    box-shadow: 0 4px 20px rgba(0,0,0,0.3);
    position: relative;
    animation: slideUp 0.3s ease-out;
}

@keyframes slideUp {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.items-modal-content h5 {
    margin-bottom: 15px;
    border-bottom: 2px solid #0d6efd;
    padding-bottom: 10px;
    padding-right: 30px;
}

.items-list-full {
    list-style: none;
    padding: 0;
}

.items-list-full li {
    padding: 12px;
    border-left: 3px solid #0d6efd;
    margin-bottom: 8px;
    background: #f8f9fa;
    border-radius: 4px;
}

.items-list-full li strong {
    color: #0d6efd;
}

.modal-close-btn {
    position: absolute;
    top: 10px;
    right: 10px;
    background: none;
    border: none;
    font-size: 24px;
    cursor: pointer;
    color: #666;
    padding: 0;
}

.modal-close-btn:hover {
    color: #000;
}
</style>

<div id="items-modal" class="items-modal-overlay">
    <div class="items-modal-content">
        <button type="button" class="modal-close-btn" onclick="closeItemsModal()">✕</button>
        <h5>📋 Полный список позиций счета</h5>
        <ul class="items-list-full" id="items-list-content"></ul>
    </div>
</div>

<script>
function showItemsModal(itemsJson) {
    try {
        const items = JSON.parse(itemsJson);
        const listContainer = document.getElementById('items-list-content');
        listContainer.innerHTML = '';
        
        if (Array.isArray(items) && items.length > 0) {
            items.forEach(item => {
                const li = document.createElement('li');
                li.innerHTML = `<strong>${item.description}</strong> ��� ${parseFloat(item.original_amount).toFixed(2)} ${item.currency}`;
                listContainer.appendChild(li);
            });
        } else {
            listContainer.innerHTML = '<li>Нет данных о позициях</li>';
        }
        
        document.getElementById('items-modal').classList.add('active');
    } catch (e) {
        console.error('Ошибка парсинга данных:', e);
        alert('Ошибка при загрузке деталей счета');
    }
}

function closeItemsModal() {
    document.getElementById('items-modal').classList.remove('active');
}

document.addEventListener('DOMContentLoaded', function() {
    // Обработка кнопок показа деталей счета
    document.querySelectorAll('.toggle-items-details').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const itemsJson = this.getAttribute('data-items');
            showItemsModal(itemsJson);
        });
    });

    // Закрытие модального окна по клику на фон
    document.getElementById('items-modal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeItemsModal();
        }
    });

    // Закрытие по Escape
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeItemsModal();
        }
    });

    // 1. Поллинг данных (бонусы, уведомления) каждые 3 секунды
    setInterval(function() {
        // Обновляем бонусы
        fetch('api_get_bonuses.php')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const display = document.getElementById('bonus-balance-display');
                    if (display) {
                        display.innerText = data.bonus_balance_formatted;
                        // Обновляем доступные бонусы во всех формах
                        document.querySelectorAll('.bonus-available-display').forEach(el => {
                            el.innerText = data.bonus_balance_formatted;
                        });
                    }
                }
            });

        // Обновляем уведомления
        fetch('api_get_notifications.php')
            .then(response => response.text())
            .then(html => {
                document.getElementById('notifications-container').innerHTML = html;
            });
    }, 3000);

    // 2. Обработка списания бонусов через AJAX
    function attachBonusFormHandler(form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            formData.append('use_bonuses_ajax', '1');
            formData.append('use_bonuses', '1');
            
            const btn = this.querySelector('button');
            const originalBtnText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '⏳...';

            fetch('index.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                btn.disabled = false;
                btn.innerHTML = originalBtnText;

                if (data.success) {
                    document.getElementById('bonus-balance-display').innerText = data.new_balance_formatted;
                    document.querySelectorAll('.bonus-available-display').forEach(el => {
                        el.innerText = data.new_balance_formatted;
                    });
                    
                    const alerts = document.getElementById('ajax-alerts');
                    alerts.innerHTML = `<div class="alert alert-success alert-dismissible fade show" role="alert">
                        ✅ ${data.msg}
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>`;

                    // Обновляем элементы строки
                    const row = form.closest('tr');
                    const bonusesByn = parseFloat(formData.get('bonuses_byn'));
                    
                    const finalAmountCell = row.querySelector('.final-amount-cell');
                    const currentFinal = parseFloat(finalAmountCell.innerText.replace(/[^\d.]/g, ''));
                    const newFinal = Math.max(0, currentFinal - bonusesByn);
                    finalAmountCell.innerText = newFinal.toFixed(2) + ' BYN';

                    const statTotalRecv = document.getElementById('stat-total-recv');
                    const currentTotalRecv = parseFloat(statTotalRecv.innerText.replace(/[^\d.]/g, ''));
                    const newTotalRecv = Math.max(0, currentTotalRecv - bonusesByn);
                    statTotalRecv.innerText = newTotalRecv.toFixed(2) + ' BYN';

                    const badgeContainer = row.querySelector('.bonus-spent-badge');
                    badgeContainer.innerHTML = `<span class="badge bg-warning">Бонусы: -${bonusesByn.toFixed(2)}</span>`;
                    
                    const noDiscounts = row.querySelector('.no-discounts');
                    if (noDiscounts) noDiscounts.remove();

                    const statusContainer = row.querySelector('.status-container');
                    statusContainer.innerHTML = `<span class="badge bg-light text-dark border w-100 p-2 mb-2 pending-status-badge">Бонусы списаны</span>`;

                    if (newFinal <= 0) {
                        form.closest('.mb-2').remove();
                    } else {
                        const input = form.querySelector('.bonus-input');
                        input.value = '';
                        input.focus();
                    }
                } else {
                    alert('❌ ' + (data.error || 'Ошибка при списании'));
                }
            })
            .catch(error => {
                btn.disabled = false;
                btn.innerHTML = originalBtnText;
                console.error('Error:', error);
                alert('❌ Ошибка соединения');
            });
        });
    }

    // Привязываем обработчики к существующим формам
    document.querySelectorAll('.ajax-use-bonuses').forEach(attachBonusFormHandler);
});
</script>

<!-- ИСТОРИЯ ЧЕКОВ -->
<div class="card bg-white shadow-sm border-0 mb-5 overflow-hidden rounded-4">
    <div class="card-header bg-dark text-white p-3 fw-bold d-flex justify-content-between align-items-center">
        <span>🧾 История чеков (<?php echo count($receipts); ?>)</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light small">
                <tr>
                    <th>№ ЧЕКА</th>
                    <th>ДАТА</th>
                    <th>КОНТРАГЕНТ</th>
                    <th>СЧЕТ</th>
                    <th>СУММА</th>
                    <th>БОНУСЫ</th>
                    <th>ДЕЙСТВИЕ</th>
                </tr>
            </thead>
            <tbody>
                <?php if(empty($receipts)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">История платежей пуста</td></tr>
                <?php endif; ?>
                <?php foreach($receipts as $rcp): 
                    $is_payer = ($rcp['payer_id'] == $u_id);
                ?>
                <tr>
                    <td><code class="fw-bold"><?php echo htmlspecialchars($rcp['receipt_number']); ?></code></td>
                    <td class="small text-muted"><?php echo date('d.m.Y H:i', strtotime($rcp['created_at'])); ?></td>
                    <td>
                        <span class="badge <?php echo $is_payer ? 'bg-light text-danger' : 'bg-light text-success'; ?> me-1">
                            <?php echo $is_payer ? 'Оплата' : 'Приход'; ?>
                        </span>
                        <b><?php echo htmlspecialchars($rcp['partner_name']); ?></b>
                    </td>
                    <td><small class="text-muted">Счет №<?php echo htmlspecialchars($rcp['invoice_number']); ?></small></td>
                    <td>
                        <b class="<?php echo $is_payer ? 'text-danger' : 'text-success'; ?>">
                            <?php echo $is_payer ? '-' : '+'; ?><?php echo number_format($rcp['amount_paid'], 2); ?> BYN
                        </b>
                    </td>
                    <td>
                        <?php if($rcp['bonuses_spent'] > 0 && $is_payer): ?>
                            <span class="text-warning small" title="Оплачено бонусами">-<?php echo number_format($rcp['bonuses_spent']/100, 2); ?></span>
                        <?php endif; ?>
                        <?php if($rcp['bonuses_earned'] > 0 && !$is_payer): ?>
                            <span class="text-info small" title="Начислено бонусов">+<?php echo number_format($rcp['bonuses_earned']/100, 2); ?></span>
                        <?php endif; ?>
                        <?php if($rcp['bonuses_spent'] == 0 && $rcp['bonuses_earned'] == 0): ?>—<?php endif; ?>
                    </td>
                    <td>
                        <div class="btn-group">
                            <a href="view_receipt.php?id=<?php echo $rcp['id']; ?>" class="btn btn-sm btn-outline-dark px-3 rounded-pill">
                                👁️ Посмотреть
                            </a>
                            <a href="delete_receipt.php?id=<?php echo $rcp['id']; ?>" class="btn btn-sm btn-outline-danger px-2 ms-1 rounded-pill" onclick="return confirm('Удалить запись о чеке из истории?')">
                                ✕
                            </a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include 'footer.php'; ?>
