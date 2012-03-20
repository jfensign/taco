<?php

require "Taco.Class.php";

$T = new AccessControl(TRUE);

if(!$T->checkBlackListStatus()) {
	$T->BlackListUser();
}
else {
	header("Status: 403 Permission Denied");
	header("Content-type: text/html");
	exit("Your browsing behavior is not in compliance with our Terms or Service.");
}

?>
<h1>something</h1>