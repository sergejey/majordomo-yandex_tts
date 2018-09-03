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
  $this->name="yandex_tts";
  $this->title="Yandex TTS";
  $this->module_category="<#LANG_SECTION_APPLICATIONS#>";
  $this->checkInstalled();
}
/**
* saveParams
*
* Saving module parameters
*
* @access public
*/
function saveParams($data=0) {
 $p=array();
 if (IsSet($this->id)) {
  $p["id"]=$this->id;
 }
 if (IsSet($this->view_mode)) {
  $p["view_mode"]=$this->view_mode;
 }
 if (IsSet($this->edit_mode)) {
  $p["edit_mode"]=$this->edit_mode;
 }
 if (IsSet($this->tab)) {
  $p["tab"]=$this->tab;
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
   $this->id=$id;
  }
  if (isset($mode)) {
   $this->mode=$mode;
  }
  if (isset($view_mode)) {
   $this->view_mode=$view_mode;
  }
  if (isset($edit_mode)) {
   $this->edit_mode=$edit_mode;
  }
  if (isset($tab)) {
   $this->tab=$tab;
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
  $out=array();
  if ($this->action=='admin') {
   $this->admin($out);
  } else {
   $this->usual($out);
  }
  if (IsSet($this->owner->action)) {
   $out['PARENT_ACTION']=$this->owner->action;
  }
  if (IsSet($this->owner->name)) {
   $out['PARENT_NAME']=$this->owner->name;
  }
  $out['VIEW_MODE']=$this->view_mode;
  $out['EDIT_MODE']=$this->edit_mode;
  $out['MODE']=$this->mode;
  $out['ACTION']=$this->action;
  $this->data=$out;
  $p=new parser(DIR_TEMPLATES.$this->name."/".$this->name.".html", $this->data, $this);
  $this->result=$p->result;
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
	$out['ACCESS_KEY'] = $this->config['ACCESS_KEY'];
	$out['SPEAKER'] = $this->config['SPEAKER'];
	$out['EMOTION'] = $this->config['EMOTION'];
	$out['EMPHASIS'] = $this->config['EMPHASIS'];
	$out['DISABLED'] = $this->config['DISABLED'];
	switch($this->view_mode) {
		case 'update_settings':
			global $access_key;
			$this->config['ACCESS_KEY'] = $access_key;
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
			array_map('unlink', glob(ROOT.'cms/cached/voice/*_yandex.mp3'));
			$this->redirect('?view_mode=ok');
			break;
		case 'add_emphasis':
			global $search_str, $replace_str;
			if(!empty($search_str) && !empty($replace_str)) {
				if($query = SQLSelectOne("SELECT * FROM `yandex_tts_emphasis` WHERE `search_str` LIKE '".DBSafe($search_str)."'")) {
					$query['search_str'] = $search_str;
					$query['replace_str'] = $replace_str;
					if(SQLUpdate('yandex_tts_emphasis', $query)) {
						$this->redirect('?view_mode=ok');
					} else {
						$this->redirect('?view_mode=err');
					}
				} else {
					$query = array();
					$query['search_str'] = $search_str;
					$query['replace_str'] = $replace_str;
					if(SQLInsert('yandex_tts_emphasis', $query)) {
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
			if(SQLExec('DELETE FROM `yandex_tts_emphasis` WHERE `ID` = '.intval($id))) {
				$this->redirect('?view_mode=ok');
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
	$out['EMPHASIS_LIST'] = SQLSelect('SELECT * FROM `yandex_tts_emphasis` ORDER BY `search_str`');
}

/**
* FrontEnd
*
* Module frontend
*
* @access public
*/
function usual(&$out) {
 $this->admin($out);
}
 function processSubscription($event, &$details) {
  $this->getConfig();
  if ($event=='SAY' && !$this->config['DISABLED'] && !$details['ignoreVoice']) {
    $level=$details['level'];
    $message=$details['message'];
    
	if($this->config['EMPHASIS']) {
		$emphasis = SQLSelect('SELECT `search_str`, `replace_str` FROM `yandex_tts_emphasis`');
		foreach($emphasis as $item) {
			$message = preg_replace_callback('/('.preg_quote($item['search_str'], '/').')/ui', function($match) use($item) {
				return $item['replace_str'];
			}, $message);
		}
	}
	
    $accessKey=$this->config['ACCESS_KEY'];
	$speaker=$this->config['SPEAKER'];
	$emotion=$this->config['EMOTION'];
    
    if ($level >= (int)getGlobal('minMsgLevel') && $accessKey!='')
    {
        $filename       = md5($message) . '_yandex.mp3';
        $cachedVoiceDir = ROOT . 'cms/cached/voice';
        $cachedFileName = $cachedVoiceDir . '/' . $filename;

        $base_url       = 'https://tts.voicetech.yandex.net/generate?';        

        if (!file_exists($cachedFileName))
        {

           $lang = SETTINGS_SITE_LANGUAGE;
           $qs = http_build_query(array('format' => 'mp3', 'lang' => $lang, 'speaker' => $speaker, emotion => $emotion, 'key' => $accessKey, 'text' => $message));

           try
           {
              $contents = file_get_contents($base_url . $qs);
           }
           catch (Exception $e)
           {
              registerError('yandextts', get_class($e) . ', ' . $e->getMessage());
           }
   
           if (isset($contents))
           {
              CreateDir($cachedVoiceDir);
              SaveFile($cachedFileName, $contents);
           }
        } else {
         @touch($cachedFileName);
        }
        if (file_exists($cachedFileName)) {
          playSound($cachedFileName, 1, $level);
          $details['ignoreVoice']=1;
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
 function install($data='') {
  subscribeToEvent($this->name, 'SAY', '', 10);
  parent::install();
 }
 
 function dbInstall($data) {
$data = <<<EOD
 yandex_tts_emphasis: ID int(10) unsigned NOT NULL auto_increment
 yandex_tts_emphasis: search_str text 
 yandex_tts_emphasis: replace_str text 
EOD;
  parent::dbInstall($data);
 }
 
 function uninstall() {
  SQLExec('DROP TABLE IF EXISTS `yandex_tts_emphasis`');
  parent::uninstall();
 }
 
// --------------------------------------------------------------------
}
/*
*
* TW9kdWxlIGNyZWF0ZWQgTWFyIDEzLCAyMDE2IHVzaW5nIFNlcmdlIEouIHdpemFyZCAoQWN0aXZlVW5pdCBJbmMgd3d3LmFjdGl2ZXVuaXQuY29tKQ==
*
*/
