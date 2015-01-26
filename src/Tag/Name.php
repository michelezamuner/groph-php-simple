<?php
namespace Tag;

class Name
{
	public static function create($string)
	{
		return new self($string);
	}
	
	public static function sanitize($string)
	{
		return trim(preg_replace('/\s+/', ' ', $string));
	}
	
	public static function normalize($string)
	{
		return strtolower(self::sanitize($string));
	}
	
	private $name;
	
	public function __construct($name)
	{
		if (empty($name))
			throw new Exception('Cannot construct Name from empty string');
		$this->name = self::sanitize($name);
	}
	
	public function __toString()
	{
		return $this->name;
	}
	
	public function toNorm()
	{
		return self::normalize($this);
	}
	
	public function matches($name)
	{
		if (!$name instanceof self)
			$name = self::create((string)$name);
		return $this->toNorm() === $name->toNorm();
	}
}