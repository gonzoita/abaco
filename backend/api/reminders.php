<?php
// C:\laragon\www\control-finanzas\backend\api\reminders.php
require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth_helper.php';

$userData = authenticate();
$userId = $userData['user_id'];
$db = Database::getConnection();

$method = $_SERVER['REQUEST_METHOD'];
$id = isset($_GET['id']) ? trim($_GET['id']) : null;

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

if ($method === 'GET') {
    try {
        // 1. Obtener la configuración de recordatorios del usuario (días de anticipación)
        $stmtUser = $db->prepare("SELECT reminder_days_before FROM users WHERE id = ?");
        $stmtUser->execute([$userId]);
        $userObj = $stmtUser->fetch();
        $daysBefore = $userObj ? intval($userObj['reminder_days_before']) : 5;

        // 2. Obtener recordatorios personalizados (físicos en base de datos)
        $stmtCustom = $db->prepare("SELECT * FROM reminders WHERE user_id = ? AND status = 'pendiente' ORDER BY due_date ASC");
        $stmtCustom->execute([$userId]);
        $customReminders = $stmtCustom->fetchAll();
        foreach ($customReminders as &$cr) {
            $cr['type'] = 'personalizado';
            $cr['amount'] = 0.00;
        }

        // 3. Generar recordatorios virtuales de Tarjetas de Crédito con deuda pendiente
        $stmtCards = $db->prepare("SELECT id, name, due_day, balance, currency FROM accounts WHERE user_id = ? AND type = 'tarjeta_credito'");
        $stmtCards->execute([$userId]);
        $cards = $stmtCards->fetchAll();

        $cardReminders = [];
        $currentDay = intval(date('d'));
        $currentMonth = intval(date('m'));
        $currentYear = intval(date('Y'));

        foreach ($cards as $card) {
            if (empty($card['due_day'])) continue;
            
            // La deuda se guarda como saldo negativo en la DB
            $debt = abs(floatval($card['balance']));
            if ($debt <= 0) continue; // Si la tarjeta no tiene saldo por pagar, omitir

            $dueDay = intval($card['due_day']);
            $dueMonth = $currentMonth;
            $dueYear = $currentYear;

            // Si el día actual superó la fecha de pago del mes, el vencimiento es el siguiente mes
            if ($currentDay > $dueDay) {
                $dueMonth++;
                if ($dueMonth > 12) {
                    $dueMonth = 1;
                    $dueYear++;
                }
            }

            $dueDateStr = sprintf("%04d-%02d-%02d", $dueYear, $dueMonth, $dueDay);
            $dueTimestamp = strtotime($dueDateStr . ' 00:00:00');
            $diffDays = ceil(($dueTimestamp - time()) / (60 * 60 * 24));

            if ($diffDays <= $daysBefore) {
                $cardReminders[] = [
                    "id" => "card-" . $card['id'],
                    "user_id" => $userId,
                    "title" => "Pago Tarjeta: " . $card['name'],
                    "description" => "Fecha límite de pago. Deuda acumulada: " . number_format($debt) . " " . $card['currency'],
                    "due_date" => $dueDateStr,
                    "status" => "pendiente",
                    "type" => "tarjeta",
                    "amount" => $debt,
                    "account_id" => $card['id']
                ];
            }
        }

        // 4. Generar recordatorios virtuales de Servicios / Suscripciones Recurrentes
        $stmtRecur = $db->prepare("
            SELECT r.*, c.name as category_name 
            FROM recurrences r 
            JOIN categories c ON r.category_id = c.id 
            WHERE r.user_id = ? AND r.active = 1
        ");
        $stmtRecur->execute([$userId]);
        $recurrences = $stmtRecur->fetchAll();

        $recurReminders = [];
        foreach ($recurrences as $rec) {
            $dueDateStr = $rec['next_due_date'];
            $dueTimestamp = strtotime($dueDateStr . ' 00:00:00');
            $diffDays = ceil(($dueTimestamp - time()) / (60 * 60 * 24));

            if ($diffDays <= $daysBefore) {
                $recurReminders[] = [
                    "id" => "recur-" . $rec['id'],
                    "user_id" => $userId,
                    "title" => "Servicio / Pago Recurrente",
                    "description" => "Pago programado para: " . $rec['description'],
                    "due_date" => $dueDateStr,
                    "status" => "pendiente",
                    "type" => "servicio",
                    "amount" => floatval($rec['amount']),
                    "category_name" => $rec['category_name'],
                    "recurrence_id" => $rec['id']
                ];
            }
        }

        // Unir todo en un solo listado ordenado
        $allReminders = array_merge($customReminders, $cardReminders, $recurReminders);
        
        // Ordenar por fecha de vencimiento ascendente
        usort($allReminders, function($a, $b) {
            return strcmp($a['due_date'], $b['due_date']);
        });

        echo json_encode($allReminders);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => "Error al obtener recordatorios: " . $e->getMessage()]);
    }
    exit();
}

