<?php
namespace Model;

abstract class Model
{
	protected $factory = null;
	protected $logger = null;
	protected $db = null;
	protected $collection = null;
	protected $id = 0;
	protected $changed = true;

	public function __construct(Factory $factory, $id = 0)
	{
		$this->logger = $factory->getLogger();
		$this->db = $factory->getDb();
		$this->collection = $factory->getCollection();
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