<?php
class State
{
	public static function create(Vector $configuration)
	{
		return new self($configuration);
	}
	
	private $conf = NULL;
	private $posts = NULL;
	private $searchQuery = NULL;
	
	public function __construct(Vector $configuration)
	{
		$this->conf = $configuration;
		
		$this->posts = Vector::create();
		foreach ($this->conf['actions'] as $action)
			if (isset($action['post']))
				$this->posts->merge($action['post']);

		$searchQuery = $this->conf->get('actions/search/query');
		$this->searchQuery = isset($_GET[$searchQuery])
			? trim($_GET[$searchQuery]) : '';
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
	
	public function getSearchQuery()
	{
		return $this->searchQuery;
	}
}