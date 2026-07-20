<?php
// C:\laragon\www\control-finanzas\backend\api\export_report.php
require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth_helper.php';

$userData = authenticate();
$userId = $userData['user_id'];
$db = Database::getConnection();

$format = isset($_GET['format']) ? $_GET['format'] : 'html';
$month = isset($_GET['month']) ? intval($_GET['month']) : intval(date('m'));
$year = isset($_GET['year']) ? intval($_GET['year']) : intval(date('Y'));

// Obtener datos del usuario
$stmtUser = $db->prepare("SELECT name, email, currency FROM users WHERE id = ?");
$stmtUser->execute([$userId]);
$user = $stmtUser->fetch();
$userName = $user['name'] ?? 'Usuario';
$currency = $user['currency'] ?? 'COP';

// Transacciones del mes solicitado
$stmtTx = $db->prepare("
    SELECT t.date, t.type, t.amount, t.description, t.tags, c.name as category_name, a.name as account_name
    FROM transactions t
    LEFT JOIN categories c ON t.category_id = c.id
    LEFT JOIN accounts a ON t.account_id = a.id
    WHERE t.user_id = ? AND MONTH(t.date) = ? AND YEAR(t.date) = ?
    ORDER BY t.date DESC
");
$stmtTx->execute([$userId, $month, $year]);
$transactions = $stmtTx->fetchAll();

// Cuentas del usuario
$stmtAccounts = $db->prepare("SELECT name, type, balance FROM accounts WHERE user_id = ?");
$stmtAccounts->execute([$userId]);
$accounts = $stmtAccounts->fetchAll();

// Calcular ingresos y egresos
$totalIncome = 0;
$totalExpense = 0;
foreach ($transactions as $tx) {
    if ($tx['type'] === 'ingreso') $totalIncome += floatval($tx['amount']);
    if ($tx['type'] === 'egreso') $totalExpense += floatval($tx['amount']);
}
$netSavings = $totalIncome - $totalExpense;

$monthNames = [1=>'Enero', 2=>'Febrero', 3=>'Marzo', 4=>'Abril', 5=>'Mayo', 6=>'Junio', 7=>'Julio', 8=>'Agosto', 9=>'Septiembre', 10=>'Octubre', 11=>'Noviembre', 12=>'Diciembre'];
$monthLabel = ($monthNames[$month] ?? '') . " " . $year;

// Exportar CSV
if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="reporte_abaco_' . $year . '_' . $month . '.csv"');
    
    $output = fopen('php://output', 'w');
    // BOM para UTF-8 en Excel
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    fputcsv($output, ['Reporte Financiero Ábaco', $monthLabel]);
    fputcsv($output, ['Usuario:', $userName]);
    fputcsv($output, ['Moneda:', $currency]);
    fputcsv($output, []);
    fputcsv($output, ['Fecha', 'Tipo', 'Categoría', 'Descripción', 'Etiquetas (#Tags)', 'Cuenta', 'Monto']);
    
    foreach ($transactions as $tx) {
        fputcsv($output, [
            $tx['date'],
            strtoupper($tx['type']),
            $tx['category_name'] ?: 'Sin Categoría',
            $tx['description'],
            $tx['tags'] ?: '',
            $tx['account_name'],
            $tx['amount']
        ]);
    }
    
    fputcsv($output, []);
    fputcsv($output, ['RESUMEN DEL MES']);
    fputcsv($output, ['Total Ingresos', $totalIncome]);
    fputcsv($output, ['Total Egresos', $totalExpense]);
    fputcsv($output, ['Ahorro Neto', $netSavings]);
    fclose($output);
    exit();
}

