<?php
namespace State;

use State\Action\Action;
use State\Action\Get;
use State\Action\Post;
use Vector;

class State
{
	public static function create()
	{
		return new self();
	}
	
	private $actions = array();
	
	public function __construct()
	{
		$this->addAction(Post::create('manage:export'));
		$this->addAction(Post::create('manage:import',
				array('file')));
		$this->addAction(Get::create('search',
				array('query')));
		$this->addAction(Post::create('resource:add',
				array('link', 'title', 'tags')));
		$this->addAction(Get::create('resource:add:prefill',
				array('link', 'title')));
	}
	
	private function addAction(Action $action)
	{
		$this->actions[$action->getName()] = $action;
		return $this;
	}
	
	public function getExport()
	{
		return $this->actions['manage:export'];
	}
	
	public function getImport()
	{
		$import = $this->actions['manage:import'];
		return $this->actions['manage:import'];
	}
	
	public function getSearch()
	{
		return $this->actions['search'];
	}
	
	public function getSearchQuery()
	{
		return $this->getSearch()->getParam('query');
	}
	
	public function getResourceAdd()
	{
		return $this->actions['resource:add'];
	}
	
	public function getResourceAddPrefill()
	{
		return $this->actions['resource:add:prefill'];
	}
	
	public function getPost()
	{
		foreach ($this->actions as $action)
			if ($action instanceof Post && $action->getIsSet())
				return $action;
		return NULL;
	}
}