if ($method === 'POST') {
    $title = trim($input['title'] ?? '');
    $description = trim($input['description'] ?? '');
    $dueDate = trim($input['due_date'] ?? '');

    if (empty($title) || empty($dueDate)) {
        http_response_code(400);
        echo json_encode(["error" => "El título del recordatorio y la fecha de vencimiento son obligatorios."]);
        exit();
    }

    try {
        $stmt = $db->prepare("INSERT INTO reminders (user_id, title, description, due_date, status) VALUES (?, ?, ?, ?, 'pendiente')");
        $stmt->execute([$userId, $title, $description, $dueDate]);
        
        $newId = $db->lastInsertId();
        echo json_encode([
            "message" => "Recordatorio creado exitosamente.",
            "reminder" => [
                "id" => $newId,
                "title" => $title,
                "description" => $description,
                "due_date" => $dueDate,
                "status" => "pendiente"
            ]
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => "Error al guardar el recordatorio: " . $e->getMessage()]);
    }
    exit();
}

if ($method === 'PUT') {
    if (!$id) {
        http_response_code(400);
        echo json_encode(["error" => "Falta especificar el ID del recordatorio."]);
        exit();
    }

    try {
        // Si es un recordatorio físico en DB
        if (is_numeric($id)) {
            $stmt = $db->prepare("UPDATE reminders SET status = 'completado' WHERE id = ? AND user_id = ?");
            $stmt->execute([$id, $userId]);
            echo json_encode(["message" => "Recordatorio completado."]);
        } else {
            // Si es un recordatorio virtual (ej. de tarjeta de crédito o servicio recurrente),
            // lo marcamos simulado o creamos la transacción en su lugar.
            // Para servicios recurrentes, podemos actualizar la fecha del próximo pago `next_due_date`.
            if (strpos($id, 'recur-') === 0) {
                $recurId = intval(substr($id, 6));
                
                $stmtRec = $db->prepare("SELECT next_due_date, frequency FROM recurrences WHERE id = ? AND user_id = ?");
                $stmtRec->execute([$recurId, $userId]);
                $rec = $stmtRec->fetch();

                if ($rec) {
                    $nextDueDate = $rec['next_due_date'];
                    // Calcular el próximo vencimiento según la frecuencia
                    switch ($rec['frequency']) {
                        case 'semanal': $next = '+1 week'; break;
                        case 'quincenal': $next = '+2 weeks'; break;
                        case 'anual': $next = '+1 year'; break;
                        case 'diario': $next = '+1 day'; break;
                        case 'mensual':
                        default: $next = '+1 month'; break;
                    }
                    $newDueDate = date('Y-m-d', strtotime($next, strtotime($nextDueDate)));
                    
                    $stmtUp = $db->prepare("UPDATE recurrences SET next_due_date = ? WHERE id = ?");
                    $stmtUp->execute([$newDueDate, $recurId]);
                    
                    echo json_encode(["message" => "Vencimiento recurrente actualizado al: " . $newDueDate]);
                } else {
                    throw new Exception("Configuración recurrente no encontrada.");
                }
            } else {
                echo json_encode(["message" => "Recordatorio procesado localmente."]);
            }
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => "Error al actualizar el recordatorio: " . $e->getMessage()]);
    }
    exit();
}

http_response_code(405);
echo json_encode(["error" => "Método HTTP no permitido."]);
