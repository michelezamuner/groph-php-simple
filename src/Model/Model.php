<?php
namespace Model;

use Exception;

abstract class Model
{
	protected $factory = null;
	protected $logger = null;
	protected $db = null;
	protected $collection = null;
	protected $id = Null;
	protected $changed = true;

	public function __construct(Factory $factory, $id = Null)
	{
		$this->logger = $factory->getLogger();
		$this->db = $factory->getDb();
		$this->collection = $factory->getCollection();
		if (!$this->collection)
			throw new Exception('Created model with no collection');
		$this->factory = $factory;
		$this->id = $id;
	}

	abstract public function __toString();

	abstract public function save();

	public function getId()
	{
		return $this->id;
	}

	abstract protected function log($message);
}