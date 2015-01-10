<?php
class Logger
{
	private $file = '';

	public function __construct($file)
	{
		date_default_timezone_set('Europe/Rome');
		$this->file = __DIR__.'/'.$file;
	}

	public function log($message, $context)
	{
		$message = date('Y-m-d H:i:s')." [$context] $message";
		file_put_contents($this->file, $message.PHP_EOL, FILE_APPEND);
		return $this;
	}
}