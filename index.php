<?php
$time = microtime(true); // Gets microseconds

if(!preg_match("#^[a-zA-Z0-9]{32}$#",$_SERVER["HTTP_X_API_KEY"]) && !empty($_SERVER["HTTP_X_API_KEY"])) 
	die('failed');
preg_match("#^/([a-zA-Z0-9\-_]+)/([a-zA-Z0-9\-_]+)/?(.*)$#",$_SERVER["REQUEST_URI"],$matches);
$config['fache_timeout'] = 86400; //in seconds (86400 seconds == 24 hours)
// Unique app key / api path / special parameters
$config['fcache_path'] = $_SERVER['DOCUMENT_ROOT'].'/cache/'.$_SERVER["HTTP_X_API_KEY"].'/'.$matches[1].'/'.$matches[2].'/'.(!empty($_SERVER["HTTP_X_API_SESS"])?$_SERVER["HTTP_X_API_SESS"]:'default').'/';
$config['fcache_delete'] = $_SERVER['DOCUMENT_ROOT'].'/cache/'.$_SERVER["HTTP_X_API_KEY"].'/'.$matches[1].'/';
$config['fcache_apipath'] = $_SERVER['DOCUMENT_ROOT'].'/cache/'.$_SERVER["HTTP_X_API_KEY"].'/';
$config['services'] = 'service.domain.com'; // API server
$hash = str_replace('/','+',(empty($matches[3])?'index':$matches[3]));
function get_services()
{
	global $config;
	$fp = fsockopen($config['services'], 80, $errno, $errstr, 30);
	if (!$fp) {
		return FALSE;
	} else {
		$buffer = '';
		$out = "GET ".$_SERVER["REQUEST_URI"]." HTTP/1.1\r\n";
		$out .= "Host: ".$config['services']."\r\n";
		!empty($_SERVER["HTTP_X_API_KEY"])?$out .= "X-API-KEY: ".$_SERVER["HTTP_X_API_KEY"]."\r\n":NULL;
		!empty($_SERVER["HTTP_X_TOKEN"])?$out .= "X-TOKEN: ".$_SERVER["HTTP_X_TOKEN"]."\r\n":NULL;
		!empty($_SERVER["HTTP_X_API_SESS"])?$out .= "X-API-SESS: ".$_SERVER["HTTP_X_API_SESS"]."\r\n":NULL;
		$out .= "Connection: Close\r\n\r\n";
		fwrite($fp, $out);
		while (!feof($fp)) {
			$buffer .= fgets($fp, 128);
		}
		fclose($fp);
	}
	return $buffer;
}
function post_services() {
	global $config;
	$fp = fsockopen($config['services'], 80, $errno, $errstr, 30);
	if (!$fp) {
		return FALSE;
	} else {
		$buffer = '';
		$out = "POST ".$_SERVER["REQUEST_URI"]." HTTP/1.1\r\n";
		$out .= "Host: ".$config['services']."\r\n";
		!empty($_SERVER["HTTP_X_API_KEY"])?$out .= "X-API-KEY: ".$_SERVER["HTTP_X_API_KEY"]."\r\n":NULL;
		!empty($_SERVER["HTTP_X_TOKEN"])?$out .= "X-TOKEN: ".$_SERVER["HTTP_X_TOKEN"]."\r\n":NULL;
		!empty($_SERVER["HTTP_X_API_SESS"])?$out .= "X-API-SESS: ".$_SERVER["HTTP_X_API_SESS"]."\r\n":NULL;
		$out .= "Content-type: application/x-www-form-urlencoded\r\n";
		$out .= "Content-Length: ".strlen(http_build_query($_POST))."\r\n";
		$out .= "Connection: Close\r\n\r\n";
		$out .= http_build_query($_POST);
		fwrite($fp, $out);
		while (!feof($fp)) {
			$buffer .= fgets($fp, 128);
		}
		fclose($fp);
	}
	return $buffer;
}
function fcache_isset($key)
{
	global $config;
	return @file_exists($config['fcache_path'].$key);
}

