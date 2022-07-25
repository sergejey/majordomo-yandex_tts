<?php
/**
 * Yandex TTS
 * @package project
 * @author Wizard <sergejey@gmail.com>
 * @copyright http://majordomo.smartliving.ru/ (c)
 * @version 0.1 (wizard, 13:03:10 [Mar 13, 2016])
 */
//
//
class yandex_tts extends module {
    /**
     * yandex_tts
     *
     * Module class constructor
     *
     * @access private
     */
    function yandex_tts() {
        $this->name = "yandex_tts";
        $this->title = "Yandex TTS";
        $this->module_category = "<#LANG_SECTION_APPLICATIONS#>";
        $this->checkInstalled();
        $this->MyDebug = false;
    }
    /**
     * saveParams
     *
     * Saving module parameters
     *
     * @access public
     */
    function saveParams($data = 0) {
        $p = array();
        if (IsSet($this->id)) {
            $p["id"] = $this->id;
        }
        if (IsSet($this->view_mode)) {
            $p["view_mode"] = $this->view_mode;
        }
        if (IsSet($this->edit_mode)) {
            $p["edit_mode"] = $this->edit_mode;
        }
        if (IsSet($this->tab)) {
            $p["tab"] = $this->tab;
        }
        return parent::saveParams($p);
    }
    /**
     * getParams
     *
     * Getting module parameters from query string
     *
     * @access public
     */
    function getParams() {
        global $id;
        global $mode;
        global $view_mode;
        global $edit_mode;
        global $tab;
        if (isset($id)) {
            $this->id = $id;
        }
        if (isset($mode)) {
            $this->mode = $mode;
        }
        if (isset($view_mode)) {
            $this->view_mode = $view_mode;
        }
        if (isset($edit_mode)) {
            $this->edit_mode = $edit_mode;
        }
        if (isset($tab)) {
            $this->tab = $tab;
        }
    }
    /**
     * Run
     *
     * Description
     *
     * @access public
     */
    function run() {
        global $session;
        $out = array();
        if ($this->action == 'admin') {
            $this->admin($out);
        } else {
            $this->usual($out);
        }
        if (IsSet($this->owner->action)) {
            $out['PARENT_ACTION'] = $this->owner->action;
        }
        if (IsSet($this->owner->name)) {
            $out['PARENT_NAME'] = $this->owner->name;
        }
        $out['VIEW_MODE'] = $this->view_mode;
        $out['EDIT_MODE'] = $this->edit_mode;
        $out['MODE'] = $this->mode;
        $out['ACTION'] = $this->action;
        $this->data = $out;
        $p = new parser(DIR_TEMPLATES . $this->name . "/" . $this->name . ".html", $this->data, $this);
        $this->result = $p->result;
    }
    /**
     * BackEnd
     *
     * Module backend
     *
     * @access public
     */
    function admin(&$out) {
        $this->getConfig();
        $out['NEW_AUTH'] = $this->config['NEW_AUTH'];
        $out['ACCESS_KEY'] = $this->config['ACCESS_KEY'];
        $out['OAUTH'] = $this->config['OAUTH'];
        $out['FOLDERID'] = $this->config['FOLDERID'];
        $out['SPEAKER'] = $this->config['SPEAKER'];
        $out['EMOTION'] = $this->config['EMOTION'];
        $out['EMPHASIS'] = $this->config['EMPHASIS'];
        $out['DISABLED'] = $this->config['DISABLED'];
        switch ($this->view_mode) {
            case 'update_settings':
                global $new_auth;
                $this->config['NEW_AUTH'] = $new_auth;
                global $access_key;
                $this->config['ACCESS_KEY'] = $access_key;
                global $oauth;
                $this->config['OAUTH'] = $oauth;
                global $folderID;
                $this->config['FOLDERID'] = $folderID;
                global $speaker;
                $this->config['SPEAKER'] = $speaker;
                global $emotion;
                $this->config['EMOTION'] = $emotion;
                global $emphasis;
                $this->config['EMPHASIS'] = $emphasis;
                global $disabled;
                $this->config['DISABLED'] = $disabled;
                $this->saveConfig();
                $this->redirect('?view_mode=ok');
            break;
            case 'clear_cache':
                array_map('unlink', glob(ROOT . 'cms/cached/voice/*_yandex.mp3'));
                $this->redirect('?view_mode=ok');
            break;
            case 'add_emphasis':
                global $search_str, $replace_str, $case;
                if (!empty($search_str) && !empty($replace_str)) {
                    if ($query = SQLSelectOne("SELECT * FROM `yandex_tts_emphasis` WHERE `search_str` LIKE '" . DBSafe($search_str) . "'")) {
                        $query['search_str'] = $search_str;
                        $query['replace_str'] = $replace_str;
                        $query['case'] = ($case == '1' ? 1 : 0);
                        if (SQLUpdate('yandex_tts_emphasis', $query)) {
                            $this->redirect('?view_mode=ok');
                        } else {
                            $this->redirect('?view_mode=err');
                        }
                    } else {
                        $query = array();
                        $query['search_str'] = $search_str;
                        $query['replace_str'] = $replace_str;
                        $query['case'] = ($case == '1' ? 1 : 0);
                        if (SQLInsert('yandex_tts_emphasis', $query)) {
                            $this->redirect('?view_mode=ok');
                        } else {
                            $this->redirect('?view_mode=err');
                        }
                    }
                } else {
                    $this->redirect('?view_mode=err');
                }
            break;
            case 'delete_emphasis':
                global $id;
                if (SQLExec('DELETE FROM `yandex_tts_emphasis` WHERE `ID` = ' . intval($id))) {
                    $this->redirect('?view_mode=ok');
                } else {
                    $this->redirect('?view_mode=err');
                }
            break;
            case 'emphasis_clear':
                if (SQLExec('TRUNCATE TABLE `yandex_tts_emphasis`')) {
                    $this->redirect('?view_mode=ok');
                } else {
                    $this->redirect('?view_mode=err');
                }
            break;
            case 'emphasis_import':
                if ($this->mode == 'update') {
                    $error = FALSE;
                    global $file;
                    if (file_exists($file)) {
                        $tmp = LoadFile($file);
                        $lines = mb_split("\n", $tmp);
                        foreach ($lines as $line) {
                            $line = mb_split(':', $line);
                            $count = count($line);
                            if ($count == 2 || $count == 3) {
                                $line[0] = str_replace('&#58;', ':', $line[0]);
                                $line[1] = str_replace('&#58;', ':', $line[1]);
                                $line[2] = ($count == 3 ? intval($line[2]) : 0);
                                if ($query = SQLSelectOne("SELECT * FROM `yandex_tts_emphasis` WHERE `search_str` LIKE '" . DBSafe($line[0]) . "'")) {
                                    $query['search_str'] = $line[0];
                                    $query['replace_str'] = $line[1];
                                    $query['case'] = ($line[2] == 1 ? 1 : 0);
                                    SQLUpdate('yandex_tts_emphasis', $query);
                                } else {
                                    $query = array();
                                    $query['search_str'] = $line[0];
                                    $query['replace_str'] = $line[1];
                                    $query['case'] = ($line[2] == 1 ? 1 : 0);
                                    SQLInsert('yandex_tts_emphasis', $query);
                                }
                            }
                        }
                        $this->redirect('?view_mode=ok');
                    } else {
                        $this->redirect('?view_mode=err');
                    }
                }
            break;
            case 'emphasis_export':
                if ($emphasis = SQLSelect('SELECT * FROM `yandex_tts_emphasis` ORDER BY `search_str`')) {
                    $data = '';
                    foreach ($emphasis as $item) {
                        $item['search_str'] = str_replace(':', '&#58;', $item['search_str']);
                        $item['replace_str'] = str_replace(':', '&#58;', $item['replace_str']);
                        $item['case'] = intval($item['case']);
                        $data.= $item['search_str'] . ':' . $item['replace_str'] . ':' . $item['case'] . PHP_EOL;
                    }
                    header('Content-Disposition: attachment; filename=yandex_tts_export_' . date('d-m-Y_H-i-s') . '.dic');
                    header('Content-Type: text/plain');
                    die($data);
                } else {
                    $this->redirect('?view_mode=err');
                }
            break;
            case 'ok':
                $out['OK'] = 1;
            break;
            case 'err':
                $out['ERR'] = 1;
            break;
        }
        // Show emphasis list
        $emphasis = SQLSelect('SELECT * FROM `yandex_tts_emphasis` ORDER BY `search_str`');
        foreach ($emphasis as $item) {
            $out['EMPHASIS_LIST'][] = array('ID' => $item['ID'], 'search_str' => htmlspecialchars($item['search_str']), 'replace_str' => htmlspecialchars($item['replace_str']), 'case' => intval($item['case']),);
        }
    }
    /**
     * FrontEnd
     *
     * Module frontend
     *
     * @access public
     */
    function ya_speech($phase, $lang = 'ru-RU', $voice = 'oksana', $emotion = 'good') {
        $this->getConfig();
        $token = $this->config['TOKEN'];
        $token_expire = $this->config['TOKENEXPIRE'];
        $oauth = $this->config['OAUTH'];
        $folderId = $this->config['FOLDERID'];
        //$this->saveConfig();
        if ($this->MyDebug) DebMes($this->getConfig());
        if (!isset($token) || (strtotime($token_expire) < time())) {
            $url = 'https://iam.api.cloud.yandex.net/iam/v1/tokens';
            $post = "{\"yandexPassportOauthToken\":\"$oauth\"}";
            $ch2 = curl_init();
            curl_setopt($ch2, CURLOPT_AUTOREFERER, TRUE);
            curl_setopt($ch2, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch2, CURLOPT_URL, $url);
            curl_setopt($ch2, CURLOPT_FOLLOWLOCATION, TRUE);
            curl_setopt($ch2, CURLOPT_HEADER, false);
            curl_setopt($ch2, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false);
            if ($post !== false) {
                curl_setopt($ch2, CURLOPT_POST, 1);
                curl_setopt($ch2, CURLOPT_POSTFIELDS, $post);
            }
            $res = curl_exec($ch2);
            curl_close($ch2);
            $res = json_decode($res);
            $token = $res->iamToken;
            $token_expire = $res->expiresAt;
            $this->config['TOKEN'] = $token;
            $this->config['TOKENEXPIRE'] = $token_expire;
            $this->saveConfig();
            if ($this->MyDebug) DebMes('Yandex TTS generate new Token');
        }
        $url = "https://tts.api.cloud.yandex.net/speech/v1/tts:synthesize";
        $lang = str_replace('_', '-', $lang);
        $post = "text=" . urlencode(strip_tags($phase)) . "&lang=$lang&folderId=${folderId}&emotion=$emotion&voice=$voice";
        if ($this->MyDebug) DebMes('post request: ' . $post);
        $headers = ['Authorization: Bearer ' . $token];
        if ($this->MyDebug) DebMes('Post headers: ' . $headers);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_AUTOREFERER, TRUE);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        if ($post !== false) {
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            if ($this->MyDebug) DebMes("Error: " . curl_error($ch));
        }
        if (curl_getinfo($ch, CURLINFO_HTTP_CODE) != 200) {
            $decodedResponse = json_decode($response, true);
            if ($this->MyDebug) DebMes("Error code: " . $decodedResponse["error_code"]);
            if ($this->MyDebug) DebMes("Error message: " . $decodedResponse["error_message"]);
        } else {
            $filename = md5($phase) . '_yandex';
            $cachedVoiceDir = ROOT . 'cms/cached/voice';
            $cachedFileName = $cachedVoiceDir . '/' . $filename;
            file_put_contents($cachedFileName . '.ogg', $response);
            if (IsWindowsOS()) {
                $ffmpeg_path = SERVER_ROOT . '/apps/ffmpeg/ffmpeg.exe';
                if (defined('PATH_TO_FFMPEG')) {
                    if ($this->MyDebug) {
                        DebMes('PATH_TO_FFMPEG = ' . PATH_TO_FFMPEG);
                    }
                    $ffmpeg_path = PATH_TO_FFMPEG;
                }
                if (file_exists($ffmpeg_path)) {
                    if ($this->MyDebug) {
                        DebMes('PATH_TO_FFMPEG = Not Defined. Using default ffmpeg: ' . $default_ffmpeg);
                    }
                } else {
                    DebMes('yandex_tts: Error! Cannot find ffmpeg.exe! Define right PATH_TO_FFMPEG in config.php.');
                }
                exec($ffmpeg_path . ' -y -i ' . $cachedFileName . '.ogg -acodec mp3 ' . $cachedFileName . '.mp3');
            } else {
                exec('ffmpeg -y -i ' . $cachedFileName . '.ogg -acodec mp3 ' . $cachedFileName . '.mp3');
            }
            unlink($cachedFileName . '.ogg');
            return file_get_contents($cachedFileName . '.mp3');
        }
        curl_close($ch);
    }
    function usual(&$out) {
        $this->admin($out);
    }
    function processSubscription($event, &$details) {
        $this->getConfig();
        if ($this->MyDebug) DebMes('Yandex TTS processSubscription');
        $level = $details['level'];
        $message = $details['message'];
        $destination = $details['destination'];
        $filename = md5($message) . '_yandex.mp3';
        $cachedVoiceDir = ROOT . 'cms/cached/voice';
        $cachedFileName = $cachedVoiceDir . '/' . $filename;
        if ($this->MyDebug) DebMes('cachedFileName: ' . $cachedFileName);
        //$this->config['DISABLED'] = false;
	
	// add for terminals 2
	if ($details['SOURCE']) {
	    if (($event == 'SAY' OR $event == 'SAYTO' OR $event == 'SAYREPLY') AND !$this->config['DISABLED']) {
		DebMes("Processing $event: " . json_encode($details, JSON_UNESCAPED_UNICODE), 'terminals');
		$message                    = $details['MESSAGE'];
		$level                      = $details['IMPORTANCE'];
		$mmd5                       = md5($message);
		$cachedFileName             = ROOT . 'cms/cached/voice/yandex_' . $mmd5 . '.mp3';
	    }
	}
	    
        if (($event == 'SAY' || $event == 'SAYTO' || $event == 'ASK') && !$this->config['DISABLED'] && !$details['ignoreVoice'] && ($level >= (int)getGlobal('minMsgLevel'))) {
            if ($this->config['EMPHASIS']) {
                $emphasis = SQLSelect('SELECT * FROM `yandex_tts_emphasis`');
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
            if ($this->config['NEW_AUTH']) {
                $new_auth = 1;
            } else {
                $new_auth = 0;
            }
            $oauth = $this->config['OAUTH'];
            $folderID = $this->config['FOLDERID'];
            $accessKey = $this->config['ACCESS_KEY'];
            $speaker = $this->config['SPEAKER'];
            $emotion = $this->config['EMOTION'];
            if ($this->MyDebug) DebMes($oauth . ' ' . $folderID);
            if (($oauth != '') && ($folderID != '') || ($accessKey != '')) {
                $base_url = 'https://tts.voicetech.yandex.net/generate?';
                if (!file_exists($cachedFileName) || !filesize($cachedFileName)) {
                    $lang = SETTINGS_SITE_LANGUAGE_CODE;
                    $qs = http_build_query(array('format' => 'mp3', 'lang' => $lang, 'speaker' => $speaker, 'emotion' => $emotion, 'key' => $accessKey, 'text' => $message));
                    try {
                        if ($new_auth) {
                            $contents = $this->ya_speech($message, $lang, $speaker, $emotion);
                            if ($this->MyDebug) DebMes('new_auth');
                        } else {
                            $contents = file_get_contents($base_url . $qs);
                            if ($this->MyDebug) DebMes($qs);
                        }
                    }
                    catch(Exception $e) {
                        registerError('yandextts', get_class($e) . ', ' . $e->getMessage());
                    }
                    if (isset($contents)) {
                        CreateDir($cachedVoiceDir);
                        SaveFile($cachedFileName, $contents);
                    }
                } else {
                    @touch($cachedFileName);
                }

                // add for terminals 2
                if ($details['SOURCE']) {
                    if (($event == 'SAY' OR $event == 'SAYTO' OR $event == 'SAYREPLY') AND !$this->config['DISABLED']) {
                           DebMes("Processing $event: " . json_encode($details, JSON_UNESCAPED_UNICODE), 'terminals');
                           $details['CACHED_FILENAME'] = $cachedFileName;
                           $details['tts_engine']      = 'yandex_tts';
                           if (file_exists($cachedFileName) && filesize($cachedFileName)) {
                               processSubscriptions('SAY_CACHED_READY', $details);
                               $details['BREAK'] = true;
                           }
                       }
                       return true;
                  }
				
                if (file_exists($cachedFileName) && filesize($cachedFileName)) {
                    processSubscriptionsSafe('SAY_CACHED_READY', array('level' => $level, 'tts_engine' => 'yandex', 'filename' => $cachedFileName, 'destination' => $destination, 'event' => $event, 'message' => $message,));
                    if ($event == 'SAY' && $level >= (int)getGlobal('minMsgLevel')) {
                        playSound($cachedFileName, 1, $level);
                    }
                    //$details['ignoreVoice']=1;
                    
                }
            }
        }
    }
    /**
     * Install
     *
     * Module installation routine
     *
     * @access private
     */
    function install($data = '') {
        subscribeToEvent($this->name, 'SAY', '', 100);
        subscribeToEvent($this->name, 'SAYTO', '', 100);
        subscribeToEvent($this->name, 'ASK', '', 100);
        subscribeToEvent($this->name, 'SAYREPLY', '', 100);
        parent::install();
    }
    function dbInstall($data) {
        $data = <<<EOD
 yandex_tts_emphasis: ID int(10) unsigned NOT NULL auto_increment
 yandex_tts_emphasis: search_str text
 yandex_tts_emphasis: replace_str text
 yandex_tts_emphasis: case boolean NOT NULL DEFAULT FALSE
EOD;
        parent::dbInstall($data);
    }
    function uninstall() {
        SQLExec('DROP TABLE IF EXISTS `yandex_tts_emphasis`');
        unsubscribeFromEvent($this->name, 'SAY');
        unsubscribeFromEvent($this->name, 'SAYTO');
        unsubscribeFromEvent($this->name, 'ASK');
        unsubscribeFromEvent($this->name, 'SAYREPLY');
        parent::uninstall();
    }
    // --------------------------------------------------------------------
    
}
/*
 *
 * TW9kdWxlIGNyZWF0ZWQgTWFyIDEzLCAyMDE2IHVzaW5nIFNlcmdlIEouIHdpemFyZCAoQWN0aXZlVW5pdCBJbmMgd3d3LmFjdGl2ZXVuaXQuY29tKQ==
 *
*/
