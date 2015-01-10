<?php
class Location
{
	private $path = null;
	private $params = array();

	public function __construct()
	{
		$components = parse_url($_SERVER['REQUEST_URI']);
		$this->path = $components['path'];
		if (isset($components['query'])) {
			foreach (explode('&', $components['query']) as $paramString) {
				$paramElems = explode('=', $paramString);
				$this->params[urldecode($paramElems[0])] = urldecode($paramElems[1]);
			}
		}
	}

	public function getPath()
	{
		return $this->path;
	}

	public function getParam($name)
	{
		return isset($this->params[$name]) ? $this->params[$name] : '';
	}

	public function setParam($name, $value)
	{
		$this->params[$name] = $value;
		return $this;
	}

	public function getUrl()
	{
		$params = array();
		foreach ($this->params as $name => $value)
			$params[] = urlencode($name).'='.urlencode($value);
		return $this->path.(empty($params) ? '' : '?'.implode('&', $params));
	}

	public function getClone()
	{
		return clone $this;
	}
}