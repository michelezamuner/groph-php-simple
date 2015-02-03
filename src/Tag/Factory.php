<?php
namespace Tag;

use Model\Factory as ModelFactory;

class Factory extends ModelFactory
{
	private $listeners = array();

	public function create(Array $attributes)
	{
		$name = $attributes[0];
		$id = isset($attributes[1]) ? $attributes[1] : Null;
		$tag = new Tag($this, $name, $id);
		foreach ($this->listeners as $listener)
			$tag->addListener($listener);

		return $tag;
	}

	public function addListener(Listener $listener)
	{
		$this->listeners[] = $listener;
	}

	protected function createCollection()
	{
		return new Collection($this);
	}

	protected function log($message)
	{
		$this->logger->log($message, 'TAG FACTORY');
	}
}