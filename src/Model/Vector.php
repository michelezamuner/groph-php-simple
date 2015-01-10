<?php
namespace Model;

use ArrayObject;

class Vector extends ArrayObject
{
	public function toArray()
	{
		$array = array();
		foreach ($this as $model)
			$array[] = (string)$model;
		return $array;
	}
	
	public function map($callback)
	{
		return new self(array_map($callback), $this->getArrayCopy());
	}

	public function implode($glue)
	{
		return implode($glue, $this->getArrayCopy());
	}
}