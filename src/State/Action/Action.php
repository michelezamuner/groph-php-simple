<?php
namespace State\Action;

class Action
{
	public static function create($type, $name)
	{
		return new self($type, $name);
	}
	
	private $name = NULL;
	private $global = NULL;
	private $params = array();
	
	private function __construct($type, $name)
	{
		$this->name = $name;
		if ($type === 'GET')
			$this->global = $_GET;
		else if ($type === 'POST')
			$this->global = $_POST;
		else
			throw new Exception('Invalid action type '.$type);
	}
	
	public function __toString()
	{
		return $this->name;
	}
	
	public function getName()
	{
		return $this->name;
	}
	
	public function getParam($name)
	{
		$name = "$this->name:$name";
		return isset($this->params[$name])
			? $this->params[$name]
			: NULL;
	}
	
	public function addParam($name)
	{
		$name = "$this->name:$name";
		$value = isset($this->global[$name])
			? $this->global[$name] : '';
		$this->params[$name] = Param::create($name, $value);
		return $this;
	}
}