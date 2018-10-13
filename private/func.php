<?php

function createPasswd($passwd = ''){
	if(empty($passwd)){
		$passwd = genRandStr(8);
	}
	return [$passwd, password_hash($passwd, PASSWORD_DEFAULT)];
}

function genRandStr($length = 10) {
	$str = ''; $chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ~!@#$%^&*()_+-=';
	for ($i = 0; $i < $length; $i++) {
		$str .= $chars[random_int(0 ,strlen($chars)-1)];
	}
    return $str;
}

function _mail($email, $subject, $message){
	global $conf;
	$headers  = "MIME-Version: 1.0\r\n";
	$headers .= "Content-type: text/html; charset=utf-8\r\n";
	$headers .= "Content-Transfer-Encoding: base64\r\n";
	$subject  = "=?utf-8?B?".base64_encode($subject)."?=";
	$headers .= "From: {$conf['email_from']} <{$conf['email']}>\r\n";
	mail($email, $subject, rtrim(chunk_split(base64_encode($message))), $headers);
}

function _message($mes, $err = 'ok'){
	$arr = ['err' => $err, 'mes' => $mes];
	echo json_encode($arr);
	die();
}

function half_string($s){
	return substr($s, round(strlen($s)/2));
}

function session_hash($login, $passwd, $rand = '', $time = ''){
	global $conf, $var;
	if(empty($rand)){
		$rand = genRandStr(8);
	}
	if(empty($time)){
		$time = $var['time']+86400;
	}
	return [$rand.hash($conf['hash_algo'], $rand.$var['ip'].$var['user_agent'].$time.$login.sha1(half_string($passwd))), $time];
}

function coinhive_proof(){
	global $conf;
	if(empty($_POST['coinhive-captcha-token'])){
		return false;	
	}
	$post_data = [
		'secret' => $conf['coinhive_secret'],
		'token' => $_POST['coinhive-captcha-token'],
		'hashes' => 1024
	];
	$post_context = stream_context_create([
		'http' => [
			'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
			'method'  => 'POST',
			'content' => http_build_query($post_data)
		]
	]);
	$url = 'https://api.coinhive.com/token/verify';
	$response = json_decode(file_get_contents($url, false, $post_context));
	if($response && $response->success) {
		return true;
	}
	return false;
}

