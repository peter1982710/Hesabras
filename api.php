<?php
// Display errors for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/bot_errors.log');

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

$baseDir = __DIR__;
$dbFile = $baseDir . '/database.json';

// Ensure JSON DB exists (used for bootstrapping settings)
if (!file_exists($dbFile)) {
    $initialData = ["users" => [], "settings" => []];
    file_put_contents($dbFile, json_encode($initialData));
}

function getJsonDB() {
    global $dbFile;
    return json_decode(file_get_contents($dbFile), true) ?? ["users" => [], "settings" => []];
}

function getDB() {
    $data = getJsonDB();
    
    // Check if MySQL is configured
    if (!empty($data['settings']['dbName']) && !empty($data['settings']['dbUser'])) {
        $conn = new mysqli('localhost', $data['settings']['dbUser'], $data['settings']['dbPassword'], $data['settings']['dbName']);
        if (!$conn->connect_error) {
            $conn->set_charset("utf8");
            
            // Create Table if not exists
            $conn->query("CREATE TABLE IF NOT EXISTS users (
                id VARCHAR(50) PRIMARY KEY,
                name VARCHAR(255),
                telegramId VARCHAR(50),
                purchasedGb FLOAT,
                consumedGb FLOAT,
                purchaseDate VARCHAR(20),
                lastCheckDate VARCHAR(50)
            )");

            $result = $conn->query("SELECT * FROM users");
            $users = [];
            while($row = $result->fetch_assoc()) {
                // Ensure number types
                $row['purchasedGb'] = (float)$row['purchasedGb'];
                $row['consumedGb'] = (float)$row['consumedGb'];
                $users[] = $row;
            }
            $data['users'] = $users;
            $conn->close();
        }
    }
    return $data;
}

function saveDB($data) {
    global $dbFile;
    // Always save settings to JSON
    file_put_contents($dbFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    
    // Check if MySQL configured
    if (!empty($data['settings']['dbName']) && !empty($data['settings']['dbUser'])) {
        $conn = new mysqli('localhost', $data['settings']['dbUser'], $data['settings']['dbPassword'], $data['settings']['dbName']);
        if (!$conn->connect_error) {
            $conn->set_charset("utf8");
            
            // Sync Users: Simple Truncate & Insert approach for consistency
            $conn->query("CREATE TABLE IF NOT EXISTS users (
                id VARCHAR(50) PRIMARY KEY,
                name VARCHAR(255),
                telegramId VARCHAR(50),
                purchasedGb FLOAT,
                consumedGb FLOAT,
                purchaseDate VARCHAR(20),
                lastCheckDate VARCHAR(50)
            )");

            $conn->query("TRUNCATE TABLE users");
            
            if (!empty($data['users'])) {
                $stmt = $conn->prepare("INSERT INTO users (id, name, telegramId, purchasedGb, consumedGb, purchaseDate, lastCheckDate) VALUES (?, ?, ?, ?, ?, ?, ?)");
                foreach ($data['users'] as $user) {
                     $stmt->bind_param("sssdsss", 
                        $user['id'], 
                        $user['name'], 
                        $user['telegramId'], 
                        $user['purchasedGb'], 
                        $user['consumedGb'], 
                        $user['purchaseDate'], 
                        $user['lastCheckDate']
                     );
                     $stmt->execute();
                }
                $stmt->close();
            }
            $conn->close();
        }
    }
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if ($method === 'POST') {
    $inputRaw = file_get_contents("php://input");
    $input = json_decode($inputRaw, true);

    if ($action === 'send_bill') {
        $db = getDB();
        $token = $db['settings']['telegramBotToken'] ?? '';
        
        if (!$token) {
            echo json_encode(["status" => "error", "message" => "Bot token not configured"]);
            exit;
        }
        
        $chatId = isset($input['chat_id']) ? trim((string)$input['chat_id']) : '';
        $text = $input['text'] ?? '';
        
        if (empty($chatId) || empty($text)) {
             echo json_encode(["status" => "error", "message" => "Missing chat_id or text"]);
             exit;
        }

        $url = "https://api.telegram.org/bot$token/sendMessage";
        $data = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML'];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode == 200) {
            $resData = json_decode($response, true);
            if (isset($resData['ok']) && $resData['ok']) {
                echo json_encode(["status" => "success"]);
            } else {
                echo json_encode(["status" => "error", "message" => "Telegram API Error: " . ($resData['description'] ?? 'Unknown')]);
            }
        } else {
            echo json_encode(["status" => "error", "message" => "Connection Error ($httpCode)"]);
        }
        exit;
    }

    if ($input) {
        saveDB($input);
        echo json_encode(["status" => "success"]);
    } else {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Invalid JSON"]);
    }
} else {
    echo json_encode(getDB());
}
?>