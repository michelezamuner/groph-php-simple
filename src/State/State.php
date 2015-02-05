<?php
namespace State;

use Tag\Collection as TagCollection;
use Resource\Collection as ResCollection;
use State\Action\Action;
use State\Action\Get;
use State\Action\Post;
use Vector;

class State
{
	public static function create(
			TagCollection $tagCollection,
			ResCollection $resCollection)
	{
		return new self($tagCollection, $resCollection);
	}

	private $tagCollection = Null;
	private $resCollection = Null;
	private $location = Null;
	private $actions = Array();
	
	public function __construct(
			TagCollection $tagCollection,
			ResCollection $resCollection)
	{
		$this->tagCollection = $tagCollection;
		$this->resCollection = $resCollection;
		$this->location = new Location();
		
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
		$this->addAction(Post::create('tag:add',
				Array('name', 'parent')));
		$this->addAction(Get::create('tag:select',
				Array('id')));
		$this->addAction(Post::create('tag:edit',
				Array('id', 'name', 'parent')));
		$this->addAction(Post::create('tag:delete',
				Array('id')));
	}
	
	public function getLocation()
	{
		return $this->location;
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
	
	public function getTagAdd()
	{
		return $this->actions['tag:add'];
	}
	
	public function getTagSelect()
	{
		return $this->actions['tag:select'];
	}
	
	public function getSelectedTag()
	{
		return $this->tagCollection->find(
				(int)$this->getTagSelect()
				->getParam('id')->getValue());
	}
	
	public function getTagEdit()
	{
		return $this->actions['tag:edit'];
	}
	
	public function getTagDelete()
	{
		return $this->actions['tag:delete'];
	}
	
	public function getPost()
	{
		foreach ($this->actions as $action)
			if ($action instanceof Post && $action->getIsSet())
				return $action;
		return Null;
	}
	
	private function addAction(Action $action)
	{
		$this->actions[$action->getName()] = $action;
		return $this;
	}
}