<?php

/* Modern Contact Plugin for Dokuwiki
 * 
 * Copyright (C) 2008 Bob Baddeley (bobbaddeley.com)
 * Copyright (C) 2010-2012 Marvin Thomas Rabe (marvinrabe.de)
 * 
 * This program is free software; you can redistribute it and/or modify it under the terms
 * of the GNU General Public License as published by the Free Software Foundation; either
 * version 3 of the License, or (at your option) any later version.
 * 
 * This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
 * without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See the GNU General Public License for more details.
 * 
 * You should have received a copy of the GNU General Public License along with this program;
 * if not, see <http://www.gnu.org/licenses/>. */

/**
 * Embed a send email form onto any page
 * @license GNU General Public License 3 <http://www.gnu.org/licenses/>
 * @author Bob Baddeley <bob@bobbaddeley.com>
 * @author Marvin Thomas Rabe <mrabe@marvinrabe.de>
 */

if(!defined('DOKU_INC')) define('DOKU_INC',realpath(dirname(__FILE__).'/../../').'/');
if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');
require_once(DOKU_PLUGIN.'syntax.php');
require_once(DOKU_INC.'inc/auth.php');
require_once(dirname(__file__).'/recaptchalib.php');

class syntax_plugin_groupmail extends DokuWiki_Syntax_Plugin {

	public static $captcha = false;
	public static $lastFormId = 1;

	private $formId = 0;
	private $status = 1;
	private $statusMessage;
	private $errorFlags = array();

	/**
	 * General information about the plugin.
	 */
	public function getInfo(){
		return array(
			'author' => 'David Cabernel',
			'email'  => 'dcabernel@gmail.com',
			'date'	 => '2018-06-26',
			'name'	 => 'Group email plugin',
			'desc'	 => 'Group email with archiving.',
			'url'	 => 'https://github.com/POpus/dokuwiki-groupmail',
		);
	}

	/**
	 * What kind of syntax are we?
	 */
	public function getType(){
		return 'container';
	}

	/**
	 * What about paragraphs?
	 */
	public function getPType(){
		return 'block';
	}

	/**
 	 * Where to sort in?
 	 */
	public function getSort(){
		return 300;
	}

	/**
 	 * Connect pattern to lexer.
 	 */
	public function connectTo($mode) {
		$this->Lexer->addSpecialPattern('\{\{groupmail>[^}]*\}\}',$mode,'plugin_groupmail');
	}

	/**
	 * Handle the match.
	 */
	public function handle($match, $state, $pos, Doku_Handler $handler){
		if (isset($_REQUEST['comment']))
		    return false;

		$match = substr($match,12,-2); //strip markup from start and end

		$data = array();

		//handle params
		$params = explode('|',$match);
		foreach($params as $param){
			$splitparam = explode('=',$param);
			//multiple targets/profils possible for the email
			//add multiple to field in the dokuwiki page code
			// example : {{groupmail>to*=profile1|subject=Feedback from Site}}
			if ($splitparam[0]=='toemail'){
				if (isset($data[$splitparam[0]])){
					$data[$splitparam[0]] .= ",".$splitparam[1]; //it is a "toemail" param but not the first
				}else{
					$data[$splitparam[0]] = $splitparam[1]; // it is the first "toemail" param
				}
			} else if ($splitparam[0]=='touser'){
				if (isset($data[$splitparam[0]])){
					$data[$splitparam[0]] .= ",".$splitparam[1]; //it is a "touserl" param but not the first
				}else{
					$data[$splitparam[0]] = $splitparam[1]; // it is the first "touserl" param
				}
			} else if ($splitparam[0]=='togroup'){
				if (isset($data[$splitparam[0]])){
					$data[$splitparam[0]] .= ",".$splitparam[1]; //it is a "togroup" param but not the first
				}else{
					$data[$splitparam[0]] = $splitparam[1]; // it is the first "togroup" param
				}
			} else if ($splitparam[0]=='autofrom'){
                           // If only 'autofrom' is set but no 'autofrom=...', 
                           // default to 'autofrom=true'
                           if (!isset($data[$splitparam[0]]))
                              $data[$splitparam[0]] = 'true';
                           else
		              $data[$splitparam[0]] = $splitparam[1]; // it is not a "to" param
			}else{
				$data[$splitparam[0]] = $splitparam[1]; // All other parameters
			}
		}
		return $data;
	}

