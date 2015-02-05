<?php
namespace Model;

use Database;
use Logger;

abstract class Factory
{
	protected $db = null;
	protected $logger = null;
	protected $collection = null;

	public function __construct(Database $database, Logger $logger)
	{
		$this->db = $database;
		$this->logger = $logger;
		$this->collection = $this->createCollection();
	}

	public static function createArray(Array $models)
	{
		return new ModelArray($models);
	}

	abstract public function create(Array $attributes);

	public function getCollection()
	{
		return $this->collection;
	}

	public function getDb()
	{
		return $this->db;
	}

	public function getLogger()
	{
		return $this->logger;
	}

	abstract protected function createCollection();
	abstract protected function log($message);
}