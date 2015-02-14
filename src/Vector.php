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
		return empty($string)
			? new self()
			: new self(explode($delimiter, $string));
// 		return new self(explode($delimiter, $string));
	}
	
	public function get($index)
	{
		return $this[$index];
	}
	
	public function getFirst()
	{
		return $this[0];
	}
	
	public function getLast()
	{
		return $this[$this->count() - 1];
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
	
	public function toJson()
	{
		$json = json_encode($this);
		$result = '';
		$pos = 0; // indentation level
		$strLen = strlen($json);
		$indentStr = "\t";
		$newLine = "\n";
		$prevChar = '';
		$outOfQuotes = true;
		for ($i = 0; $i < $strLen; $i++) {
			// Speedup: copy blocks of input which don't matter re string detection and formatting.
			$copyLen = strcspn($json, $outOfQuotes ? " \t\r\n\",:[{}]" : "\\\"", $i);
			if ($copyLen >= 1) {
				$copyStr = substr($json, $i, $copyLen);
				// Also reset the tracker for escapes: we won't be hitting any right now
				// and the next round is the first time an 'escape' character can be seen again at the input.
				$prevChar = '';
				$result .= $copyStr;
				$i += $copyLen - 1; // correct for the for(;;) loop
				continue;
			}
			// Grab the next character in the string
			$char = substr($json, $i, 1);
			// Are we inside a quoted string encountering an escape sequence?
			if (!$outOfQuotes && $prevChar === '\\') {
				// Add the escaped character to the result string and ignore it for the string enter/exit detection:
				$result .= $char;
				$prevChar = '';
				continue;
			}
			// Are we entering/exiting a quoted string?
			if ($char === '"' && $prevChar !== '\\') {
				$outOfQuotes = !$outOfQuotes;
			}
			// If this character is the end of an element,
			// output a new line and indent the next line
			else if ($outOfQuotes && ($char === '}' || $char === ']')) {
				$result .= $newLine;
				$pos--;
				for ($j = 0; $j < $pos; $j++) {
					$result .= $indentStr;
				}
			}
			// eat all non-essential whitespace in the input as we do our own here and it would only mess up our process
			else if ($outOfQuotes && false !== strpos(" \t\r\n", $char)) {
				continue;
			}
			// Add the character to the result string
			$result .= $char;
			// always add a space after a field colon:
			if ($outOfQuotes && $char === ':') {
				$result .= ' ';
			}
			// If the last character was the beginning of an element,
			// output a new line and indent the next line
			else if ($outOfQuotes && ($char === ',' || $char === '{' || $char === '[')) {
				$result .= $newLine;
				if ($char === '{' || $char === '[') {
					$pos++;
				}
				for ($j = 0; $j < $pos; $j++) {
					$result .= $indentStr;
				}
			}
			$prevChar = $char;
		}
		return $result;
	}
}