<?php
class Vector extends ArrayObject
{
	public static function create($arg = Array())
	{
		return is_array($arg)
			? new self($arg)
			: new self(func_get_args());
	}
	
	public static function explode($delimiter, $string)
	{
		return new self(explode($delimiter, $string));
	}
	
	public function get($index)
	{
		return $this[$index];
	}
	
	public function getFirst()
	{
		return $this[0];
	}
	
	public function void()
	{
		$this->exchangeArray(Array());
		return $this;
	}
	
	public function isEmpty()
	{
		return $this->count() === 0;
	}
	
	public function toStringsArray()
	{
		$array = Array();
		foreach ($this as $item)
			$array[] = (string)$item;
		return $array;
	}
	
	public function copy()
	{
		return new self($this->getArrayCopy());
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
		return new self(array_map($callback, $this->getArrayCopy()));
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
	
	public function shift()
	{
		$current = $this->getArrayCopy();
		$output = array_shift($current);
		$this->exchangeArray($current);
		return $output;
	}
	
	public function unshift($value)
	{
		$current = $this->getArrayCopy();
		array_unshift($current, $value);
		$this->exchangeArray($current);
		return $this;
	}
	
	public function reverse()
	{
		$this->exchangeArray(array_reverse(
				$this->getArrayCopy()));
		return $this;
	}
	
	public function getTail($offset)
	{
		$tail = self::create();
		for ($i = $offset; $i < $this->count(); $i++)
			$tail->append($this[$i]);
		return $tail;
	}
}