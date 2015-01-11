<?php
class State
{
	public static function create(Vector $configuration)
	{
		return new self($configuration);
	}
	
	private $conf = NULL;
	private $posts = NULL;
	
	public function __construct(Vector $configuration)
	{
		$this->conf = $configuration;
		
		$this->posts = Vector::create();
		foreach ($this->conf['actions'] as $action)
			if (isset($action['post']))
				$this->posts->merge($action['post']);
	}
	
	public function getPost()
	{
		foreach ($_POST as $param => $value) {
			if ($this->posts->has($param))
				return $param;
		}
		return NULL;
	}
	
	public function getSelectedTag()
	{
		
	}
}