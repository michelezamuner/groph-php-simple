<?php
namespace State;

use State\Action\Action;
use State\Action\Get;
use State\Action\Post;
use Vector;
use Resource\Collection as ResCollection;

class State
{
	public static function create(ResCollection $resCollection)
	{
		return new self($resCollection);
	}
	
	private $resCollection = Null;
	private $actions = Array();
	
	public function __construct(ResCollection $resCollection)
	{
		$this->resCollection = $resCollection;
		$this->addAction(Post::create('manage:export'));
		$this->addAction(Post::create('manage:import',
				Array('file')));
		$this->addAction(Get::create('search',
				Array('query')));
		$this->addAction(Post::create('resource:add',
				Array('link', 'title', 'tags')));
		$this->addAction(Get::create('resource:add:prefill',
				Array('link', 'title')));
		$this->addAction(Get::create('resource:select',
				Array('id')));
		$this->addAction(Post::create('resource:edit',
				Array('id', 'link', 'title', 'tags')));
		$this->addAction(Post::create('resource:delete'));
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
	
	public function getResourceSelect()
	{
		return $this->actions['resource:select'];
	}
	
	public function getResourceEdit()
	{
		return $this->actions['resource:edit'];
	}
	
	public function getSelectedResource()
	{
		return $this->resCollection->find(
				(int)$this->getResourceSelect()
					->getParam('id')->getValue());
	}
	
	public function getResourceDelete()
	{
		return $this->actions['resource:delete'];
	}
	
	public function getPost()
	{
		foreach ($this->actions as $action)
			if ($action instanceof Post && $action->getIsSet())
				return $action;
		return NULL;
	}
}