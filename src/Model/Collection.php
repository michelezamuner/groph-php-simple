<?php
namespace Model;

abstract class Collection
{
	protected $db = null;
	protected $factory = null;
	protected $logger = null;
	protected $mainTable = '';
	protected $relationsTable = '';

	public function __construct(Factory $factory)
	{
		$this->factory = $factory;
		$this->db = $factory->getDb();
		$this->logger = $factory->getLogger();
		$this->mainTable = $this->getMainTable();
		$this->relationsTable = $this->getRelationsTable();
		$this->createTables();
	}

	abstract public function getMainTable();
	abstract public function getRelationsTable();
	abstract public function load(Array $seeds);

	public function find($id)
	{
		return $this->db->exists($this->mainTable, "id = $id")
		? $this->loadById($id) : null;
	}

	public function findOrAdd(Array $attributes)
	{
		$model = $this->findByMainAttribute($attributes[0]);
		return $model ? $model : $this->add($attributes);
	}

	abstract public function findLike(Array $attributes);

	public function add(Array $attributes)
	{
		return $this->factory->create($attributes)->save();
	}

	protected function getSelect($fields, $table, $where, $attribute = 'id')
	{
		$results = array();
		foreach ($this->db->select($fields, $table, $where) as $row)
			$results[] = $this->loadById($row[$attribute]);
		return $results;
	}

	abstract protected function findByMainAttribute($attribute);
	abstract protected function loadById($id);
	abstract protected function createTables();
	abstract protected function log($message);
}