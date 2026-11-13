<?php
/**
 * Модуль Yandex TTS для MajorDoMo
 * Поддерживает API v1 (REST) и v3 (REST через API-ключ + folder-id)
 *
 * @package project
 * @version 3.4
 * @author Wizard <sergejey@gmail.com>
 * @copyright http://majordomo.smartliving.ru/ (c)
 *
 * @author Pau1td <cms.rmd@yandex.ru> — модернизация, поддержка API v1, v3, новый интерфейс,
 *         словарь ударений, кэширование, повторные попытки, увеличенные таймауты.
 * @version 3.4
 */


class yandex_tts extends module {

    // --------------------------------------------------------------------
    // Базовые методы модуля
    // --------------------------------------------------------------------

    function yandex_tts() {
        $this->name = "yandex_tts";
        $this->title = "Yandex TTS (v1/v3)";
        $this->module_category = "<#LANG_SECTION_APPLICATIONS#>";
        $this->checkInstalled();
        $this->MyDebug = true;
    }

    function saveParams($data = 0) {
        $p = array();
        if (IsSet($this->id)) $p["id"] = $this->id;
        if (IsSet($this->view_mode)) $p["view_mode"] = $this->view_mode;
        if (IsSet($this->edit_mode)) $p["edit_mode"] = $this->edit_mode;
        if (IsSet($this->tab)) $p["tab"] = $this->tab;
        return parent::saveParams($p);
    }

    function getParams() {
        global $id, $mode, $view_mode, $edit_mode, $tab;
        if (isset($id)) $this->id = $id;
        if (isset($mode)) $this->mode = $mode;
        if (isset($view_mode)) $this->view_mode = $view_mode;
        if (isset($edit_mode)) $this->edit_mode = $edit_mode;
        if (isset($tab)) $this->tab = $tab;
    }

    function run() {
        global $session;
        $out = array();
        if ($this->action == 'admin') {
            $this->admin($out);
        } else {
            $this->usual($out);
        }
        if (IsSet($this->owner->action)) $out['PARENT_ACTION'] = $this->owner->action;
        if (IsSet($this->owner->name)) $out['PARENT_NAME'] = $this->owner->name;
        $out['VIEW_MODE'] = $this->view_mode;
        $out['EDIT_MODE'] = $this->edit_mode;
        $out['MODE'] = $this->mode;
        $out['ACTION'] = $this->action;
        $this->data = $out;
        $p = new parser(DIR_TEMPLATES . $this->name . "/" . $this->name . ".html", $this->data, $this);
        $this->result = $p->result;
    }

    // --------------------------------------------------------------------
    // Логирование (только в debmes, но с force=true пишет всегда)
    // --------------------------------------------------------------------

    function logMessage($msg, $force = false) {
        if (!$force && isset($this->config['DEBUG']) && $this->config['DEBUG'] != '1') {
            return;
        }
        DebMes($msg, 'yandex_tts');
        // Временное логирование в файл для отладки (раскомментировать при необходимости)
        /*
        $logFile = ROOT . 'cms/modules/yandex_tts/yandex_tts.log';
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
        }
        @file_put_contents($logFile, date('Y-m-d H:i:s') . ' ' . $msg . PHP_EOL, FILE_APPEND);
        */
    }

    // --------------------------------------------------------------------
    // Вспомогательная функция определения кодировки таблицы
    // --------------------------------------------------------------------

    function getTableEncoding() {
        $result = SQLSelectOne("SHOW CREATE TABLE yandex_tts_emphasis");
        if ($result && isset($result['Create Table'])) {
            $create = $result['Create Table'];
            if (preg_match('/DEFAULT CHARSET=([a-z0-9_-]+)/i', $create, $m)) {
                return strtoupper($m[1]);
            }
        }
        // Если не определилось, пробуем узнать через INFORMATION_SCHEMA
        $res = SQLSelectOne("SELECT CCSA.character_set_name FROM information_schema.tables T, information_schema.collation_character_set_applicability CCSA WHERE CCSA.collation_name = T.table_collation AND T.table_name = 'yandex_tts_emphasis'");
        if ($res && isset($res['character_set_name'])) {
            return strtoupper($res['character_set_name']);
        }
        return 'UTF-8'; // по умолчанию
    }

    // --------------------------------------------------------------------
    // Административная часть
    // --------------------------------------------------------------------