function _exit(){
	global $db;
	if(session_status() != PHP_SESSION_NONE){
		if(!empty($_SESSION['sess'])){
			$query = $db->prepare("DELETE FROM `session` WHERE `hash` = :hash");
			$query->bindParam(':hash', $_SESSION["sess"], PDO::PARAM_STR);
			$query->execute();
		}
		$params = session_get_cookie_params();
		setcookie(session_name(), '', time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
		session_unset();
		session_destroy();
		header("Location: https://".$_SERVER['SERVER_NAME']);	
	}
}

function login(){
	global $db, $var, $user;
	if($user){
		_message('Already authorized', 'error');
	}
	if(empty($_POST['login']) || empty($_POST['passwd'])){
		_message('Empty post value', 'error');
	}
	if(strlen($_POST['login']) > 20){
		_message('Too long login or email', 'error');
	}
	if(preg_match('/[^0-9A-Za-z]/', $_POST['login'])){
		_message('Wrong login', 'error');
	}
	if(strlen($var['user_agent']) > 256){
		_message('Wrong user agent', 'error');
	}
	$query = $db->prepare("SELECT * FROM `users` WHERE `login` = :login");
	$query->bindValue(':login', $_POST['login'], PDO::PARAM_STR);
	$query->execute();
	if($query->rowCount() == 0){
		_message('Invalid user', 'error');
	}
	$row = $query->fetch();
	if(!empty($row['2fa'])){
		if(empty($_POST['fa2code'])){
			_message('Empty post 2FA', 'error');
		}
		if(oathHotp($row['2fa'], floor(microtime(true) / 30)) != $_POST['fa2code']){
			_message('Wrong 2FA', 'error');
		}
	}
	if(!password_verify($_POST['passwd'], $row['passwd'])){
		_message('Wrong password', 'error');
	}
	if(password_needs_rehash($row['passwd'], PASSWORD_DEFAULT)){
		$passwd = createPasswd($_POST['passwd']);
		$query = $db->prepare("UPDATE `users` SET `passwd` = :passwd WHERE `id` = :id");
		$query->bindParam(':passwd', $passwd[1], PDO::PARAM_STR);
		$query->bindParam(':id', $row['id'], PDO::PARAM_STR);
		$query->execute();
		$row['passwd'] = $passwd[1];
	}
	$hash = session_hash($row['login'], $row['passwd']);
	$query = $db->prepare("INSERT INTO `session` (`uid`, `hash`, `time`, `ip`, `info`) VALUES (:uid, :hash, :time, :ip, :info)");
	$query->bindParam(':uid', $row['id'], PDO::PARAM_STR);
	$query->bindParam(':hash', $hash[0], PDO::PARAM_STR);
	$query->bindParam(':time', $hash[1], PDO::PARAM_STR);
	$query->bindParam(':ip', $var['ip'], PDO::PARAM_STR);
	$query->bindParam(':info', $var['user_agent'], PDO::PARAM_STR);
	$query->execute();
	$query = $db->prepare("SELECT `id` FROM `session` WHERE `uid` = :uid ORDER BY `time`");
	$query->bindParam(':uid', $row['id'], PDO::PARAM_STR);
	$query->execute();
	if($query->rowCount() > 10){
		$row = $query->fetch();
		$query = $db->prepare("DELETE FROM `session` WHERE `id` = :id");
		$query->bindParam(':id', $row['id'], PDO::PARAM_STR);
		$query->execute();
	}
	$_SESSION['sess'] = $hash[0];
	_message('Success');
}

function password_link(){
	global $conf, $db, $var;
	if(empty($_GET['id']) || empty($_GET['time']) || empty($_GET['hash'])){
		_message('Empty get value', 'error');
	}
	if(!is_numeric($_GET['id']) || !is_numeric($_GET['time'])){
		_message('Wrong id or time', 'error');	
	}
	$query = $db->prepare("SELECT * FROM `users` WHERE `id` = :id");
	$query->bindParam(':id', $_GET['id'], PDO::PARAM_STR);
	$query->execute();
	if($query->rowCount() == 0){
		_message('No such user', 'error');
	}
	$row = $query->fetch();
	$hash = hash($conf['hash_algo'], $var['ip'].$_GET['id'].$_GET['time'].sha1(half_string($row['passwd'])));
	if($_GET['hash'] != $hash){
		_message('Wrong hash', 'error');
	}
	if($var['time'] > $_GET['time']){
		_message('Invalid link', 'error');
	}
	$passwd = createPasswd();
	$query = $db->prepare("UPDATE `users` SET `passwd` = :passwd WHERE `id` = :id");
	$query->bindValue(':id', $row['id'], PDO::PARAM_STR);
	$query->bindParam(':passwd', $passwd[1], PDO::PARAM_STR);
	$query->execute();
	_mail($row['mail'], "Новый пароль", "Ваш пароль: $passwd[0]");
	_message('Success');
}

function testcaptcha(){
	$z = false;
	if(!empty($_POST['g-recaptcha-response'])){
		$result = recaptchav3();
		if($result['success'] && $result['score'] > 0.5){
			$z = true;
		}else{
			_message('reCaptcha test failed: score too low', 'error');
		}
	}
	if(!coinhive_proof() && !$z){
		_message('Coinhive captcha error', 'error');
	}
}

function password_recovery(){
	global $conf, $db, $var;
	testcaptcha();
	if(empty($_POST['mail'])){
		_message('Empty post value', 'error');
	}
	if(strlen($_POST['mail']) > 254){
		_message('Too long login or email', 'error');
	}
	if(!filter_var($_POST['mail'], FILTER_VALIDATE_EMAIL)){
		_message('Wrong email', 'error');
	}
	$query = $db->prepare("SELECT * FROM `users` WHERE `mail` = :mail");
	$query->bindParam(':mail', $_POST['mail'], PDO::PARAM_STR);
	$query->execute();
	if($query->rowCount() == 0){
		_message('No such user', 'error');
	}
	$row = $query->fetch();
	$time = $var['time']+43200;
	$hash = hash($conf['hash_algo'], $var['ip'].$row['id'].$time.sha1(half_string($row['passwd'])));
	$link = "https://" . $_SERVER['SERVER_NAME'] . "/public/password_link.php?id={$row['id']}&time={$time}&hash={$hash}";
	_mail($row['mail'], "Восстановление пароля", "Запрос отправили с IP {$var['ip']}<br/>Чтобы восстановить пароль <a href='$link'>перейдите по ссылке</a>.");
	_message('Please check your mail');
}

function registration(){
	global $db, $user;
	if($user){
		_message('Already authorized', 'error');
	}
	testcaptcha();
	if(empty($_POST['login']) || empty($_POST['mail'])){
		_message('Empty post value', 'error');
	}
	if(strlen($_POST['login']) > 20 || strlen($_POST['mail']) > 254){
		_message('Too long login or email', 'error');
	}
	if(preg_match('/[^0-9A-Za-z]/', $_POST['login'])){
		_message('Wrong login', 'error');
	}
	if(!filter_var($_POST['mail'], FILTER_VALIDATE_EMAIL)){
		_message('Wrong email', 'error');
	}
	$_POST['mail'] = mb_strtolower($_POST['mail']);
	$query = $db->prepare("SELECT * FROM `users` WHERE `login` = :login OR `mail`= :mail");
	$query->bindValue(':login', $_POST['login'], PDO::PARAM_STR);
	$query->bindParam(':mail', $_POST['mail'], PDO::PARAM_STR);
	$query->execute();
	if($query->rowCount() > 0){
		_message('Already registered', 'error');
	}
	$passwd = createPasswd();
	$userFields = setupUserData();
	$query = $db->prepare("INSERT INTO `users` (`login`, `mail`, `passwd`, `user_data`) VALUES (:login, :mail, :passwd, :userData)");
	$query->bindValue(':login', $_POST['login'], PDO::PARAM_STR);
	$query->bindParam(':mail', $_POST['mail'], PDO::PARAM_STR);
	$query->bindParam(':passwd', $passwd[1], PDO::PARAM_STR);
    $query->bindParam(':userData', $userFields, PDO::PARAM_STR);
	$query->execute();
	_mail($_POST['mail'], "Регистрация", "Вы успешно зарегистрировались на сайте!<br/>Ваш пароль: $passwd[0]");
	_message('Success registration');
}

function auth(){
	global $conf, $db, $var, $user;
	if(!empty($_SESSION['sess'])){
		$query = $db->prepare("SELECT * FROM `session` WHERE `hash` = :hash AND `time` > unix_timestamp(now())");
		$query->bindParam(':hash', $_SESSION['sess'], PDO::PARAM_STR);
		$query->execute();
		if($query->rowCount() != 1){
			_exit();
		}
		$session = $query->fetch();
		$query = $db->prepare("SELECT * FROM `users` WHERE `id` = :id");
		$query->bindParam(':id', $session['uid'], PDO::PARAM_STR);
		$query->execute();
		if($query->rowCount() != 1){
			_exit();
		}
		$row = $query->fetch();
		if($_SESSION['sess'] != session_hash($row['login'], $row['passwd'], substr($session['hash'], 0, 8), $session['time'])[0]){
			_exit();
		}
		if($var['time'] > $session['time']){			
			$hash = session_hash($row['login'], $row['passwd']);
			$query = $db->prepare('UPDATE `session` set `hash` = :hash, `time` = :time WHERE `id` = :id');
			$query->bindParam(':hash', $hash[0], PDO::PARAM_STR);
			$query->bindParam(':time', $hash[1], PDO::PARAM_STR);
			$query->bindParam(':id', $session['id'], PDO::PARAM_STR);
			$query->execute();
			$_SESSION['sess'] = $hash[0];
		}
		$user = ['id' => $row['id'], 'login' => $row['login'], 'passwd' => $row['passwd'], 'mail' => $row['mail'], '2fa' => $row['2fa'], 'access' => $row['access']];
	}
}

function base32_map($i, $do = 'encode'){
	$chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
	if( $do == 'encode'){
		return $chars[$i];
	}else{
		return array_search($i, str_split($chars));
	}
}

function base32_bits($v){
	$value = ord($v);
	return vsprintf(str_repeat('%08b', count($value)), $value);
}

function base32_encode($data){
	$result = ''; $s = 0;
	$j = [4 => 1, 3 => 3, 2 => 4, 1 => 6];
	$arr = explode('|', substr(chunk_split($data, 5, '|'), 0, -1));
	foreach($arr as $val){
		$s++;
		$arr2 = str_split($val);
		$x = ['00000000', '00000000', '00000000', '00000000', '00000000'];
		foreach($arr2 as $key => $val2){
			$x[$key] = base32_bits($val2);	
		}
		$arr3 = explode('|', substr(chunk_split(implode('', $x), 5, '|'), 0, -1));
		foreach($arr3 as $key => $val3){	
			$result .= base32_map(bindec($val3));
		}
		if($s == count($arr) && isset($j[strlen($val)])){
			$result = str_pad(substr($result, 0, -$j[strlen($val)]), 8*$s, '=', STR_PAD_RIGHT);
		}
	}
	return $result;
}

function base32_decode($data){ // thx Sanasol
	$x = '';
	$arr = str_split($data);
	foreach($arr as $val){
		$x .= str_pad(decbin(base32_map($val, 'decode')), 5, '0', STR_PAD_LEFT);
	}
	$chunks = str_split($x, 8);
	$string = array_map(function($chr){
		return chr(bindec($chr));
	}, $chunks);
	return implode("", $string);
}

function generate_secret(){
	return base32_encode(genRandStr());
}

function oathTruncate($hash){
	$offset = ord($hash[19]) & 0xf;
	$temp = unpack('N', substr($hash, $offset, 4));
	return substr($temp[1] & 0x7fffffff, -6);
}

function oathHotp($secret, $time){
	$secret = base32_decode($secret);
	$time = pack('N*', 0, $time);
	$hash = hash_hmac('sha1', $time, $secret, true);
	return str_pad(oathTruncate($hash), 6, '0', STR_PAD_LEFT);
}

function getQRCodeGoogleUrl($name, $secret){
	$urlencoded = urlencode('otpauth://totp/'.$name.'?secret='.$secret.'');
	return 'https://chart.googleapis.com/chart?chs=200x200&cht=qr&chl='.$urlencoded.'';
}

function auth2FA(){
	global $db, $user;
	if(!$user){
		_message('Unauthorized user', 'error');
	}
	if(empty($_POST['do'])){
		_message('Empty post', 'error');
	}
	switch($_POST['do']){
		default: return 'empty_post_value'; break;
		case 'gen':
			if(!empty($user['2fa'])){
				_message('2FA already activated', 'error');				
			}
			$base32_key = generate_secret();
			_message("<img src=".getQRCodeGoogleUrl($user['login']."@anilibria.tv", $base32_key)."><br>Secret key: $base32_key<br/>Сохраните секретный ключ в надежном месте.<input type=\"hidden\" id=\"2fa\" value=\"$base32_key\">");
		break;
		case 'save':
			if(empty($_POST['passwd']) || empty($_POST['code'])){
				_message('Empty post', 'error');
			}
			if(empty($user['2fa'])){
				if(empty($_POST['2fa'])){
					_message('Empty post 2fa', 'error');
				}
				$check = $_POST['2fa'];
			}else{
				$check = $user['2fa'];
			}
			if(strlen($check) != 16 || !ctype_alnum($check) || ctype_lower($check)){
				_message('Wrong 2FA', 'error');
			}
			if(oathHotp($check, floor(microtime(true) / 30)) != $_POST['code']){
				_message('Wrong 2FA', 'error');
			}
			if(!password_verify($_POST['passwd'], $user['passwd'])){
				_message('Wrong password', 'error');
			}
			if(!empty($user['2fa'])){
				$query = $db->prepare("UPDATE `users` SET `2fa` = :code WHERE `id` = :uid");
				$query->bindValue(':code', null, PDO::PARAM_INT);
				$query->bindParam(':uid', $user['id'], PDO::PARAM_STR);
				$query->execute();
				_message('2FA disabled');
			}else{
				$query = $db->prepare("UPDATE `users` SET `2fa` = :code WHERE `id` = :uid");
				$query->bindParam(':code', $_POST['2fa'], PDO::PARAM_STR);
				$query->bindParam(':uid', $user['id'], PDO::PARAM_STR);
				$query->execute();
				_message('2FA activated');
			}
		break;
	}
}

function recaptchav3(){
	global $conf, $var;
	if(empty($_POST['g-recaptcha-response'])){
		_message('Empty post recaptcha', 'error');
	}
	$data = ['secret' => $conf['recaptcha_secret'], 'response' => $_POST['g-recaptcha-response'], 'remoteip' => $var['ip']];
	$verify = curl_init();
	curl_setopt($verify, CURLOPT_URL, "https://www.google.com/recaptcha/api/siteverify");
	curl_setopt($verify, CURLOPT_POST, true);
	curl_setopt($verify, CURLOPT_POSTFIELDS, $data);
	curl_setopt($verify, CURLOPT_RETURNTRANSFER, true);
	$result = json_decode(curl_exec($verify), true);
	curl_close($verify);
	return $result;
}

function xSpiderBot($name){
	$arr = ['Google' => '/\.googlebot\.com$/i', 'Yandex' => '/\.spider\.yandex\.com$/i'];
	if(strpos($_SERVER['HTTP_USER_AGENT'], $name) !== false){
		return preg_match($arr["$name"], gethostbyaddr($_SERVER['REMOTE_ADDR']));
	}
	return false;
}

function secret_cookie(){
	global $conf, $var;
	$rand = genRandStr(8);
	$hash = hash($conf['hash_algo'], $var['ip'].$rand.$conf['sign_secret']);
	setcookie("ani_test", $hash.$_SERVER['REMOTE_ADDR'].$rand, $var['time'] + 86400, '/');
}

function simple_http_filter(){
	global $conf, $var;
	$flag = false;
	if(!empty($_COOKIE['ani_test'])){
		$string = $_COOKIE['ani_test'];
		$hash = substr($string, 0, $conf['hash_len']);
		$rand = substr($string, $conf['hash_len']+strlen($var['ip']));
		$test = hash($conf['hash_algo'], $var['ip'].$rand.$conf['sign_secret']);
		if($hash == $test){ 
			$flag = true;
		}
	}
	$list = ['RU', 'UA', 'BY', 'LV', 'EE', 'LT', 'TM', 'KG', 'KZ', 'MD', 'UZ', 'AZ', 'AM', 'GE'];
	if(!in_array(geoip_country_code_by_name($_SERVER['REMOTE_ADDR']), $list) && !$flag){
		if(xSpiderBot('Google') == false || xSpiderBot('Yandex') == false){
			$tmpFilter = file_get_contents($_SERVER['DOCUMENT_ROOT'].'/private/template/filter.html');
			$tmpFilter = str_replace("__COINHIVEKEY__", $conf['coinhive_public'], $tmpFilter);
			$tmpFilter = str_replace("__RECAPTCHAKEY__", $conf['recaptcha_public'], $tmpFilter);
			echo $tmpFilter;
			die;
		}
	}
}

function torrentHashExist($hash){
	global $db;
	$query = $db->prepare("SELECT * FROM xbt_files WHERE `info_hash` = :hash");
	$query->bindParam(':hash', $hash, PDO::PARAM_STR);
	$query->execute();
	if($query->rowCount() == 0){
		return false;
	}
	return true;
}

function torrentExist($id){
	global $db;
	$query = $db->prepare("SELECT * FROM `xbt_files` WHERE `fid`= :id");
	$query->bindParam(':id', $id, PDO::PARAM_STR);
	$query->execute();
	return $query->fetch();
}

function torrentAdd($hash, $rid, $json, $completed = 0){
	global $db;
	$query = $db->prepare("INSERT INTO `xbt_files` (`info_hash`, `mtime`, `ctime`, `flags`, `completed`, `rid`, `info`) VALUES( :hash , UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0, :completed, :rid, :info)");
	$query->bindParam(':hash', $hash, PDO::PARAM_STR);
	$query->bindParam(':rid', $rid, PDO::PARAM_STR);
	$query->bindParam(':completed', $completed, PDO::PARAM_STR);
	$query->bindParam(':info', $json, PDO::PARAM_STR);
	$query->execute();
	return $db->lastInsertId();	
}

// https://github.com/shakahl/xbt/wiki/XBT-Tracker-(XBTT)
// flags - This field is used to communicate with the tracker. Usable values: 0 - No changes. 1 - Torrent should be deleted. 2 - Torrent was updated.
// flag 1 work		https://github.com/OlafvdSpek/xbt/blob/master/Tracker/server.cpp#L183-L187
// source code		https://img.poiuty.com/img/6e/f01f40eaa783018fe12e5649315b716e.png
// flag 2 not work	https://img.poiuty.com/img/7c/a5479067a6e3a272d66bb92c0416797c.png
// Also I dont find it in source.
function torrentDelete($id){
	global $db;
	$query = $db->prepare("UPDATE `xbt_files` SET `flags` = 1 WHERE `fid` = :id");
	$query->bindParam(':id', $id, PDO::PARAM_STR);
	$query->execute();
	$file = $_SERVER['DOCUMENT_ROOT'].'/upload/torrents/'.$id.'.torrent';
	if(file_exists($file)) {
		unlink($file);
	}
}

function torrent(){
	global $conf, $db, $user, $var;
	if(!$user){
		_message('Unauthorized user', 'error');
	}
	if($user['access'] < 4){
		_message('Access denied', 'error');
	}
	if(empty($_POST['do'])){
		_message('Empty GET', 'error');
	}
	switch($_POST['do']){
		case 'delete':
			if(empty($_POST['edit_torrent']) || !is_numeric($_POST['edit_torrent'])){
				_message('No edit_torrent', 'error');
			}
			torrentDelete($_POST['edit_torrent']);
			_message('Finish, we delete torrent');
		break;
		default:
			if(empty($_POST['rid']) || empty($_POST['quality']) || empty($_POST['episode'])){
				_message('Set release id, name, quality and episode', 'error');
			}
			if(!is_numeric($_POST['rid'])){
				_message('Release ID allow numeric', 'error');
			}
			if(strlen($_POST['quality']) > 200 || strlen($_POST['episode']) > 200){
				_message('Max strlen 200', 'error');
			}
			if(empty($_FILES['torrent'])){
				_message('No upload file', 'error');
			}
			if($_FILES['torrent']['error'] != 0){
				_message('Upload error', 'error');
			}
			if($_FILES['torrent']['type'] != 'application/x-bittorrent'){
				_message('You can upload only torrents', 'error');	
			}
			$torrent = new Torrent($_FILES['torrent']['tmp_name']);
			if(empty($torrent->hash_info())){
				_message('Wrong torrent file', 'error');
			}
			$pack_hash = pack('H*', $torrent->hash_info());
			if(torrentHashExist($pack_hash)){
				_message('Torrent hash already exist', 'error');
			}
			$json = json_encode([$_POST['quality'], $_POST['episode']]);
			if(empty($_POST['edit_torrent'])){
				$name = torrentAdd($pack_hash, $_POST['rid'], $json);
			}else{
				if(!is_numeric($_POST['edit_torrent'])){
					_message('edit_torrent allow only numeric', 'error');
				}
				$old = torrentExist($_POST['edit_torrent']);
				if(!is_array($old)){
					_message('No old torrent', 'error');
				}
				$name = torrentAdd($pack_hash, $_POST['rid'], $json, $old['completed']);
				torrentDelete($_POST['edit_torrent']);
			}
			$torrent->announce(false);
			$torrent->announce($conf['torrent_announce']);
			$torrent->save($_SERVER['DOCUMENT_ROOT'].'/upload/torrents/'.$name.'.torrent');
			_message('Success');
		break;
	}
}

function downloadTorrent(){
	global $db, $user, $conf;
	if(!$user){
		_message('Unauthorized user', 'error');
	}
	if(empty($_GET['id'])){
		_message('Empty $_GET', 'error');
	}
	if(!is_numeric($_GET['id'])){
		_message('Wrong id', 'error');
	}
	$query = $db->prepare("SELECT * FROM `xbt_files` WHERE `fid` = :id");
	$query->bindParam(':id', $_GET['id'], PDO::PARAM_STR);
	$query->execute();
	if($query->rowCount() == 0){
		_message('Wrong id', 'error');
	}
	$info_hash = $query->fetch()['info_hash'];	

	$query = $db->prepare("SELECT * FROM `xbt_users` WHERE `torrent_pass_version` = :id");
	$query->bindParam(':id', $user['id'], PDO::PARAM_STR);
	$query->execute();
	if($query->rowCount() == 0){
		$query = $db->prepare("INSERT INTO `xbt_users` (`torrent_pass_version`) VALUES (:id)");
		$query->bindParam(':id', $user['id'], PDO::PARAM_STR);
		$query->execute();
		$uid = $db->lastInsertId();
	}else{
		$uid = $query->fetch()['uid'];
	}
	$key = sprintf('%08x%s', $uid, substr(sha1("{$conf['torrent_secret']} {$user['id']} $uid $info_hash"), 0, 24));
	$torrent = new Torrent($_SERVER['DOCUMENT_ROOT']."/upload/torrents/{$_GET['id']}.torrent");
	$torrent->announce(false);
	$torrent->announce(str_replace('/announce', "/$key/announce", $conf['torrent_announce']));
	$torrent->send();
}

function upload_avatar() {
	global $user;
	if(!$user){
		_message('Unauthorized user', 'error');
	}
	if(empty($_FILES['avatar'])){
		_message('No upload file', 'error');
	}
	if($_FILES['avatar']['error'] != 0){
		_message('Upload error', 'error');
	}
	if($_FILES['avatar']['type'] != 'image/jpeg'){
		_message('You can upload only jpeg', 'error');	
	}
	if($_FILES['avatar']['size'] > 150000){
		_message('Max size', 'error');
	}
	$dir = $_SERVER['DOCUMENT_ROOT'].'/upload/avatars/'.substr(md5($user['id']), 0, 2);
	if(!file_exists($dir)) {
		mkdir($dir, 0755, true);
	}
	move_uploaded_file($_FILES['avatar']['tmp_name'], "$dir/{$user['id']}.jpg");
	_message('Success');
}

function getUserAvatar($userid) {
	$img = "https://".$_SERVER['SERVER_NAME']."/upload/avatars/noavatar.png";
	$dir = substr(md5($userid), 0, 2);
	$path = "/upload/avatars/$dir/$userid.jpg";
	if(file_exists($_SERVER['DOCUMENT_ROOT'].$path)){
		$img = "https://".$_SERVER['SERVER_NAME'].$path;
	}
	return $img;
}

function show_profile(){
	global $db, $user;
	if(!empty($_GET['id'])){
		$id = $_GET['id'];
	} else {
		if($user) {
			$id = $user['id'];
		} else {
			return ['err' => true, 'mes' => 'К сожалению, такого пользователя не существует.'];
		}
	}
	if(!is_numeric($id)){
		return ['err' => true, 'mes' => 'Wrong user id'];
	}
	$query = $db->prepare("SELECT * FROM `users` WHERE `id` = :id");
	$query->bindValue(':id', $id, PDO::PARAM_STR);
	$query->execute();
	if($query->rowCount() == 0){
		return ['err' => true, 'mes' => 'К сожалению, такого пользователя не существует.'];
	}
    return ['err' => false, 'mes' => $query->fetch()];
}

function getUserNick($id) {
	global $db;
	$query = $db->prepare("SELECT `login`, `nickname` FROM `users` WHERE `id` = :id");
	$query->bindValue(':id', $id, PDO::PARAM_STR);
	$query->execute();
	$row = $query->fetch();
	if(isset($row["nickname"])) {
		return $row["nickname"];
	} else {
		return $row["login"];
	}
}

function getGroupName($access) {
	switch($access) {
		case 0:
			$groupName = "Заблокирован";
			break;
		case 1:
			$groupName = "Пользователь";
			break;
		case 2:
			$groupName = "Спонсор";
			break;
		case 3:
			$groupName = "Либриец";
			break;
		case 4:
			$groupName = "Редактор";
			break;
		case 5:
			$groupName = "Админ";
			break;
	}
	return $groupName;
}

function setupUserData() {
    $userFields = array(
        'vk' => NULL,
        'telegram' => NULL,
        'steamid' => NULL,
        'age' => NULL,
        'country' => NULL,
        'city' => NULL
    );
    return json_encode($userFields);
}

function saveUser($id) {
    global $db;
    if($_POST['saveData']) {
        $dataToSave = array(
            'nickname' => $_POST['nickname'],
            'sex' => $_POST['sex']
        );
        $jsonDataSave = array(
            'vk' => $_POST['vkontakte'],
            'telegram' => $_POST['telegram'],
            'steamid' => $_POST['steamid'],
            'age' => $_POST['age'],
            'country' => $_POST['country'],
            'city' => $_POST['city']
        );
        $json = json_encode($jsonDataSave);
        $query = $db->prepare("UPDATE `users` SET `nickname` = :nickname, `user_data` = :user_data, `sex` = :sex WHERE `id` = :id");
        $query->bindParam(':nickname', $dataToSave['nickname'], PDO::PARAM_STR);
        $query->bindParam(':sex', $dataToSave['sex'], PDO::PARAM_STR);
        $query->bindParam(':id', $id, PDO::PARAM_STR);
        $query->bindParam(':user_data', $json, PDO::PARAM_STR);
        $query->execute();
        _message('Data saved');
    }
}

function cryptAES($text, $key, $do = 'encrypt'){
	$key = hash('sha256', $key, true);
	$iv_size = openssl_cipher_iv_length($cipher = 'AES-256-CBC');
	$iv = random_bytes($iv_size);
	if($do == 'encrypt'){
		$ciphertext_raw = openssl_encrypt($text, $cipher, $key, OPENSSL_RAW_DATA, $iv);
		$hmac = hash_hmac('sha512', $ciphertext_raw, $key, true);		
		$ciphertext = base64_encode($iv.$hmac.$ciphertext_raw);
		return $ciphertext;
	}else{
		$c = base64_decode($text);
		$iv_dec = substr($c, 0, $iv_size);
		$hmac = substr($c, $iv_size, $sha2len=64);
		$ciphertext_raw = substr($c, $iv_size+$sha2len);
		$original = openssl_decrypt($ciphertext_raw, $cipher, $key, OPENSSL_RAW_DATA, $iv_dec);
		$calcmac = hash_hmac('sha512', $ciphertext_raw, $key, true);
		if(hash_equals($hmac, $calcmac)){
			return $original;
		}
	}
}

function change_mail(){
	global $user, $var, $conf;
	if(!$user){
		_message('Unauthorized user', 'error');
	}
	if(empty($_POST['mail']) || empty($_POST['passwd'])){
		_message('Empty post', 'error');	
	}
	if(!password_verify($_POST['passwd'], $user['passwd'])){
		_message('Wrong password', 'error');
	}
	if(!filter_var($_POST['mail'], FILTER_VALIDATE_EMAIL)){
		_message('Wrong email', 'error');
	}
    $_POST['mail'] = mb_strtolower($_POST['mail']);
    $time = $var['time'] + 43200;
    $hash = hash($conf['hash_algo'], $var['ip'] . $user['id'] . $user['mail'] . $_POST['mail'] . $time . sha1(half_string($user['passwd'])));
    $link = "https://" . $_SERVER['SERVER_NAME'] . "/public/mail_link.php?time=$time&mail=" . urlencode($_POST['mail']) . "&hash=$hash";
    _mail($user['mail'], "Изменение email", "Запрос отправили с IP {$var['ip']}<br/>Если вы хотите изменить email на {$_POST['mail']} - <a href='$link'>перейдите по ссылке</a>.");
    _message('Please check your mail');
}

function mail_link(){
	global $db, $user, $var, $conf;
	if(!$user){
		_message('Unauthorized user', 'error');
	}
	if(empty($_GET['time']) || empty($_GET['mail']) || empty($_GET['hash'])){
		_message('Empty $_GET', 'error');
	}
	if($var['time'] > $_GET['time']){
		_message('Too late $_GET', 'error');	
	}
	$_GET['mail'] = urldecode($_GET['mail']);
	if(!filter_var($_GET['mail'], FILTER_VALIDATE_EMAIL)){
		_message('Wrong email', 'error');
	}
	$hash = hash($conf['hash_algo'], $var['ip'].$user['id'].$user['mail'].$_GET['mail'].$_GET['time'].sha1(half_string($user['passwd'])));
	if($hash != $_GET['hash']){
		_message('Wrong hash', 'error');
	}
	$query = $db->prepare("UPDATE `users` SET `mail` = :mail WHERE `id` = :id");
	$query->bindParam(':mail', $_GET['mail'], PDO::PARAM_STR);
	$query->bindParam(':id', $user['id'], PDO::PARAM_STR);
	$query->execute();
	_message('Success');
}

function change_passwd(){
	global $db, $user, $var, $conf;
	if(!$user){
		_message('Unauthorized user', 'error');
	}
	if(empty($_POST['passwd'])){
		_message('Empty post', 'error');
	}
	if(!password_verify($_POST['passwd'], $user['passwd'])){
		_message('Wrong password', 'error');
	}
	$passwd = createPasswd();
	$query = $db->prepare("UPDATE `users` SET `passwd` = :passwd WHERE `id` = :id");
	$query->bindParam(':passwd', $passwd[1], PDO::PARAM_STR);
	$query->bindParam(':id', $user['id'], PDO::PARAM_STR);
	$query->execute();
	_mail($user['mail'], "Изменение пароля", "Запрос отправили с IP {$var['ip']}<br/>Ваш новый пароль: {$passwd[0]}");
	_message('Please check your mail');
}
