<?php
class Vector extends ArrayObject
{
	public static function create(Array $array = Array())
	{
		return new self($array);
	}
	
	public function toStringsArray()
	{
		$array = Array();
		foreach ($this as $item)
			$array[] = (string)$item;
		return $array;
	}
	
	public function has($value)
	{
		return in_array($value, (array)$this);
	}
	
	/**
	 * @param Callable|Closure $callback
	 * @return Vector
	 */
	public function map($callback)
	{
		return new Self(array_map($callback), $this->getArrayCopy());
	}

	/**
	 * @param String $glue
	 * @return String
	 */
	public function implode($glue)
	{
		return implode($glue, $this->getArrayCopy());
	}
	
	/**
	 * @param ArrayObject|Array $array
	 * @return Vector
	 */
	public function merge($array)
	{
		if ($array instanceof ArrayObject)
			$array = $array->getArrayCopy();
		
		$this->exchangeArray(array_merge(
				$this->getArrayCopy(), $array));
		
		return $this;
	}
	
	/**
	 * Descend a vector of vectors, following
	 * the path formatted as 'key1/key2/...'
	 * 
	 * @param String $path
	 */
	public function get($path)
	{
		$result = $this;
		$steps = explode('/', $path);
		foreach ($steps as $step)
			$result = $result[$step];
		
		return is_array($result)
			? self::create($result)
			: $result;
	}
}