    function admin(&$out) {
        $this->getConfig();

        // Чтение текущих настроек (дефолты изменены)
        $out['API_VERSION'] = isset($this->config['API_VERSION']) ? (string)$this->config['API_VERSION'] : 'v1';
        $out['API_KEY'] = isset($this->config['API_KEY']) ? (string)$this->config['API_KEY'] : '';
        $out['FOLDER_ID'] = isset($this->config['FOLDER_ID']) ? (string)$this->config['FOLDER_ID'] : '';
        $out['SPEAKER'] = isset($this->config['SPEAKER']) ? (string)$this->config['SPEAKER'] : 'alena';
        $out['EMOTION'] = isset($this->config['EMOTION']) ? (string)$this->config['EMOTION'] : 'good';
        $out['EMPHASIS'] = isset($this->config['EMPHASIS']) ? (string)$this->config['EMPHASIS'] : '1';
        $out['DISABLED'] = isset($this->config['DISABLED']) ? (string)$this->config['DISABLED'] : '0';
        $out['LANGUAGE'] = isset($this->config['LANGUAGE']) ? (string)$this->config['LANGUAGE'] : 'ru-RU';
        $out['SPEED'] = isset($this->config['SPEED']) ? (string)$this->config['SPEED'] : '1.0';
        $out['FORMAT'] = isset($this->config['FORMAT']) ? (string)$this->config['FORMAT'] : 'mp3';
        $out['VOICE_GENDER'] = isset($this->config['VOICE_GENDER']) ? (string)$this->config['VOICE_GENDER'] : 'female';
        $out['DEBUG'] = isset($this->config['DEBUG']) ? (string)$this->config['DEBUG'] : '1';

        // Данные о голосах 
        $voices_data = [
            'v1' => [
                'ru-RU' => [
                    'male' => [
                        ['id' => 'ermil', 'label' => 'ermil', 'emotions' => ['neutral', 'good']],
                        ['id' => 'zahar', 'label' => 'zahar', 'emotions' => ['neutral', 'good']],
                        ['id' => 'filipp', 'label' => 'filipp', 'emotions' => []],
                        ['id' => 'madi_ru', 'label' => 'madi_ru', 'emotions' => []],
                    ],
                    'female' => [
                        ['id' => 'alena', 'label' => 'alena', 'emotions' => ['neutral', 'good']],
                        ['id' => 'jane', 'label' => 'jane', 'emotions' => ['neutral', 'good', 'evil']],
                        ['id' => 'omazh', 'label' => 'omazh', 'emotions' => ['neutral', 'evil']],
                        ['id' => 'marina', 'label' => 'marina', 'emotions' => ['neutral', 'whisper', 'friendly']],
                        ['id' => 'oksana', 'label' => 'oksana (legacy)', 'emotions' => ['neutral', 'good', 'evil']],
                    ]
                ],
                'en-US' => [
                    'male' => [],
                    'female' => []
                ]
            ],
            'v3' => [
                'ru-RU' => [
                    'male' => [
                        ['id' => 'alexander', 'label' => 'alexander', 'emotions' => ['neutral', 'good']],
                        ['id' => 'anton', 'label' => 'anton', 'emotions' => ['neutral', 'good']],
                        ['id' => 'ermil', 'label' => 'ermil', 'emotions' => ['neutral', 'good']],
                        ['id' => 'kirill', 'label' => 'kirill', 'emotions' => ['neutral', 'strict', 'good']],
                        ['id' => 'zahar', 'label' => 'zahar', 'emotions' => ['neutral', 'good']],
                        ['id' => 'filipp', 'label' => 'filipp', 'emotions' => []],
                        ['id' => 'madi_ru', 'label' => 'madi_ru', 'emotions' => []],
                    ],
                    'female' => [
                        ['id' => 'alena', 'label' => 'alena', 'emotions' => ['neutral', 'good']],
                        ['id' => 'dasha', 'label' => 'dasha', 'emotions' => ['neutral', 'good', 'friendly']],
                        ['id' => 'jane', 'label' => 'jane', 'emotions' => ['neutral', 'good', 'evil']],
                        ['id' => 'julia', 'label' => 'julia', 'emotions' => ['neutral', 'strict']],
                        ['id' => 'lera', 'label' => 'lera', 'emotions' => ['neutral', 'friendly']],
                        ['id' => 'marina', 'label' => 'marina', 'emotions' => ['neutral', 'whisper', 'friendly']],
                        ['id' => 'masha', 'label' => 'masha', 'emotions' => ['good', 'strict', 'friendly']],
                        ['id' => 'omazh', 'label' => 'omazh', 'emotions' => ['neutral', 'evil']],
                        ['id' => 'saule_ru', 'label' => 'saule_ru', 'emotions' => ['neutral', 'strict', 'whisper']],
                        ['id' => 'zamira_ru', 'label' => 'zamira_ru', 'emotions' => ['neutral', 'strict', 'friendly']],
                        ['id' => 'zhanar_ru', 'label' => 'zhanar_ru', 'emotions' => ['neutral', 'strict', 'friendly']],
                        ['id' => 'yulduz_ru', 'label' => 'yulduz_ru', 'emotions' => ['neutral', 'strict', 'friendly', 'whisper']],
                    ]
                ],
                'en-US' => [
                    'male' => [
                        ['id' => 'john', 'label' => 'john', 'emotions' => []],
                    ],
                    'female' => []
                ]
            ]
        ];
        $out['VOICES_DATA'] = json_encode($voices_data);

        // Обработка действий 
        switch ($this->view_mode) {

            case 'update_settings':
                global $api_version, $api_key, $folder_id, $speaker, $emotion, $emphasis, $disabled, $language, $speed, $format;
                global $voice_gender, $debug;

                $this->config['API_VERSION'] = (string)$api_version;
                $this->config['API_KEY'] = (string)$api_key;
                $this->config['FOLDER_ID'] = (string)$folder_id;
                $this->config['SPEAKER'] = (string)$speaker;
                $this->config['EMOTION'] = (string)$emotion;
                $this->config['EMPHASIS'] = (string)$emphasis;
                $this->config['DISABLED'] = (string)$disabled;
                $this->config['LANGUAGE'] = (string)$language;
                $this->config['SPEED'] = (string)$speed;
                $this->config['FORMAT'] = (string)$format;
                $this->config['VOICE_GENDER'] = (string)$voice_gender;
                $this->config['DEBUG'] = (string)$debug;
                $this->saveConfig();
                $this->logMessage("Settings updated (API: $api_version)");
                $this->redirect('?view_mode=ok');
                break;

            case 'clear_cache':
                array_map('unlink', glob(ROOT . 'cms/cached/voice/*_yandex.*'));
                $this->logMessage("Cache cleared");
                $this->redirect('?view_mode=ok');
                break;

            // ============================================================
            // ДОБАВЛЕНИЕ ЗАПИСИ СЛОВАРЯ (с логированием)
            // ============================================================
            case 'add_emphasis':
                global $search_str, $replace_str, $case;
                $this->logMessage("=== ADD EMPHASIS START ===", true);
                $this->logMessage("Received: search='$search_str', replace='$replace_str', case='$case'", true);

                if (empty($search_str) || empty($replace_str)) {
                    $this->logMessage("Add emphasis: empty fields, setting ERR", true);
                    $out['ERR'] = 1;
                    break;
                }

                $search_safe = DBSafe($search_str);
                $replace_safe = DBSafe($replace_str);
                $case_int = ($case == '1' ? 1 : 0);

                $existing = SQLSelectOne("SELECT * FROM yandex_tts_emphasis WHERE search_str = '$search_safe'");
                $this->logMessage("Existing check: " . ($existing ? "found (ID={$existing['ID']})" : "not found"), true);

                if ($existing) {
                    $id = intval($existing['ID']);
                    $sql = "UPDATE yandex_tts_emphasis SET search_str='$search_safe', replace_str='$replace_safe', `case`=$case_int WHERE ID=$id";
                    $this->logMessage("UPDATE SQL: $sql", true);
                    $result = SQLExec($sql);
                    $this->logMessage("UPDATE result: " . ($result ? "success" : "failed"), true);
                    if ($result === false) {
                        $this->logMessage("UPDATE failed, setting ERR", true);
                        $out['ERR'] = 1;
                        break;
                    }
                    $this->logMessage("Record updated (ID=$id)", true);
                } else {
                    $sql = "INSERT INTO yandex_tts_emphasis (search_str, replace_str, `case`) VALUES ('$search_safe', '$replace_safe', $case_int)";
                    $this->logMessage("INSERT SQL: $sql", true);
                    $result = SQLExec($sql);
                    $this->logMessage("INSERT result: " . ($result ? "success" : "failed"), true);
                    if ($result === false) {
                        $this->logMessage("INSERT failed, setting ERR", true);
                        $out['ERR'] = 1;
                        break;
                    }
                    $row = SQLSelectOne("SELECT LAST_INSERT_ID() as new_id");
                    $insertId = $row ? $row['new_id'] : 'unknown';
                    $this->logMessage("Record inserted, new ID=$insertId", true);
                }

                $this->logMessage("Redirecting to ?view_mode=ok", true);
                $this->redirect('?view_mode=ok');
                exit;
                break;

            case 'delete_emphasis':
                global $id;
                SQLExec('DELETE FROM yandex_tts_emphasis WHERE ID='.intval($id));
                $this->logMessage("Delete emphasis ID=$id", true);
                $this->redirect('?view_mode=ok');
                break;

            case 'emphasis_clear':
                SQLExec('TRUNCATE TABLE yandex_tts_emphasis');
                $this->logMessage("Emphasis table cleared", true);
                $this->redirect('?view_mode=ok');
                break;

            // --------------------------------------------------------------------
            // ИМПОРТ СЛОВАРЯ (с автоопределением кодировок и полным логированием)
            // --------------------------------------------------------------------
            case 'emphasis_import':
                $this->logMessage("=== IMPORT START ===", true);
                if ($this->mode != 'update') {
                    $this->logMessage("Mode not update (probably showing import form)", true);
                    break;
                }
                global $file;
                $this->logMessage("File: " . $file, true);
                if (!file_exists($file)) {
                    $this->logMessage("File not found", true);
                    $this->redirect('?view_mode=err');
                    break;
                }
                $this->logMessage("File exists", true);
                $tmp = LoadFile($file);
                $fileSize = strlen($tmp);
                $this->logMessage("File loaded, size: " . $fileSize, true);

                // Удаляем BOM
                if (substr($tmp, 0, 3) == "\xEF\xBB\xBF") {
                    $tmp = substr($tmp, 3);
                    $this->logMessage("BOM removed", true);
                }

                // Определяем кодировку файла
                $fileEncoding = mb_detect_encoding($tmp, ['UTF-8', 'Windows-1251', 'KOI8-R', 'ISO-8859-1'], true);
                if ($fileEncoding === false) {
                    $fileEncoding = 'UTF-8'; // если не определили, считаем UTF-8
                }
                $this->logMessage("Detected file encoding: " . $fileEncoding, true);

                // Определяем кодировку таблицы
                $tableEncoding = $this->getTableEncoding();
                $this->logMessage("Table data encoding: " . $tableEncoding, true);

                // Приводим содержимое файла к UTF-8 (для внутренней обработки)
                if ($fileEncoding != 'UTF-8') {
                    $tmp = mb_convert_encoding($tmp, 'UTF-8', $fileEncoding);
                    $this->logMessage("Converted file content from $fileEncoding to UTF-8", true);
                }

                $lines = preg_split('/\R/', $tmp);
                $totalLines = count($lines);
                $this->logMessage("Total lines in file: " . $totalLines, true);

                $importedCount = 0;
                $insertedCount = 0;
                $updatedCount = 0;
                $skippedCount = 0;

                foreach ($lines as $index => $line) {
                    $line = trim($line);
                    if ($line === '') {
                        $this->logMessage("Line $index: empty, skipped", true);
                        continue;
                    }

                    $parts = explode(':', $line, 3);
                    $count = count($parts);
                    $this->logMessage("Line $index: parts=$count, line='$line'", true);

                    if ($count == 2 || $count == 3) {
                        $search = str_replace('&#58;', ':', trim($parts[0]));
                        $replace = str_replace('&#58;', ':', trim($parts[1]));
                        $case = ($count == 3 ? intval(trim($parts[2])) : 0);
                        $this->logMessage("Parsed: search='$search', replace='$replace', case=$case", true);

                        // Если кодировка таблицы не UTF-8, конвертируем строки в кодировку таблицы
                        if ($tableEncoding != 'UTF-8' && $tableEncoding != 'UTF8') {
                            $search_db = mb_convert_encoding($search, $tableEncoding, 'UTF-8');
                            $replace_db = mb_convert_encoding($replace, $tableEncoding, 'UTF-8');
                            $this->logMessage("Converted to table encoding: search='$search_db', replace='$replace_db'", true);
                        } else {
                            $search_db = $search;
                            $replace_db = $replace;
                        }

                        // Проверяем существование записи
                        $sql = "SELECT * FROM yandex_tts_emphasis WHERE search_str = '" . DBSafe($search_db) . "'";
                        $this->logMessage("SQL Select: " . $sql, true);
                        $existing = SQLSelectOne($sql);

                        if ($existing) {
                            $this->logMessage("Record exists, updating...", true);
                            $existing['search_str'] = $search_db;
                            $existing['replace_str'] = $replace_db;
                            $existing['case'] = $case;
                            $updateSql = "UPDATE yandex_tts_emphasis SET search_str='" . DBSafe($search_db) . "', replace_str='" . DBSafe($replace_db) . "', `case`=$case WHERE ID=" . intval($existing['ID']);
                            $this->logMessage("SQL Update: " . $updateSql, true);
                            SQLExec($updateSql);
                            $this->logMessage("Updated: $search_db -> $replace_db", true);
                            $updatedCount++;
                        } else {
                            $this->logMessage("No record, inserting...", true);
                            $insertSql = "INSERT INTO yandex_tts_emphasis (search_str, replace_str, `case`) VALUES ('" . DBSafe($search_db) . "', '" . DBSafe($replace_db) . "', $case)";
                            $this->logMessage("SQL Insert: " . $insertSql, true);
                            SQLExec($insertSql);
                            $this->logMessage("Inserted: $search_db -> $replace_db", true);
                            $insertedCount++;
                        }
                        $importedCount++;
                    } else {
                        $this->logMessage("Skipped line (count=$count): '$line'", true);
                        $skippedCount++;
                    }
                }

                $this->logMessage("IMPORT SUMMARY: total lines=$totalLines, processed=$importedCount, inserted=$insertedCount, updated=$updatedCount, skipped=$skippedCount", true);
                $this->redirect('?view_mode=ok');
                break;

            // --------------------------------------------------------------------
            // ЭКСПОРТ СЛОВАРЯ (с автоопределением кодировки и конвертацией в UTF-8)
            // --------------------------------------------------------------------
            case 'emphasis_export':
                $this->logMessage("=== EXPORT START ===", true);
                $tableEncoding = $this->getTableEncoding();
                $this->logMessage("Table data encoding: " . $tableEncoding, true);

                $emphasis = SQLSelect('SELECT * FROM yandex_tts_emphasis ORDER BY search_str');
                if (!$emphasis) {
                    $this->logMessage("No data to export", true);
                    $this->redirect('?view_mode=err');
                    break;
                }

                $data = '';
                foreach ($emphasis as $item) {
                    $search = $item['search_str'];
                    $replace = $item['replace_str'];
                    if ($tableEncoding != 'UTF-8' && $tableEncoding != 'UTF8') {
                        $search = mb_convert_encoding($search, 'UTF-8', $tableEncoding);
                        $replace = mb_convert_encoding($replace, 'UTF-8', $tableEncoding);
                    }
                    $search = str_replace(':', '&#58;', $search);
                    $replace = str_replace(':', '&#58;', $replace);
                    $data .= $search . ':' . $replace . ':' . intval($item['case']) . PHP_EOL;
                }
                header('Content-Type: text/plain; charset=utf-8');
                header('Content-Disposition: attachment; filename=yandex_tts_export_' . date('d-m-Y_H-i-s') . '.dic');
                die($data);
                break;

            case 'ok':
                $out['OK'] = 1;
                break;
            case 'err':
                $out['ERR'] = 1;
                break;
        }

        // Получение списка словаря для отображения
        $emphasis = SQLSelect('SELECT * FROM yandex_tts_emphasis ORDER BY search_str');
        foreach ($emphasis as $item) {
            $out['EMPHASIS_LIST'][] = array(
                'ID' => $item['ID'],
                'search_str' => htmlspecialchars($item['search_str']),
                'replace_str' => htmlspecialchars($item['replace_str']),
                'case' => intval($item['case']),
            );
        }
    }