function fcache_unset($key)
{
	global $config;
	return @unlink($config['fcache_path'].$key);
}
function delTree($dir) { 
    $files = glob( $dir . '*', GLOB_MARK ); 
    foreach( $files as $file ){ 
        if( substr( $file, -1 ) == '/' ) 
            delTree( $file ); 
        else 
            unlink( $file ); 
    } 
    rmdir( $dir ); 
}
function fcache_get($key) {
	global $config;
	$val = @file_get_contents($config['fcache_path'].$key);
	if(empty($val))
		return NULL;
	else
		return $val;
}

function fcache_set($key, $val='') {
	global $config;
	if(!empty($val)) {
		$tmp = tempnam($config['fcache_path'], $key);
		if(@file_put_contents($tmp, $val)) {
			if(@rename($tmp, $config['fcache_path'].$key))
				return true;
			else
				return false;
		}
		else {
			return false;
		}
	}
	return true;
}

function fcache_parsetimout() {
	global $config;
	$folders = glob($config['fcache_apipath'].'*', GLOB_MARK );
	foreach($folders as $value) {
		$filetime = stat($value);
		$time = time();
		if($time-$filetime['mtime']>=$config['fache_timeout'])
			delTree($value);
	}
}
header("Content-Type: application/json");
// Intergrate function such as REQUEST_METHOD=="PUT" it identifies a header key password, and allows a controller directory to be deleted and cached for the next view.
// This would be sent by the services after recieving a post request it would send to an assigned list of cache servers to delete cache of it's current controller being accessed.
fcache_parsetimout();
if($_SERVER['REQUEST_METHOD']=="POST") {
	$string = post_services();
	$tok = strtok($string, "\r\n");
	if(substr($tok,0,10)=="HTTP/1.1 2") {
		if(is_dir($config['fcache_delete']))
			delTree($config['fcache_delete']);
		while($tok = strtok("\r\n"))
			if(substr($tok,0,15)=="Content-Length:")
				break;
		$len = substr($tok,16);
		$content = substr($string,intval("-".$len));
	}
	else {
		while($tok = strtok("\r\n"))
			if(substr($tok,0,15)=="Content-Length:")
				break;
		$len = substr($tok,16);
		$content = substr($string,intval("-".$len));
	}
}
elseif($_SERVER['REQUEST_METHOD']=="DELETE") {
	echo $_SERVER["REQUEST_URI"];
}
else {
	if(fcache_isset($hash)) {
		$content = fcache_get($hash);
		for($i=0;$i<=50 && $content=='reserve';$i++) { //if reserved, loop and sleep for request to be made check contents again, if change, set contents, if unset, send service call, and try to set yourself.
			usleep(2000);
			if($i==50) // On last loop, assume cache failed to set and unset.
				fcache_unset($hash);
			if(!fcache_isset($hash)) { // check if file becomes unset whether be on last loop, or outside script unset on failure.
				fcache_set($hash, 'reserve');
				$string = get_services();
				$tok = strtok($string, "\r\n");
				if($tok=="HTTP/1.1 200 OK") {
					while($tok = strtok("\r\n"))
						if(substr($tok,0,15)=="Content-Length:")
							break;
					$len = substr($tok,16);
					$content = substr($string,intval("-".$len));
					fcache_set($hash, $content);
				}
				else {
					fcache_unset($hash); // unset if failed
					die('failed');
				}
				break;
			}
			$content = fcache_get($hash);
		}
	}
	else {
		if(!is_dir($config['fcache_path']))
			mkdir($config['fcache_path'],0777,true);
		fcache_set($hash, 'reserve'); // sets a reserved file so other requests know a cache query is in progress as it takes about 50ms to retrieve data from services
		$string = get_services();
		$tok = strtok($string, "\r\n");
		if($tok=="HTTP/1.1 200 OK") {
			while($tok = strtok("\r\n"))
				if(substr($tok,0,15)=="Content-Length:")
					break;
			$len = substr($tok,16);
			$content = substr($string,intval("-".$len));
			fcache_unset($hash); // unset to be rewritten
			fcache_set($hash, $content);
		}
		else {
			fcache_unset($hash); // unset if failed
			die('failed');
		}
	}
}

header("Exec-Time: ".(microtime(true) - $time)."s");
echo $content;
?>
