<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// =====================================================
// НАСТРОЙКИ -- вставьте ваши ключи
// =====================================================
define('API_FNS_KEY',   'ВАШ_КЛЮЧ_ЗДЕСЬ'); // Сюда вставить API-ключ api-fns

// 1. Читаем запрос
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$rawBody = file_get_contents('php://input');

if (strpos($contentType, 'application/json') !== false) {
    $input = json_decode($rawBody, true) ?? [];
} else {
    parse_str($rawBody, $postData);
    $input = array_merge($_GET, $postData);
}

$inn  = trim($input['inn']  ?? '');
$date = trim($input['date'] ?? date("d.m.Y"));

define('REQUEST_DATE', DateTime::createFromFormat("d.m.Y", $date));

// 2. Валидация
if (!$inn) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Поле inn обязательно'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!preg_match('/^\d{10}(\d{2})?$/', $inn)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'ИНН должен содержать 10 (юрлицо) или 12 (ИП) цифр'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 3. Запрос к нужному источнику
$result = fetchFromFns($inn);

// 4. Ответ
http_response_code(200);
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);


// =====================================================
// api-fns.ru
// =====================================================
function fetchFromFns(string $inn): array
{
    $url = 'https://api-fns.ru/api/egr?req=' . urlencode($inn) . '&key=' . API_FNS_KEY;

    $response = curlGet($url);

    if (!$response['ok']) {
        return ['http_code' => 502, 'body' => [
            'status'  => 'error',
            'source'  => 'fns',
            'message' => 'Не удалось получить данные от api-fns.ru',
            'detail'  => $response['error'],
        ]];
    }

    $data = json_decode($response['body'], true);

    if (empty($data['items'])) {
        return ['http_code' => 200, 'body' => [
            'status'  => 'not_found',
            'source'  => 'fns',
            'inn'     => $inn,
            'message' => 'Компания не найдена',
        ]];
    }

    $result = parse($data);

    return $result;
}

// =====================================================
// Общий cURL GET
// =====================================================
function curlGet(string $url): array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'webhook/1.0',
    ]);
    $body  = curl_exec($ch);
    $error = curl_error($ch);
    $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($error || $code < 200 || $code >= 300) {
        return ['ok' => false, 'error' => $error ?: "HTTP $code"];
    }
    return ['ok' => true, 'body' => $body];
}

// =====================================================
// Parse - основной метод парсинга
// =====================================================
function parse($data)
{
    $count = 0;
    foreach ($data['items'] as $item) {
        $ul = $item['ЮЛ'];
        $ip = $item['ИП'];
        $count += 1;
        if (isset($ul)) {
            $result['ЮЛ' . $count] = parseUL($ul);
        } elseif (isset($ip)) {
            $result['ИП' . $count] = parseIP($ip);
        }
    }
    $result = getEndData($result);
    return $result;
}

// =====================================================
// parseUL - метод парсинга для юр. лиц
// =====================================================
function parseUL($ul)
{
    $result = [];
    $require_requisite = [
        "Адрес",
        "Руководитель",
    ];

    $cur = [
        'НаимСокрЮЛ' => $ul['НаимСокрЮЛ'],
        'НаимПолнЮЛ' => $ul['НаимПолнЮЛ'],
        'ИНН' => $ul['ИНН'],
        'ОГРН' => $ul['ОГРН'],
        'Адрес' => $ul['Адрес'],
        'Руководитель' => $ul['Руководитель'],
    ];

    $result['Текущие данные'] =   [
        'Наименование компании' => $cur['НаимСокрЮЛ'],
        'Полное наименование компании' => $cur['НаимПолнЮЛ'],
        'ИНН' => $cur['ИНН'],
        'ОГРН' => $cur['ОГРН'],
        'Адрес' => $cur['Адрес']['АдресПолн'],
        'ФИО руководителя' => $cur['Руководитель']['ФИОПолн'],
        'Должность руководителя' => $cur['Руководитель']['Должн'],
    ];
    $cur = findHistoryInfo($require_requisite, $ul, $cur);

    if (isset($cur)) {
        $result['Данные на дату'] = [
            'Наименование компании' => $cur['НаимСокрЮЛ'],
            'Полное наименование компании' => $cur['НаимПолнЮЛ'],
            'ИНН' => $cur['ИНН'],
            'ОГРН' => $cur['ОГРН'],
            'Адрес' => $cur['Адрес']['АдресПолн'],
            'ФИО руководителя' => $cur['Руководитель']['ФИОПолн'],
            'Должность руководителя' => $cur['Руководитель']['Должн'],
        ];
    }
    return $result;
}

// =====================================================
// parseIP - метод парсинга для ип. лиц
// =====================================================
function parseIP($ip)
{
    $result = [];
    $require_requisite = [
        "Адрес",
    ];

    $cur = [
        'ФИОПолн' => $ip['ФИОПолн'],
        'ИННФЛ' => $ip['ИННФЛ'],
        'ОГРНИП' => $ip['ОГРНИП'],
        'Адрес' => $ip['Адрес'],
    ];

    $result['Текущие данные'] =   [
        'ФИО ИП' => $cur['ФИОПолн'],
        'ИННФЛ' => $cur['ИННФЛ'],
        'ОГРНИП' => $cur['ОГРНИП'],
        'Адрес' => $cur['Адрес']['АдресПолн'],
    ];

    $cur = findHistoryInfo($require_requisite, $ip, $cur);
    if (isset($cur)) {
        $result['Данные на дату'] = [
            'ФИО ИП' => $cur['ФИОПолн'],
            'ИННФЛ' => $cur['ИННФЛ'],
            'ОГРНИП' => $cur['ОГРНИП'],
            'Адрес' => $cur['Адрес']['АдресПолн'],
        ];
    }
    return $result;
}

// ==========================================================================================================
// findHistoryInfo - находит в истории параметры из require_requisite по заданной дате в REQUEST_DATE
// ==========================================================================================================
function findHistoryInfo($require_requisite, $item, $cur)
{
    $history = $item['История'];
    $flag = false;
    foreach ($require_requisite as $requisite_name) {
        $date_main = DateTime::createFromFormat('Y-m-d', $item[$requisite_name]['Дата']);
        $date_param = REQUEST_DATE;
        if ($date_main > $date_param || !isset($item[$requisite_name]['Дата'])) {
            $hist_param = $history[$requisite_name];
            foreach ($hist_param as $period => $period_data) {
                $dates = explode(' ~ ', $period);
                $startPeriod = DateTime::createFromFormat('Y-m-d', $dates[0]);
                $endPeriod = DateTime::createFromFormat('Y-m-d', $dates[1]);
                if ($startPeriod <= $date_param && $date_param <= $endPeriod) {
                    $cur[$requisite_name] = $period_data;
                    $flag = true;
                }
            }
        }
    }
    if ($flag)
        return $cur;
}

// ==========================================================================================================
// getEndData - получение окончательных данных на дату
// ==========================================================================================================
function getEndData($data)
{
    $dataOnDate = [];
    foreach ($data as $item) {
        if (isset($item['Данные на дату'])) {
            $dataOnDate = $item;
        } else {
            $dataOnDate['Текущие данные'] = $item['Текущие данные'];
        }
    }

    if (isset($dataOnDate['Данные на дату'])) {
        $dataOnDate = $dataOnDate['Данные на дату'];
    }

    return $dataOnDate;
}
