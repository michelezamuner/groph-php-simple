<?php
if (file_exists('configuration-local.php'))
	return include 'configuration-local.php';
else
	return array(
		'db' => 'groph.sqlite'
	);