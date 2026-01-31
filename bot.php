<?php
// تنظیمات دیباگ و لاگ
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/bot_errors.log');
error_reporting(E_ALL);

// پاسخ 200 به تلگرام برای جلوگیری از تکرار درخواست‌ها
http_response_code(200);

$baseDir = __DIR__;
$dbFile = $baseDir . '/database.json';
$stateFile = $baseDir . '/bot_states.json';

// --- توابع دیتابیس (مشترک با نسخه وب) ---

function getJsonDB() {
    global $dbFile;
    if (!file_exists($dbFile)) return ["users" => [], "settings" => []];
    $content = file_get_contents($dbFile);
    return json_decode($content, true) ?? ["users" => [], "settings" => []];
}

function getDB() {
    $data = getJsonDB();
    
    // اتصال به MySQL اگر تنظیم شده باشد
    if (!empty($data['settings']['dbName']) && !empty($data['settings']['dbUser'])) {
        $conn = new mysqli('localhost', $data['settings']['dbUser'], $data['settings']['dbPassword'], $data['settings']['dbName']);
        if (!$conn->connect_error) {
            $conn->set_charset("utf8");
            
            // ایجاد جدول در صورت عدم وجود
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
    // ذخیره تنظیمات در فایل JSON
    file_put_contents($dbFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    
    // ذخیره کاربران در MySQL اگر تنظیم شده باشد
    if (!empty($data['settings']['dbName']) && !empty($data['settings']['dbUser'])) {
        $conn = new mysqli('localhost', $data['settings']['dbUser'], $data['settings']['dbPassword'], $data['settings']['dbName']);
        if (!$conn->connect_error) {
            $conn->set_charset("utf8");
            
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

// --- توابع تلگرام ---

function apiRequest($method, $parameters) {
    global $token;
    if (!$token) return false;

    $url = "https://api.telegram.org/bot$token/$method";

    $handle = curl_init($url);
    curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($handle, CURLOPT_TIMEOUT, 60);
    curl_setopt($handle, CURLOPT_POST, true);
    // ارسال به صورت JSON برای پشتیبانی کامل از کیبوردها
    curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($parameters));
    curl_setopt($handle, CURLOPT_HTTPHEADER, array("Content-Type: application/json"));
    curl_setopt($handle, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($handle);
    if ($response === false) {
        error_log("Curl error: " . curl_error($handle));
        curl_close($handle);
        return false;
    }
    curl_close($handle);
    $response = json_decode($response, true);
    return $response;
}

function sendMessage($chat_id, $text, $keyboard = null) {
    $data = [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => 'HTML'
    ];
    if ($keyboard) {
        $data['reply_markup'] = $keyboard;
    }
    return apiRequest("sendMessage", $data);
}

function editMessage($chat_id, $message_id, $text, $keyboard = null) {
    $data = [
        'chat_id' => $chat_id,
        'message_id' => $message_id,
        'text' => $text,
        'parse_mode' => 'HTML'
    ];
    if ($keyboard) {
        $data['reply_markup'] = $keyboard;
    }
    return apiRequest("editMessageText", $data);
}

function answerCallback($callback_query_id, $text = null) {
    $data = ['callback_query_id' => $callback_query_id];
    if ($text) $data['text'] = $text;
    return apiRequest("answerCallbackQuery", $data);
}

// --- کیبوردها ---

function getMainKeyboard() {
    return [
        'keyboard' => [
            [
                ['text' => '👥 مدیریت کاربران'],
                ['text' => '📊 آمار و گزارش']
            ],
            [
                ['text' => '➕ افزودن کاربر'],
                ['text' => '⚙️ تنظیمات']
            ],
            [
                ['text' => '📦 دریافت بکاپ']
            ]
        ],
        'resize_keyboard' => true,
        'is_persistent' => true // این گزینه باعث می‌شود کیبورد همیشه بماند
    ];
}

function getCancelKeyboard() {
    return [
        'keyboard' => [
            [['text' => '🏠 منوی اصلی']]
        ],
        'resize_keyboard' => true
    ];
}

// --- منطق اصلی ربات ---

// 1. دریافت ورودی
$update = json_decode(file_get_contents('php://input'), true);

if (!$update) {
    // برای تست دستی در مرورگر
    echo "Bot Engine is Running...";
    exit;
}

// 2. لود کردن اطلاعات
$data = getDB();
$settings = $data['settings'] ?? [];
$token = $settings['telegramBotToken'] ?? '';
$adminId = $settings['adminTelegramId'] ?? '';

// اگر توکن ست نشده باشد، کاری نمی‌توان کرد
if (empty($token)) {
    error_log("Bot Token Not Found in DB");
    exit;
}

// 3. لود وضعیت‌ها (برای مراحل چند مرحله‌ای مثل افزودن کاربر)
$states = file_exists($stateFile) ? json_decode(file_get_contents($stateFile), true) : [];

// --- پردازش پیام متنی ---
if (isset($update['message']['text'])) {
    $chatId = $update['message']['chat']['id'];
    $text = $update['message']['text'];

    // بررسی دسترسی ادمین
    if ((string)$chatId !== (string)$adminId) {
        if (empty($adminId)) {
            sendMessage($chatId, "⚠️ ربات هنوز کانفیگ نشده است.\n🆔 آیدی شما: <code>$chatId</code>\nلطفا این آیدی را در پنل مدیریت وب وارد کنید.");
        } else {
            sendMessage($chatId, "⛔️ شما اجازه دسترسی به این ربات را ندارید.");
        }
        exit;
    }

    // دکمه بازگشت یا استارت
    if ($text === '/start' || $text === '🏠 منوی اصلی') {
        // پاک کردن وضعیت فعلی
        if (isset($states[$chatId])) {
            unset($states[$chatId]);
            file_put_contents($stateFile, json_encode($states));
        }
        sendMessage($chatId, "👋 سلام مدیر عزیز\nبه پنل مدیریت فروشگاه هیدیفای خوش آمدید.\nیکی از گزینه‌های زیر را انتخاب کنید:", getMainKeyboard());
        exit;
    }

    // ماشین وضعیت (State Machine)
    if (isset($states[$chatId])) {
        $step = $states[$chatId]['step'];

        // --- افزودن کاربر ---
        if ($step == 'add_user_name') {
            $states[$chatId]['temp_name'] = $text;
            $states[$chatId]['step'] = 'add_user_telegram';
            file_put_contents($stateFile, json_encode($states));
            
            $kb = ['inline_keyboard' => [[['text' => 'ندارد / رد کردن', 'callback_data' => 'skip_telegram']]]];
            sendMessage($chatId, "👤 نام: <b>$text</b>\n\n🆔 حالا <b>آیدی عددی تلگرام</b> کاربر را وارد کنید:\n(می‌توانید از دکمه رد کردن استفاده کنید)", $kb);
            exit;
        }

        if ($step == 'add_user_telegram') {
            if (is_numeric($text)) {
                $states[$chatId]['temp_telegram'] = $text;
                $states[$chatId]['step'] = 'add_user_gb';
                file_put_contents($stateFile, json_encode($states));
                sendMessage($chatId, "💾 حجم خریداری شده (GB) را وارد کنید:");
            } else {
                sendMessage($chatId, "❌ لطفا فقط عدد وارد کنید (برای آیدی تلگرام).");
            }
            exit;
        }

        if ($step == 'add_user_gb') {
            if (is_numeric($text)) {
                $gb = floatval($text);
                $name = $states[$chatId]['temp_name'];
                $telegramId = $states[$chatId]['temp_telegram'] ?? null;

                // رفرش کردن دیتابیس برای اطمینان
                $data = getDB();
                
                $newUser = [
                    "id" => uniqid(),
                    "name" => $name,
                    "telegramId" => $telegramId,
                    "purchasedGb" => $gb,
                    "consumedGb" => 0,
                    "purchaseDate" => date('Y-m-d'),
                    "lastCheckDate" => date('c')
                ];

                $data['users'][] = $newUser;
                saveDB($data);

                unset($states[$chatId]);
                file_put_contents($stateFile, json_encode($states));
                sendMessage($chatId, "✅ کاربر <b>$name</b> با حجم $gb GB با موفقیت افزوده شد.", getMainKeyboard());
            } else {
                sendMessage($chatId, "❌ لطفا فقط عدد وارد کنید (برای حجم).");
            }
            exit;
        }

        // --- کسر حجم ---
        if ($step == 'consume_gb') {
            if (is_numeric($text)) {
                $amount = floatval($text);
                $targetId = $states[$chatId]['target_id'];
                
                $data = getDB();
                $found = false;
                foreach ($data['users'] as &$user) {
                    if ($user['id'] == $targetId) {
                        $user['consumedGb'] += $amount;
                        $user['lastCheckDate'] = date('c');
                        $found = true;
                        break;
                    }
                }

                if ($found) {
                    saveDB($data);
                    sendMessage($chatId, "✅ مقدار $amount GB از حجم کاربر کسر شد.", getMainKeyboard());
                } else {
                    sendMessage($chatId, "❌ کاربر پیدا نشد.", getMainKeyboard());
                }
                
                unset($states[$chatId]);
                file_put_contents($stateFile, json_encode($states));
            } else {
                sendMessage($chatId, "❌ لطفا عدد وارد کنید.");
            }
            exit;
        }

        // --- ارسال دستی صورتحساب ---
        if ($step == 'enter_manual_bill_id') {
            if (is_numeric($text)) {
                $manualId = trim($text);
                $targetUserId = $states[$chatId]['target_user_id'];
                
                $data = getDB();
                $targetUser = null;
                foreach ($data['users'] as $u) {
                    if ($u['id'] == $targetUserId) { $targetUser = $u; break; }
                }

                if ($targetUser) {
                    $pricePerGb = $data['settings']['pricePerGb'] ?? 0;
                    $profit = $data['settings']['profitPercentage'] ?? 0;
                    $price = ($targetUser['purchasedGb'] * $pricePerGb) * (1 + $profit/100);
                    $rem = $targetUser['purchasedGb'] - $targetUser['consumedGb'];
                    
                    $msg = "🧾 <b>صورت‌حساب سرویس</b>\n" .
                           "➖➖➖➖➖➖➖➖\n" .
                           "👤 <b>نام کاربر:</b> {$targetUser['name']}\n" .
                           "🆔 <b>شناسه:</b> {$manualId}\n" .
                           "📅 <b>تاریخ خرید:</b> {$targetUser['purchaseDate']}\n" .
                           "💾 <b>حجم کل:</b> {$targetUser['purchasedGb']} GB\n" .
                           "📉 <b>مصرف شده:</b> {$targetUser['consumedGb']} GB\n" .
                           "➖➖➖➖➖➖➖➖\n" .
                           "💰 <b>مبلغ قابل پرداخت:</b> " . number_format($price) . " تومان\n" .
                           "🔋 <b>باقی‌مانده:</b> {$rem} GB";
                    
                    $res = sendMessage($manualId, $msg);
                    if ($res && $res['ok']) {
                        sendMessage($chatId, "✅ صورت‌حساب به آیدی $manualId ارسال شد.", getMainKeyboard());
                    } else {
                        sendMessage($chatId, "❌ خطا در ارسال به تلگرام کاربر.\nمطمئن شوید کاربر ربات را استارت کرده است.", getMainKeyboard());
                    }
                } else {
                    sendMessage($chatId, "❌ خطا: کاربر در دیتابیس پیدا نشد.", getMainKeyboard());
                }
                unset($states[$chatId]);
                file_put_contents($stateFile, json_encode($states));
            } else {
                sendMessage($chatId, "❌ لطفا یک آیدی عددی معتبر وارد کنید.");
            }
            exit;
        }
        
        // --- تنظیمات ---
        if (in_array($step, ['set_price', 'set_profit', 'set_warn'])) {
            if (is_numeric($text)) {
                $data = getDB();
                $val = floatval($text);
                
                if ($step == 'set_price') {
                    $data['settings']['pricePerGb'] = $val;
                    $msg = "✅ قیمت هر گیگ تغییر یافت.";
                } elseif ($step == 'set_profit') {
                    $data['settings']['profitPercentage'] = $val;
                    $msg = "✅ درصد سود تغییر یافت.";
                } elseif ($step == 'set_warn') {
                    $data['settings']['warningDays'] = intval($val);
                    $msg = "✅ روز هشدار تغییر یافت.";
                }
                
                saveDB($data);
                unset($states[$chatId]);
                file_put_contents($stateFile, json_encode($states));
                sendMessage($chatId, $msg, getMainKeyboard());
            } else {
                sendMessage($chatId, "❌ لطفا عدد وارد کنید.");
            }
            exit;
        }
    }

    // --- دستورات منوی اصلی ---
    if ($text === '👥 مدیریت کاربران') {
        $data = getDB();
        if (empty($data['users'])) {
            sendMessage($chatId, "📭 لیست کاربران خالی است.");
        } else {
            // ساخت لیست شیشه‌ای
            $buttons = [];
            foreach ($data['users'] as $u) {
                $rem = $u['purchasedGb'] - $u['consumedGb'];
                $btnText = "👤 {$u['name']} | 🔋 {$rem} GB";
                $buttons[] = [['text' => $btnText, 'callback_data' => "user_info:{$u['id']}"]];
            }
            sendMessage($chatId, "👥 لیست کاربران:\nبرای مدیریت روی نام کاربر کلیک کنید.", ['inline_keyboard' => $buttons]);
        }
    }
    elseif ($text === '📊 آمار و گزارش') {
        $data = getDB();
        $totalUsers = count($data['users']);
        $totalGb = 0;
        $totalRev = 0;
        $priceBase = $data['settings']['pricePerGb'] ?? 0;
        $profit = $data['settings']['profitPercentage'] ?? 0;

        foreach ($data['users'] as $u) {
            $totalGb += $u['purchasedGb'];
            $price = ($u['purchasedGb'] * $priceBase) * (1 + $profit/100);
            $totalRev += $price;
        }
        
        $msg = "📊 <b>گزارش وضعیت فروشگاه</b>\n\n" .
               "👥 تعداد کاربران: $totalUsers\n" .
               "💾 کل حجم فروخته شده: $totalGb GB\n" .
               "💰 درآمد کل (تخمینی): " . number_format($totalRev) . " تومان";
               
        sendMessage($chatId, $msg);
    }
    elseif ($text === '➕ افزودن کاربر') {
        $states[$chatId] = ['step' => 'add_user_name'];
        file_put_contents($stateFile, json_encode($states));
        sendMessage($chatId, "👤 نام کاربر جدید را وارد کنید:", getCancelKeyboard());
    }
    elseif ($text === '⚙️ تنظیمات') {
        $s = $data['settings'];
        $msg = "⚙️ <b>تنظیمات فعلی</b>\n\n" .
               "💰 قیمت پایه: " . number_format($s['pricePerGb'] ?? 0) . " تومان\n" .
               "📈 سود: " . ($s['profitPercentage'] ?? 0) . "%\n" .
               "⚠️ هشدار: " . ($s['warningDays'] ?? 0) . " روز";
        
        $kb = [
            'inline_keyboard' => [
                [['text' => '✏️ تغییر قیمت', 'callback_data' => 'conf_price']],
                [['text' => '✏️ تغییر سود', 'callback_data' => 'conf_profit']],
                [['text' => '✏️ تغییر روز هشدار', 'callback_data' => 'conf_warn']]
            ]
        ];
        sendMessage($chatId, $msg, $kb);
    }
    elseif ($text === '📦 دریافت بکاپ') {
        $backupPath = $baseDir . '/backup_' . time() . '.json';
        file_put_contents($backupPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        
        // ارسال فایل
        $url = "https://api.telegram.org/bot$token/sendDocument";
        $post = [
            'chat_id' => $chatId,
            'document' => new CURLFile($backupPath),
            'caption' => "📦 بکاپ دیتابیس - " . date('Y/m/d H:i')
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_exec($ch);
        curl_close($ch);
        
        unlink($backupPath);
    }
}

// --- پردازش دکمه‌های شیشه‌ای (Callback Query) ---
if (isset($update['callback_query'])) {
    $cb = $update['callback_query'];
    $cbId = $cb['id'];
    $chatId = $cb['message']['chat']['id'];
    $msgId = $cb['message']['message_id'];
    $dataStr = $cb['data'];

    if ((string)$chatId !== (string)$adminId) return;

    // رد کردن آیدی تلگرام در افزودن کاربر
    if ($dataStr === 'skip_telegram') {
        if (isset($states[$chatId]) && $states[$chatId]['step'] == 'add_user_telegram') {
            $states[$chatId]['step'] = 'add_user_gb';
            file_put_contents($stateFile, json_encode($states));
            sendMessage($chatId, "💾 حجم خریداری شده (GB) را وارد کنید:");
            answerCallback($cbId);
        }
        exit;
    }

    $parts = explode(':', $dataStr);
    $action = $parts[0];
    $id = $parts[1] ?? null;

    if ($action === 'user_info') {
        $data = getDB();
        $u = null;
        foreach ($data['users'] as $usr) { if($usr['id'] == $id) { $u = $usr; break; } }

        if ($u) {
            $rem = $u['purchasedGb'] - $u['consumedGb'];
            $txt = "👤 <b>{$u['name']}</b>\n\n" .
                   "🆔 آیدی تلگرام: " . ($u['telegramId'] ?: 'ندارد') . "\n" .
                   "📅 تاریخ خرید: {$u['purchaseDate']}\n" .
                   "💾 حجم کل: {$u['purchasedGb']} GB\n" .
                   "📉 مصرف شده: {$u['consumedGb']} GB\n" .
                   "🔋 باقی‌مانده: {$rem} GB";
            
            $kb = ['inline_keyboard' => [
                [
                    ['text' => '📉 کسر حجم', 'callback_data' => "consume:$id"],
                    ['text' => '🧾 ارسال صورت‌حساب', 'callback_data' => "bill:$id"]
                ],
                [
                    ['text' => '🗑 حذف کاربر', 'callback_data' => "del_ask:$id"]
                ],
                [
                    ['text' => '🔙 بازگشت به لیست', 'callback_data' => "list_all"]
                ]
            ]];
            editMessage($chatId, $msgId, $txt, $kb);
        } else {
            answerCallback($cbId, "کاربر یافت نشد");
        }
    }
    elseif ($action === 'list_all') {
        $data = getDB();
        $buttons = [];
        foreach ($data['users'] as $u) {
            $rem = $u['purchasedGb'] - $u['consumedGb'];
            $buttons[] = [['text' => "👤 {$u['name']} | 🔋 {$rem} GB", 'callback_data' => "user_info:{$u['id']}"]];
        }
        editMessage($chatId, $msgId, "👥 لیست کاربران:", ['inline_keyboard' => $buttons]);
    }
    elseif ($action === 'consume') {
        $states[$chatId] = ['step' => 'consume_gb', 'target_id' => $id];
        file_put_contents($stateFile, json_encode($states));
        sendMessage($chatId, "📉 مقدار حجمی که مصرف شده را وارد کنید (GB):", getCancelKeyboard());
        answerCallback($cbId);
    }
    elseif ($action === 'bill') {
        $data = getDB();
        $u = null;
        foreach ($data['users'] as $usr) { if($usr['id'] == $id) { $u = $usr; break; } }
        
        if ($u) {
            if (!empty($u['telegramId'])) {
                $pricePerGb = $data['settings']['pricePerGb'] ?? 0;
                $profit = $data['settings']['profitPercentage'] ?? 0;
                $price = ($u['purchasedGb'] * $pricePerGb) * (1 + $profit/100);
                $rem = $u['purchasedGb'] - $u['consumedGb'];

                $msg = "🧾 <b>صورت‌حساب سرویس</b>\n" .
                       "➖➖➖➖➖➖➖➖\n" .
                       "👤 <b>نام کاربر:</b> {$u['name']}\n" .
                       "📅 <b>تاریخ خرید:</b> {$u['purchaseDate']}\n" .
                       "💾 <b>حجم کل:</b> {$u['purchasedGb']} GB\n" .
                       "📉 <b>مصرف شده:</b> {$u['consumedGb']} GB\n" .
                       "➖➖➖➖➖➖➖➖\n" .
                       "💰 <b>مبلغ قابل پرداخت:</b> " . number_format($price) . " تومان\n" .
                       "🔋 <b>باقی‌مانده:</b> {$rem} GB";
                
                $res = sendMessage($u['telegramId'], $msg);
                if ($res && $res['ok']) {
                    answerCallback($cbId, "✅ ارسال شد.");
                } else {
                    answerCallback($cbId, "❌ خطا در ارسال.");
                }
            } else {
                $states[$chatId] = ['step' => 'enter_manual_bill_id', 'target_user_id' => $id];
                file_put_contents($stateFile, json_encode($states));
                sendMessage($chatId, "⚠️ آیدی تلگرام ثبت نشده است.\n🆔 آیدی عددی مقصد را وارد کنید:", getCancelKeyboard());
                answerCallback($cbId);
            }
        }
    }
    elseif ($action === 'del_ask') {
        $kb = ['inline_keyboard' => [
            [['text' => '✅ بله، حذف شود', 'callback_data' => "del_do:$id"]],
            [['text' => '❌ خیر، لغو', 'callback_data' => "user_info:$id"]]
        ]];
        editMessage($chatId, $msgId, "⚠️ مطمئنید حذف شود؟", $kb);
    }
    elseif ($action === 'del_do') {
        $data = getDB();
        $newData = [];
        foreach ($data['users'] as $u) {
            if ($u['id'] != $id) $newData[] = $u;
        }
        $data['users'] = $newData;
        saveDB($data);
        answerCallback($cbId, "حذف شد.");
        
        // نمایش لیست بروز شده
        $buttons = [];
        foreach ($data['users'] as $u) {
            $rem = $u['purchasedGb'] - $u['consumedGb'];
            $buttons[] = [['text' => "👤 {$u['name']} | 🔋 {$rem} GB", 'callback_data' => "user_info:{$u['id']}"]];
        }
        editMessage($chatId, $msgId, "👥 لیست کاربران (بروز شد):", ['inline_keyboard' => $buttons]);
    }
    // تنظیمات
    elseif ($action === 'conf_price') {
        $states[$chatId] = ['step' => 'set_price'];
        file_put_contents($stateFile, json_encode($states));
        sendMessage($chatId, "💰 قیمت جدید را وارد کنید:", getCancelKeyboard());
        answerCallback($cbId);
    }
    elseif ($action === 'conf_profit') {
        $states[$chatId] = ['step' => 'set_profit'];
        file_put_contents($stateFile, json_encode($states));
        sendMessage($chatId, "📈 درصد سود جدید را وارد کنید:", getCancelKeyboard());
        answerCallback($cbId);
    }
    elseif ($action === 'conf_warn') {
        $states[$chatId] = ['step' => 'set_warn'];
        file_put_contents($stateFile, json_encode($states));
        sendMessage($chatId, "⚠️ روز هشدار جدید را وارد کنید:", getCancelKeyboard());
        answerCallback($cbId);
    }
}
?>