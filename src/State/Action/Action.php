<?php
namespace State\Action;

use Vector;

abstract class Action
{
	public static function create($name, Array $params = Array())
	{
		return new static($name, $params);
	}
	
	protected $name = NULL;
	protected $global = NULL;
	protected $params = NULL;
	protected $isSet = NULL;
	
	public function __construct($name, Array $params)
	{
		$this->name = $name;
		$this->setGlobal();
		
		$this->params = Vector::create();
		foreach ($params as $name)
			$this->addParam($name);
	}
	
	public function __toString()
	{
		return $this->name;
	}
	
	public function getIsSet()
	{
		// Cache the result the first time
		if ($this->isSet === NULL)
			$this->isSet = $this->concreteGetIsSet();
		return $this->isSet;
	}
	
	public function getName()
	{
		return $this->name;
	}
	
	public function getParam($name)
	{
		$name = "$this->name:$name";
		return $this->params[$name];
	}
	
	public function addParam($name)
	{
		$name = "$this->name:$name";
		$value = isset($this->global[$name])
			? $this->global[$name] : '';
		$this->params[$name] = Param::create($name, $value);
		return $this;
	}
	
	abstract protected function setGlobal();
	abstract protected function concreteGetIsSet();
}