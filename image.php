<?php
	set_include_path(get_include_path() . PATH_SEPARATOR .
		dirname(__FILE__) . "/include");

	require_once "config.php";

	$url = base64_decode($_GET['url']);

	$filename = CACHE_DIR . '/images/' . sha1($url) . '.png';

	if (file_exists($filename)) {
		header("Content-type: image/png");
		echo file_get_contents($filename);
	} else {
		header("Location: $url");
	}
?>
