<?php
namespace State;

use State\Action\Action;
use Vector;

class State
{
	public static function create()
	{
		return new self();
	}
	
	private $actions = array(
		'GET' => array(),
		'POST' => array()
	);
	
	public function __construct()
	{
		$this->addAction('POST', 'manage:export');
		$this->addAction('POST', 'manage:import',
				array('file'));
		$this->addAction('GET', 'search',
				array('query'));
	}
	
	private function addAction($type, $name, $params = array())
	{
		$action = Action::create($type, $name);
		foreach ($params as $paramName)
			$action->addParam($paramName);
		$this->actions[$type][$name] = $action;
		return $this;
	}
	
	public function getExport()
	{
		return $this->actions['POST']['manage:export'];
	}
	
	public function getImport()
	{
		$import = $this->actions['POST']['manage:import'];
		return $this->actions['POST']['manage:import'];
	}
	
	public function getSearch()
	{
		return $this->actions['GET']['search'];
	}
	
	public function getSearchQuery()
	{
		return $this->getSearch()->getParam('query');
	}
	
	public function getPost()
	{
		foreach ($this->actions['POST'] as $action) {
			if (isset($_POST[$action->getName()]))
				return $action;
		}
	}
}