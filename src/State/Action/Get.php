<?php
namespace State\Action;

use Exception;

class Get extends Action
{
	public function __construct($name, Array $params)
	{
		parent::__construct($name, $params);
		
		// Checks that all parameters are passed, or none.
		$expected = NULL;
		foreach ($this->params as $param) {
			if ($expected === NULL) {
				$expected = isset($this->global[$param->getName()]);
			} else {
				if (isset($this->global[$param->getName()]) !== $expected)
					throw new Exception("Inconsistent usage of parameters of action $this");
			}
		}
		
		// A GET action should always have at least one parameter
		if ($expected === NULL)
			throw new Exception("GET action $this has no registered parameter");
	}
	
	public function concreteGetIsSet()
	{
		$firstParam = $this->params->getIterator()
			->current()->getName();
		return isset($this->global[$firstParam]); 
	}
	
	protected function setGlobal()
	{
		$this->global = $_GET;
	}
}