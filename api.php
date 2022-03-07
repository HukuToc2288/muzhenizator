<?php
global $link;
$link = mysqli_connect("192.168.0.2:3306", "morphology", "morphman", "morphology");

if ($link == false) {
    printError("Невозможно подключиться к MySQL " . mysqli_connect_error());
}

if (!isset($_GET['word']) || empty($_GET['word'])) {
    printError("Параметр word не задан");
}

mysqli_set_charset($link, "utf8");
$wordFromGet = $_GET['word'];

global $returnData;
$returnData = array(
    'ok' => true,
    'words' => array()
);

processWord($wordFromGet);

//if (endsWith($wordFromGet, 'ка')) {
//    processWord(substr($wordFromGet, 0, strlen($wordFromGet) - 2), true);
//}

function processWord($word)
{
    global $link;
    global $returnData;
    $stmt = $link->stmt_init();
    if (
        ($stmt->prepare('SELECT * FROM words WHERE word = ?') === FALSE)
        or ($stmt->bind_param('s', $word) === FALSE)
        or ($stmt->execute() === FALSE)
        or (($results = $stmt->get_result()) === FALSE)
// закрываем подготовленный запрос
        or ($stmt->close() === FALSE)
    ) {
        printError('Select Error (' . $stmt->errno . ') ' . $stmt->error);
    }

    $needDecline = array("сущ", "прл", "прч", "гл", "мест", "числ");

    while (($row = $results->fetch_assoc()) != null) {
        if (!in_array($row['type'], $needDecline)) {
            // Эти части речи уже достаточно мужественные и не требуют склонения
            array_push($returnData['words'], buildEntry($row, false,));

        } elseif ($row['type'] == 'сущ') {
            // В инзначальной базе род у сущ. мн.ч. отсутствует, и есть только
            // в именах собственных, которые добавлены отдельно, поэтому если род отсутствует,
            // нужно попытаться получить код родителя
            if ($row['gender'] == null && $row['code_parent'] != 0) {
                // Может потребоваться до двух итераций, если сущ. в мн.ч. и не в им.п.
                $stmt = $link->stmt_init();
                if (
                    ($stmt->prepare('SELECT * FROM words WHERE code = ?') === FALSE)
                    or ($stmt->bind_param('i', $row['code_parent']) === FALSE)
                    or ($stmt->execute() === FALSE)
                    or (($parenResults = $stmt->get_result()) === FALSE)
                    or ($stmt->close() === FALSE)
                ) {
                    // При ошибках в получении рода возвращаем как есть
                    array_push($returnData['words'], buildEntry($row, true, null));
                    continue;
                }
                $parentRow = $parenResults->fetch_assoc();
                if ($parentRow['gender'] == null && $parentRow['code_parent'] != 0) {
                    // Если рода всё ещё нет, но есть уровень выше, то идём на уровень выше
                    $stmt = $link->stmt_init();
                    if (
                        ($stmt->prepare('SELECT * FROM words WHERE code = ?') === FALSE)
                        or ($stmt->bind_param('i', $parentRow['code_parent']) === FALSE)
                        or ($stmt->execute() === FALSE)
                        or (($grandParenResults = $stmt->get_result()) === FALSE)
                        or ($stmt->close() === FALSE)
                    ) {
                        // При ошибках в получении рода возвращаем как есть
                        array_push($returnData['words'], buildEntry($row, true, null));
                        continue;
                    }
                    // Выше идти некуда, поэтому, что бы мы не получили, возвращаем это
                    $grandParenRow = $grandParenResults->fetch_assoc();
                    $row['gender'] = $grandParenRow['gender'];

                } else {
                    $row['gender'] = $parentRow['gender'];
                }
                array_push($returnData['words'], buildEntry($row, true, null));
                continue;
            }

            // Существительные не склоняются по родам, поэтому мы предоставим это фронту
            array_push($returnData['words'], buildEntry($row, true, null));
        } elseif (($row['gender'] == null || $row['gender'] == 'муж' || $row['plural'] === 1)) {
            // мужской род а также мн.ч. (кроме сущ.) тоже довольно мужественны
            array_push($returnData['words'], buildEntry($row, false));
        } else if ($row['type'] == 'гл') {
            // Т.к глаголы склоняются по родам, то мы можем найти форму м.р. в базе данных
            // Для глаголов необходим всего один переход, так как он не склоняется по падежам
            $stmt = $link->stmt_init();
            if (
                ($stmt->prepare('SELECT * FROM words WHERE code = ?') === FALSE)
                or ($stmt->bind_param('i', $row['code_parent']) === FALSE)
                or ($stmt->execute() === FALSE)
                or (($verbResults = $stmt->get_result()) === FALSE)
                or ($stmt->close() === FALSE)
            ) {
                continue;
            }
            $verbRow = $verbResults->fetch_assoc();
            //var_dump($verbResults);
            array_push($returnData['words'], buildEntry($row, true, $verbRow['word']));
        } else {
            // Прилагательные, причастия, числительные и местоимения не только склоняются по родам,
            // но и очень неплохо склоняются по падежам, так что если мы получим слово не в им.п.,
            // то нужно перевести его в им.п., получить мужскую форму, а затем склонить её в нужный падеж

            // получаем именительную форму ж.р.
            $stmt = $link->stmt_init();
            if (
                ($stmt->prepare('SELECT DISTINCT * FROM words WHERE code = ?') === FALSE)
                or ($stmt->bind_param('i', $row['code_parent']) === FALSE)
                or ($stmt->execute() === FALSE)
                or (($nounResults = $stmt->get_result()) === FALSE)
                or ($stmt->close() === FALSE)
            ) {
                continue;
            }
            $imFormRow = $nounResults->fetch_assoc();
            if ($row['wcase'] == null || $row['wcase'] == 'им') {
                // Если у исходного слова им.п. или падежа нет совсем, значит мы нашли нужное слово
                $stmt = $link->stmt_init();
                if (
                    ($stmt->prepare('SELECT * FROM words WHERE code = ?') === FALSE)
                    or ($stmt->bind_param('i', $row['code_parent']) === FALSE)
                    or ($stmt->execute() === FALSE)
                    or (($nounResults = $stmt->get_result()) === FALSE)
                    or ($stmt->close() === FALSE)
                ) {
                    continue;
                }
                $imFormRow = $nounResults->fetch_assoc();
                array_push($returnData['words'], buildEntry($row, true, $imFormRow['word']));
            } else {
                // Если падеж исходного слова отличен от им.п., то нам нужно получить им.п. мужского рода, а затем склонить в нужный падеж
                // Получаем через код родителя форму м.р. в нужном падеже
//            $stmt = $link->stmt_init();
//            if (
//                ($stmt->prepare('SELECT * FROM words WHERE code_parent = ? AND gender = \'муж\' AND type = ? AND wcase = ? and short = ?') === FALSE)
//                or ($stmt->bind_param('issi',
//                        $row['code_parent'],
//                        $row['type'],
//                        $row['wcase'],
//                        $row['short']) === FALSE)
//                or ($stmt->execute() === FALSE)
//                or (($nounResults = $stmt->get_result()) === FALSE)
//                or ($stmt->close() === FALSE)
//            ) {
//                continue;
//            }
                $stmt = $link->stmt_init();
//            $targetGender = 'муж';
                //var_dump($imFormRow['code_parent']);
                if (
                    ($stmt->prepare('SELECT * FROM words WHERE code_parent = ? AND gender = \'муж\' AND type = ? AND wcase = ? and short = ?') === FALSE)
                    or ($stmt->bind_param('issi',
                            $imFormRow['code_parent'],
                            $row['type'],
                            $row['wcase'],
                            $row['short'],
                        ) === FALSE)
                    or ($stmt->execute() === FALSE)
                    or (($aaaResults = $stmt->get_result()) === FALSE)
                    or ($stmt->close() === FALSE)
                ) {
                    printError('Select Error (' . $stmt->errno . ') ' . $stmt->error);
                    continue;
                }
                // $manRow = $nounResults->fetch_assoc();
                while (($manRow = $aaaResults->fetch_assoc()) != null) {
                    array_push($returnData['words'], buildEntry($row, true, $manRow['word']));
                }
                //return;
                //array_push($returnData['words'], buildDeclinableEntry($row, $manRow['word']));
            }
        }
    }
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($returnData, JSON_UNESCAPED_UNICODE);
exit(0);

function buildEntry($row, $needDecline, $manForm = null)
{
    $rowArray = array(
        'needDecline' => $needDecline,
        'type' => $row['type']
    );
    if ($manForm != null) {
        $rowArray['manForm'] = $manForm;
    }
    if ($row['plural'] == 0) {
        $rowArray['plural'] = 'ед';
    } elseif ($row['plural'] == 1) {
        $rowArray['plural'] = 'мн';
    }
    if ($row['gender'] != null) {
        $rowArray['gender'] = $row['gender'];
    }
    if ($row['wcase'] != null) {
        $rowArray['wcase'] = $row['wcase'];
    }
    if ($row['soul'] != null) {
        $rowArray['soul'] = (bool)$row['soul'];
    }
    return $rowArray;
}

function printError($message)
{
    header('Content-Type: application/json;');
    echo json_encode(array(
        'ok' => false,
        'error' => $message
    ), JSON_UNESCAPED_UNICODE);
    exit(0);
}

function endsWith($haystack, $needle)
{
    $length = strlen($needle);
    return $length > 0 ? substr($haystack, -$length) === $needle : true;
}