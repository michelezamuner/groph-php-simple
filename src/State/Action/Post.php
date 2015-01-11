<?php
namespace State\Action;

class Post extends Action
{
	public function concreteGetIsSet()
	{
		return isset($this->global[$this->getName()]);
	}

	protected function setGlobal()
	{
		$this->global = $_POST;
	}
}