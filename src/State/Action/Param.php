<?php
namespace State\Action;

class Param
{
	public static function create($name, $value)
	{
		return new self($name, $value);
	}
	
	private $name = NULL;
	private $value = NULL;
	
	private function __construct($name, $value)
	{
		$this->name = $name;
		$this->value = $value;
	}
	
	public function __toString()
	{
		return $this->value;
	}
	
	public function getName()
	{
		return $this->name;
	}
	
	public function getValue()
	{
		return $this->value;
	}
}