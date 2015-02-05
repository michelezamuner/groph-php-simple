<?php
namespace Resource;

use Model\Factory as ModelFactory;
use Tag\Factory as TagFactory;
use Database;
use Logger;

class Factory extends ModelFactory
{
	private $tagFactory = null;

	public function __construct(TagFactory $tagFactory, Database $database, Logger $logger)
	{
		$this->tagFactory = $tagFactory;
		parent::__construct($database, $logger);
	}

	public function create(Array $params)
	{
		$link = trim($params[0]);
		$name = Database::sanitize($params[1]);
		$tagFactory = $this->tagFactory;
		$tags = isset($params[2]) ? $params[2] : array();
		$id = isset($params[3]) ? $params[3] : 0;
		return new Resource($this, $link, $name, $tags, $id);
	}

	protected function createCollection()
	{
		return new Collection($this, $this->tagFactory);
	}

	protected function log($message)
	{
		$this->logger->log($message, 'RESOURCE FACTORY');
	}
}