    // --------------------------------------------------------------------
    // Синтез речи v1 (с кэшированием, повторными попытками, увеличенным таймаутом)
    // --------------------------------------------------------------------

    function ya_speech_v1($text, $lang, $voice, $emotion, $speed, $format) {
        $api_key = isset($this->config['API_KEY']) ? (string)$this->config['API_KEY'] : '';
        if (empty($api_key)) {
            $this->logMessage("ERROR: API key not set for v1", true);
            return false;
        }

        // Генерируем имя кэш-файла
        $cachedVoiceDir = ROOT . 'cms/cached/voice';
        if (!is_dir($cachedVoiceDir)) mkdir($cachedVoiceDir, 0777, true);
        $ext = ($format == 'mp3') ? 'mp3' : (($format == 'lpcm') ? 'pcm' : 'ogg');
        $filename = md5($text . $voice . $emotion . $speed) . '_yandex';
        $cachedFileName = $cachedVoiceDir . '/' . $filename . '.' . $ext;

        // Проверяем кэш
        if (file_exists($cachedFileName) && filesize($cachedFileName) > 0) {
            $this->logMessage("v1 Using cached file: $cachedFileName", true);
            return $cachedFileName;
        }

        $url = 'https://tts.api.cloud.yandex.net/speech/v1/tts:synthesize';
        $params = [
            'text' => $text,
            'lang' => $lang,
            'voice' => $voice,
            'emotion' => $emotion,
            'speed' => (string)$speed,
            'format' => $format
        ];
        $postData = http_build_query($params);
        $headers = [
            'Authorization: Api-Key ' . $api_key,
            'Content-Type: application/x-www-form-urlencoded'
        ];

        $this->logMessage("v1 Request: voice=$voice, emotion=$emotion, text='$text'");

        // Попытки (до 3 раз)
        $maxAttempts = 3;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);           // увеличен до 60 сек
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);    // таймаут соединения 10 сек
            curl_setopt($ch, CURLOPT_HEADER, true);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $body = substr($response, $headerSize);

            if (curl_errno($ch)) {
                $error = curl_error($ch);
                curl_close($ch);
                $this->logMessage("v1 CURL error (attempt $attempt): $error", true);
                if ($attempt < $maxAttempts) {
                    sleep(1); // пауза перед повторной попыткой
                    continue;
                }
                return false;
            }
            curl_close($ch);

            if ($httpCode != 200) {
                $this->logMessage("v1 HTTP $httpCode (attempt $attempt): $body", true);
                if ($attempt < $maxAttempts) {
                    sleep(1);
                    continue;
                }
                return false;
            }

            // Успех – сохраняем файл
            file_put_contents($cachedFileName, $body);
            $this->logMessage("v1 Saved: $cachedFileName", true);
            return $cachedFileName;
        }

        return false;
    }

    // --------------------------------------------------------------------
    // Синтез речи v3 (с кэшированием, повторными попытками, увеличенным таймаутом)
    // --------------------------------------------------------------------

    function ya_speech_v3($text, $lang, $voice, $emotion, $speed, $format) {
        $api_key = isset($this->config['API_KEY']) ? (string)$this->config['API_KEY'] : '';
        $folder_id = isset($this->config['FOLDER_ID']) ? (string)$this->config['FOLDER_ID'] : '';

        if (empty($api_key)) {
            $this->logMessage("ERROR: API key not set for v3", true);
            return false;
        }
        if (empty($folder_id)) {
            $this->logMessage("ERROR: FOLDER_ID not set for v3", true);
            return false;
        }

        // Генерируем имя кэш-файла
        $cachedVoiceDir = ROOT . 'cms/cached/voice';
        if (!is_dir($cachedVoiceDir)) mkdir($cachedVoiceDir, 0777, true);
        $ext = ($format == 'mp3') ? 'mp3' : (($format == 'lpcm') ? 'wav' : 'ogg');
        $filename = md5($text . $voice . $emotion . $speed) . '_yandex';
        $cachedFileName = $cachedVoiceDir . '/' . $filename . '.' . $ext;

        // Проверяем кэш
        if (file_exists($cachedFileName) && filesize($cachedFileName) > 0) {
            $this->logMessage("v3 Using cached file: $cachedFileName", true);
            return $cachedFileName;
        }

        $url = 'https://tts.api.cloud.yandex.net/tts/v3/utteranceSynthesis';
        $payload = [
            'text' => $text,
            'hints' => [
                ['voice' => $voice],
                ['role'  => $emotion],
                ['speed' => (float)$speed]
            ]
        ];
        $formatMap = [
            'oggopus' => 'OGG_OPUS',
            'mp3'     => 'MP3',
        ];
        if (isset($formatMap[$format])) {
            $payload['outputAudioSpec'] = [
                'containerAudio' => [
                    'containerAudioType' => $formatMap[$format]
                ]
            ];
        }

        $json = json_encode($payload);
        $headers = [
            'Authorization: Api-Key ' . $api_key,
            'Content-Type: application/json',
            'x-folder-id: ' . $folder_id
        ];

        $this->logMessage("v3 Request: voice=$voice, emotion=$emotion, text='$text'");

        $maxAttempts = 3;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_HEADER, true);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $body = substr($response, $headerSize);

            if (curl_errno($ch)) {
                $error = curl_error($ch);
                curl_close($ch);
                $this->logMessage("v3 CURL error (attempt $attempt): $error", true);
                if ($attempt < $maxAttempts) {
                    sleep(1);
                    continue;
                }
                return false;
            }
            curl_close($ch);

            if ($httpCode != 200) {
                $this->logMessage("v3 HTTP $httpCode (attempt $attempt): $body", true);
                if ($attempt < $maxAttempts) {
                    sleep(1);
                    continue;
                }
                return false;
            }

            // Парсим ответ
            $responseData = json_decode($body, true);
            if (!$responseData || !isset($responseData['result']['audioChunk']['data'])) {
                $this->logMessage("v3 Invalid response format (attempt $attempt): $body", true);
                if ($attempt < $maxAttempts) {
                    sleep(1);
                    continue;
                }
                return false;
            }

            $audioData = '';
            $chunks = $responseData['result']['audioChunk'];
            if (isset($chunks['data'])) {
                $audioData = base64_decode($chunks['data']);
            } elseif (is_array($chunks)) {
                foreach ($chunks as $chunk) {
                    if (isset($chunk['data'])) {
                        $audioData .= base64_decode($chunk['data']);
                    }
                }
            }

            if (empty($audioData)) {
                $this->logMessage("v3 No audio data decoded (attempt $attempt)", true);
                if ($attempt < $maxAttempts) {
                    sleep(1);
                    continue;
                }
                return false;
            }

            // Сохраняем файл
            file_put_contents($cachedFileName, $audioData);
            $this->logMessage("v3 Saved: $cachedFileName", true);
            return $cachedFileName;
        }

        return false;
    }

    // --------------------------------------------------------------------
    // Обработка событий
    // --------------------------------------------------------------------

    function processSubscription($event, &$details) {
        $this->getConfig();

        if (isset($this->config['DISABLED']) && $this->config['DISABLED'] == '1') {
            $this->logMessage("Module disabled");
            return;
        }

        $api_version = isset($this->config['API_VERSION']) ? (string)$this->config['API_VERSION'] : 'v1';
        $emphasis_enabled = isset($this->config['EMPHASIS']) ? (string)$this->config['EMPHASIS'] : '0';
        $lang = isset($this->config['LANGUAGE']) ? (string)$this->config['LANGUAGE'] : 'ru-RU';
        $voice = isset($this->config['SPEAKER']) ? (string)$this->config['SPEAKER'] : 'alena';
        $emotion = isset($this->config['EMOTION']) ? (string)$this->config['EMOTION'] : 'neutral';
        $speed = isset($this->config['SPEED']) ? (float)$this->config['SPEED'] : 1.0;
        $format = isset($this->config['FORMAT']) ? (string)$this->config['FORMAT'] : 'oggopus';

        $level = $details['level'] ?? 0;
        $message = $details['message'] ?? '';
        $destination = $details['destination'] ?? '';
        $ignoreVoice = $details['ignoreVoice'] ?? false;

        if ($details['SOURCE'] ?? false) {
            if ($event == 'SAY' || $event == 'SAYTO' || $event == 'SAYREPLY') {
                $message = $details['MESSAGE'] ?? '';
                $level = $details['IMPORTANCE'] ?? 0;
            }
        }

        $minLevel = (int)getGlobal('minMsgLevel');
        if ($event == 'SAY' && $level < $minLevel) {
            $this->logMessage("SAY level $level < $minLevel, skipped");
            return;
        }
        if ($ignoreVoice) {
            $this->logMessage("ignoreVoice=true, skipped");
            return;
        }

        $this->logMessage("Processing $event (API $api_version): '$message'");

        // Словарь ударений
        if ($emphasis_enabled == '1') {
            $emphasis = SQLSelect('SELECT * FROM yandex_tts_emphasis');
            foreach ($emphasis as $item) {
                if ($item['case']) {
                    $message = str_replace($item['search_str'], $item['replace_str'], $message);
                } else {
                    $message = preg_replace_callback('/(' . preg_quote($item['search_str'], '/') . ')/ui', function ($match) use ($item) {
                        return $item['replace_str'];
                    }, $message);
                }
            }
        }

        if ($api_version == 'v1') {
            $cachedFile = $this->ya_speech_v1($message, $lang, $voice, $emotion, $speed, $format);
        } else {
            $cachedFile = $this->ya_speech_v3($message, $lang, $voice, $emotion, $speed, $format);
        }

        if ($cachedFile && file_exists($cachedFile) && filesize($cachedFile) > 0) {
            $this->logMessage("Synthesis successful");
            processSubscriptionsSafe('SAY_CACHED_READY', [
                'level' => $level,
                'tts_engine' => 'yandex_tts_' . $api_version,
                'filename' => $cachedFile,
                'destination' => $destination,
                'event' => $event,
                'message' => $message,
            ]);
            if ($event == 'SAY' && $level >= $minLevel) {
                playSound($cachedFile, 1, $level);
            }
            if ($details['SOURCE'] ?? false) {
                $details['CACHED_FILENAME'] = $cachedFile;
                $details['tts_engine'] = 'yandex_tts';
            }
            $details['BREAK'] = true;
        } else {
            $this->logMessage("Synthesis failed");
        }
    }

    // --------------------------------------------------------------------
    // Стандартные методы
    // --------------------------------------------------------------------

    function usual(&$out) {
        $this->admin($out);
    }

    function install($data = '') {
        subscribeToEvent($this->name, 'SAY', '', 100);
        subscribeToEvent($this->name, 'SAYTO', '', 100);
        subscribeToEvent($this->name, 'ASK', '', 100);
        subscribeToEvent($this->name, 'SAYREPLY', '', 100);
        parent::install();
    }

    function dbInstall($data) {
        // Определяем collation: если используется utf8mb4, то utf8mb4_bin, иначе utf8_bin
        $collation = 'utf8_bin';
        $charset = 'utf8';
        // Проверяем, какая кодировка используется в БД
        $dbCharset = SQLSelectOne("SELECT @@character_set_database as charset");
        if ($dbCharset && strpos(strtolower($dbCharset['charset']), 'utf8mb4') !== false) {
            $collation = 'utf8mb4_bin';
            $charset = 'utf8mb4';
        }
        $data = <<<EOD
 yandex_tts_emphasis: ID int(10) unsigned NOT NULL auto_increment
 yandex_tts_emphasis: search_str text COLLATE $collation
 yandex_tts_emphasis: replace_str text
 yandex_tts_emphasis: case boolean NOT NULL DEFAULT FALSE
EOD;
        parent::dbInstall($data);
        // Проверяем, существует ли уже индекс, чтобы избежать ошибки Duplicate key
        $indexExists = SQLSelectOne("SHOW INDEX FROM yandex_tts_emphasis WHERE Key_name = 'idx_search_str'");
        if (!$indexExists) {
            SQLExec("ALTER TABLE yandex_tts_emphasis ADD UNIQUE INDEX idx_search_str (search_str(255))");
            $this->logMessage("Unique index idx_search_str added", true);
        } else {
            $this->logMessage("Unique index idx_search_str already exists, skipping", true);
        }		
    }

    function uninstall() {
        SQLExec('DROP TABLE IF EXISTS yandex_tts_emphasis');
        unsubscribeFromEvent($this->name, 'SAY');
        unsubscribeFromEvent($this->name, 'SAYTO');
        unsubscribeFromEvent($this->name, 'ASK');
        unsubscribeFromEvent($this->name, 'SAYREPLY');
        parent::uninstall();
    }
}
?>