// Exportar HTML imprimible ejecutivo para PDF
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reporte Financiero Ábaco - <?php echo $monthLabel; ?></title>
    <style>
        body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; color: #1c1c1e; background: #fff; margin: 0; padding: 40px; }
        .header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #007aff; padding-bottom: 20px; margin-bottom: 30px; }
        .logo { font-size: 28px; font-weight: 800; color: #007aff; text-transform: uppercase; letter-spacing: 1px; }
        .subtitle { font-size: 14px; color: #6e6e73; margin-top: 4px; }
        .summary-cards { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 30px; }
        .card { background: #f2f2f7; padding: 16px; border-radius: 12px; border: 1px solid #e5e5ea; }
        .card-label { font-size: 12px; color: #6e6e73; text-transform: uppercase; font-weight: 600; margin-bottom: 6px; }
        .card-val { font-size: 22px; font-weight: 700; }
        .income-color { color: #2fa84e; }
        .expense-color { color: #ff3b30; }
        .savings-color { color: #007aff; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; font-size: 13px; }
        th { background: #f2f2f7; color: #1c1c1e; text-align: left; padding: 10px 12px; font-weight: 700; border-bottom: 2px solid #d1d1d6; }
        td { padding: 10px 12px; border-bottom: 1px solid #e5e5ea; }
        .tag-pill { background: #e5e5ea; padding: 2px 6px; border-radius: 4px; font-size: 11px; color: #3a3a3c; display: inline-block; margin-right: 4px; }
        .footer { margin-top: 40px; text-align: center; font-size: 12px; color: #8e8e93; border-top: 1px solid #e5e5ea; padding-top: 20px; }
        @media print {
            body { padding: 0; }
            .no-print { display: none; }
        }
    </style>
</head>
<body>
    <div class="no-print" style="margin-bottom: 20px; text-align: right;">
        <button onclick="window.print()" style="background:#007aff; color:#fff; border:none; padding:10px 20px; border-radius:8px; font-weight:bold; cursor:pointer;">
            🖨️ Imprimir / Guardar en PDF
        </button>
    </div>

    <div class="header">
        <div>
            <div class="logo">Ábaco Finanzas</div>
            <div class="subtitle">Reporte Ejecutivo de Control Financiero</div>
        </div>
        <div style="text-align: right;">
            <strong style="font-size: 16px;"><?php echo htmlspecialchars($userName); ?></strong><br>
            <span class="subtitle">Período: <?php echo $monthLabel; ?></span>
        </div>
    </div>

    <div class="summary-cards">
        <div class="card">
            <div class="card-label">Total Ingresos</div>
            <div class="card-val income-color">+ <?php echo number_format($totalIncome, 2) . " " . $currency; ?></div>
        </div>
        <div class="card">
            <div class="card-label">Total Egresos</div>
            <div class="card-val expense-color">- <?php echo number_format($totalExpense, 2) . " " . $currency; ?></div>
        </div>
        <div class="card">
            <div class="card-label">Ahorro Neto del Mes</div>
            <div class="card-val savings-color"><?php echo number_format($netSavings, 2) . " " . $currency; ?></div>
        </div>
    </div>

    <h3>Desglose de Movimientos (<?php echo count($transactions); ?> transacciones)</h3>
    <table>
        <thead>
            <tr>
                <th>Fecha</th>
                <th>Tipo</th>
                <th>Categoría</th>
                <th>Descripción / Etiquetas</th>
                <th>Cuenta</th>
                <th style="text-align: right;">Monto (<?php echo $currency; ?>)</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($transactions as $tx): ?>
            <tr>
                <td><?php echo $tx['date']; ?></td>
                <td>
                    <strong class="<?php echo $tx['type'] === 'ingreso' ? 'income-color' : ($tx['type'] === 'egreso' ? 'expense-color' : ''); ?>">
                        <?php echo strtoupper($tx['type']); ?>
                    </strong>
                </td>
                <td><?php echo htmlspecialchars($tx['category_name'] ?: 'Sin Categoría'); ?></td>
                <td>
                    <?php echo htmlspecialchars($tx['description']); ?>
                    <?php if (!empty($tx['tags'])): ?>
                        <br>
                        <?php foreach (explode(',', $tx['tags']) as $t): ?>
                            <span class="tag-pill"><?php echo htmlspecialchars(trim($t)); ?></span>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </td>
                <td><?php echo htmlspecialchars($tx['account_name'] ?: '-'); ?></td>
                <td style="text-align: right; font-weight: bold;">
                    <?php echo ($tx['type'] === 'egreso' ? '-' : '+') . number_format($tx['amount'], 2); ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="footer">
        Generado automáticamente por <strong>Ábaco Control Financiero IA</strong> &bull; <?php echo date('d/m/Y H:i'); ?>
    </div>
</body>
</html>