	/**
	 * Create output.
	 */
	public function render($mode, Doku_Renderer $renderer, $data) {
		if($mode == 'xhtml'){
			// Define unique form id
			$this->formId = syntax_plugin_groupmail::$lastFormId++;

			// Disable cache
			$renderer->info['cache'] = false;
			$renderer->doc .= $this->_groupmail($data);
			return true;
		}
		return false;
	}

	private function send_mail ($to, $subject, $content, $from, $cc, $bcc) {
        // send a mail
        $mail = new Mailer();
        $mail->to($to);
        $mail->cc($cc);
        $mail->bcc($bcc);
        $mail->from($from);
        $mail->subject($subject);
        $mail->setBody($content);
        $ok = $mail->send();
		return $ok;
	}

	/**
	 * Verify and send email content.´
	 */
	protected function _send_groupmail($captcha=false, $sendlog){
		global $conf;
		global $auth;
		global $USERINFO;
		// global $ID;

		$lang = $this->getLang("error");

		require_once(DOKU_INC.'inc/mail.php');
                $name  = $_POST['name'];
                $email = $_POST['email'];
		$subject = $_POST['subject'];
		$comment = $_POST['content'];

		// comment entered?
		if(strlen($_POST['content']) < 10)
			$this->_set_error('content', $lang["content"]);

                // record email in log
                $lastline = '';
                if ( isset($sendlog)  &&  $sendlog != '' ) {
                     $targetpage = htmlspecialchars(trim($sendlog));
                     $oldrecord = rawWiki($targetpage);
                     $newrecord = '====== '.$subject.' ======'."\n\n";
                     $newrecord .= '  * '.$this->getLang("date").': '.date('Y-m-d')."\n";
                     $newrecord .= '  * '.$this->getLang("time").': '.date('H:i:s')."\n";
                     $newrecord .= '  * '.$this->getLang("from").': '.$name.' <'.$email.'>'."\n";
                     $newrecord .= "\n";
                     $newrecord .= $comment."\n\n";
                     saveWikiText($targetpage, $newrecord.$oldrecord, "New entry", true);
                     $lastline .= $this->getLang("viewonline").wl($ID,'', true).'?id='.$targetpage."\r\n\n\n";
                }

		$comment .= "\n\n";
                $comment .= '---------------------------------------------------------------'."\n";
                $comment .= $this->getLang("sent by").$name.' <'.$email.'>'."\n";
                $comment .= $this->getLang("via").wl($ID,'',true)."\n";
                $comment .= $lastline;
                
		if (isset($_REQUEST['toemail'])){
			//multiple targets/profils possible for the email
			$usersList = explode(',',$_POST['toemail']); 
			foreach($usersList as $user){
				if (!empty($to)){
					$to .= ",".$user;
				}else{
					$to = $user;
				}
			}
		} else if (isset($_REQUEST['touser'])){
			//multiple targets/profils possible for the email
			$usersList = explode(',',$_POST['touser']); 
			foreach($usersList as $userId){
				$user = $auth->getUserData($userId);
				if (isset($user)) {
					if (!empty($to)){
						$to .= ",".$user['mail'];
					}else{
						$to = $user['mail'];
					}
				}
			}
		} else if (isset($_REQUEST['togroup'])){
                        if (!method_exists($auth,"retrieveUsers")) return false;
			//multiple targets/profils possible for the email
			$groupList = explode(',',$_POST['togroup']); 
			$userList = array();
                        foreach ($groupList as $grp) {
                            $getuser = $auth->retrieveUsers(0,-1,array('grps'=>'^'.preg_quote($grp,'/').'$'));
                            foreach ($getuser as $u => $info) {
				if (!empty($to)){
					$to .= ",".$info['mail'];
				}else{
					$to = $info['mail'];
				}
                            }
                        }
		} else {
			$to = $this->getConf('default');
		}

		// name entered?
		if(strlen($name) < 2)
			$this->_set_error('name', $lang["name"]);

		// email correctly entered?
		if(!$this->_check_email_address($email))
			$this->_set_error('email', $lang["email"]);

		// checks recaptcha answer
		if($conf['plugin']['groupmail']['captcha'] == 1 && $captcha == true) {
			$resp = recaptcha_check_answer ($conf['plugin']['groupmail']['recaptchasecret'],
						$_SERVER["REMOTE_ADDR"],
						$_POST["recaptcha_challenge_field"],
						$_POST["recaptcha_response_field"]);
			if (!$resp->is_valid){
				$this->_set_error('captcha', $lang["captcha"]);
			}
		}

		// A bunch of tests to make sure it's legitimate mail and not spoofed
		// This should make it not very easy to do injection
		if (preg_match("/(\r)/",$name) || preg_match("/(\n)/",$name) || preg_match("/(MIME-Version: )/",$name) || preg_match("/(Content-Type: )/",$name)){
			$this->_set_error('name', $lang["valid_name"]);
		}
		if (preg_match("/(\r)/",$email) || preg_match("/(\n)/",$email) || preg_match("/(MIME-Version: )/",$email || preg_match("/(Content-Type: )/",$email))){
			$this->_set_error('email', $lang["valid_email"]);
		}
		if (preg_match("/(\r)/",$subject) || preg_match("/(\n)/",$subject) || preg_match("/(MIME-Version: )/",$subject) || preg_match("/(Content-Type: )/",$subject)){
			$this->_set_error('subject', $lang["valid_subject"]);
		}
		if (preg_match("/(\r)/",$to) || preg_match("/(\n)/",$to) || preg_match("/(MIME-Version: )/",$to) || preg_match("/(Content-Type: )/",$to)){
			$this->_set_error('to', $lang["valid_to"]);
		}
		if (preg_match("/(MIME-Version: )/",$comment) || preg_match("/(Content-Type: )/",$comment)){
			$this->_set_error('content', $lang["valid_content"]);
		}

		// Status has not changed.
		if($this->status != 0) {
			// send only if comment is not empty
			// this should never be the case anyway because the form has
			// validation to ensure a non-empty comment
			if (trim($comment, " \t") != ''){
				if ($this->send_mail($to, $subject, $comment, $email, '', '')){
					$this->statusMessage = $this->getLang("success");
				} else {
					$this->_set_error('unknown', $lang["unknown"]);
				}
				//we're using the included mail_send command because it's
				//already there and it's easy to use and it works
			}
		}

		return true;
	}

	/**
	 * Manage error messages.
	 */
	protected function _set_error($type, $message) {
		$this->status = 0;
		$this->statusMessage .= empty($this->statusMessage)?$message:'<br>'.$message;
		$this->errorFlags[$type] = true;
	}

	/**
	 * Validate email address. From: http://www.ilovejackdaniels.com/php/email-address-validation
	 */
	protected function _check_email_address($email) {
		// First, we check that there's one @ symbol, 
		// and that the lengths are right.
		if (!preg_match("/(^[^@]{1,64}@[^@]{1,255}$)/", $email)) {
			// Email invalid because wrong number of characters 
			// in one section or wrong number of @ symbols.
			return false;
		}
		// Split it into sections to make life easier
		$email_array = explode("@", $email);
		$local_array = explode(".", $email_array[0]);
		for ($i = 0; $i < sizeof($local_array); $i++) {
			if (!preg_match("{^(([A-Za-z0-9!#$%&'*+/=?^_`{|}~-][A-Za-z0-9!#$%&'*+/=?^_`{|}~\.-]{0,63})|(\"[^(\\|\")]{0,62}\"))$}",
				$local_array[$i])) {
					return false;
			}
		}
		// Check if domain is IP. If not, 
		// it should be valid domain name
		if (!preg_match("/(^\[?[0-9\.]+\]?$)/", $email_array[1])) {
			$domain_array = explode(".", $email_array[1]);
			if (sizeof($domain_array) < 2) {
				return false; // Not enough parts to domain
			}
			for ($i = 0; $i < sizeof($domain_array); $i++) {
				if (!preg_match("/(^(([A-Za-z0-9][A-Za-z0-9-]{0,61}[A-Za-z0-9])|([A-Za-z0-9]+))$)/",
					$domain_array[$i])) {
						return false;
				}
			}
		}
		return true;
	}

	/**
	 * Does the groupmail form xhtml creation.
	 */
	protected function _groupmail($data){
		global $conf;
		global $USERINFO;

		// Is there none captcha on the side?
		$captcha = ($conf['plugin']['groupmail']['captcha'] == 1 && syntax_plugin_groupmail::$captcha == false)?true:false;

                // Setup send log destination
		if      ( isset($data['sendlog']) )
                   $sendlog = $data['sendlog'];
                else if ( isset($conf['plugin']['groupmail']['sendlog'])  &&
                          '' != $conf['plugin']['groupmail']['sendlog']     )
                   $sendlog = $conf['plugin']['groupmail']['sendlog'];

		$ret = "<form action=\"".$_SERVER['REQUEST_URI']."#form-".$this->formId."\" method=\"POST\"><a name=\"form-".$this->formId."\"></a>";
		$ret .= "<table class=\"inline\">";

		// Send message and give feedback
		if (isset($_POST['submit-form-'.$this->formId]))
			if($this->_send_groupmail($captcha, $sendlog))
				$ret .= $this->_show_message();

		// Build table
		if (!isset($data['autofrom'])  ||  $data['autofrom'] != 'true' ) {
		         $ret .= $this->_table_row($this->getLang("name"), 'name', 'text', $USERINFO['name']);
		         $ret .= $this->_table_row($this->getLang("email"), 'email', 'text', $USERINFO['mail']);
                }
		if (!isset($data['subject']))
                   $ret .= $this->_table_row($this->getLang("subject"), 'subject', 'text');
		if (isset($data['content']))
                   $ret .= $this->_table_row($this->getLang("content"), 'content', 'textarea', $data['content']);
                else
                   $ret .= $this->_table_row($this->getLang("content"), 'content', 'textarea');

		// Captcha
		if($captcha) {
			if($this->errorFlags["captcha"]) {
				$ret .= '<style>#recaptcha_response_field { border: 1px solid #e18484 !important; }</style>';
			}
			$ret .= "<tr><td colspan=\"2\">"
			. "<script type=\"text/javascript\">var RecaptchaOptions = { lang : '".$conf['lang']."', "
			. "theme : '".$conf['plugin']['groupmail']['recaptchalayout']."' };</script>"
			. recaptcha_get_html($conf['plugin']['groupmail']['recaptchakey'])."</td></tr>";
			syntax_plugin_groupmail::$captcha = true;
		}

		$ret .= "</table><p>";
		if ( isset($data['autofrom'])  &&  $data['autofrom'] == 'true' ) {
			$ret .= "<input type=\"hidden\" name=\"email\" value=\"".$USERINFO['mail']."\" />";
			$ret .= "<input type=\"hidden\" name=\"name\" value=\"".$USERINFO['name']."\" />";
                }
		if (isset($data['subject']))
			$ret .= "<input type=\"hidden\" name=\"subject\" value=\"".$data['subject']."\" />";
		if ( isset($data['touser']) )
			$ret .= "<input type=\"hidden\" name=\"touser\" value=\"".$data['touser']."\" />";
		else if ( isset($data['togroup']) )
			$ret .= "<input type=\"hidden\" name=\"togroup\" value=\"".$data['togroup']."\" />";
		else if ( isset($data['toemail']) )
			$ret .= "<input type=\"hidden\" name=\"toemail\" value=\"".$data['toemail']."\" />";
		$ret .= "<input type=\"hidden\" name=\"do\" value=\"show\" />";
		$ret .= "<input type=\"submit\" name=\"submit-form-".$this->formId."\" value=\"".$this->getLang("send")."\" />";
		$ret .= "</p></form>";

		return $ret;
	}

	/**
	 * Show up error messages.
	 */
	protected function _show_message() {
		return '<tr><td colspan="2">'
		. '<p class="'.(($this->status == 0)?'groupmail_error':'groupmail_success').'">'.$this->statusMessage.'</p>'
		. '</td></tr>';
	}

	/**
	 * Renders a table row.
	 */
	protected function _table_row($label, $name, $type, $default='') {
		$value = (isset($_POST['submit-form-'.$this->formId]) && $this->status == 0)?$_POST[$name]:$default;
		$class = ($this->errorFlags[$name])?'class="error_field"':'';
		$row = '<tr><td>'.$label.'</td><td>';
		if($type == 'textarea')
			$row .= '<textarea name="'.$name.'" wrap="on" cols="40" rows="6" '.$class.'>'.$value.'</textarea>';
		else
			$row .= '<input type="'.$type.'" value="'.$value.'" name="'.$name.'" '.$class.'>';
		$row .= '</td></tr>';
		return $row;
	}

}
