<?php
namespace Tag;

use Vector;
use Model\Collection as ModelCollection;

class Collection extends ModelCollection
{
	/**
	 * @param String $string
	 * @return Vector
	 */
	public static function parseTagsNames($string)
	{
		$tags = Vector::create();
		$string = preg_replace('/\s+,\s+/', ',', $string);
		$groups = empty($string) ? Array() : explode(',', $string);
		foreach ($groups as $group)
			$tags[] = Vector::explode(':', $group)
			->map(function($tag) { return trim($tag); });
		return $tags;
	}
	
	private $parentsIndex = array();

	public function __construct(Factory $factory)
	{
		parent::__construct($factory);
		foreach ($this->db->select('child, parent', $this->relationsTable, '') as $row) {
			if (!isset($this->parentsIndex[$row['child']]))
				$this->parentsIndex[$row['child']] = array($row['parent']);
			else
				$this->parentsIndex[$row['child']][] = $row['parent'];
		}
	}

	public function getMainTable()
	{
		return 'tag';
	}

	public function getRelationsTable()
	{
		return 'tag_tag';
	}

	public function load(Array $seeds)
	{
		foreach ($seeds as $childName => $parents) {
			$child = $this->findOrAdd(array($childName));
			foreach ($parents as $parentName) {
				$this->findOrAdd(array($parentName))->addChild($child)->save();
			}
		}
		return $this;
	}
		
	public function findByName($name)
	{
		$result = $this->db->selectFirst('id', $this->mainTable,
				'name = "'.$this->db->sanitize($name).'" COLLATE NOCASE');
		return $result ? $this->loadById($result->id) : null;
	}

	public function findLike(Array $attributes)
	{
		$name = $attributes[0];
		return $this->getSelect('id', $this->mainTable,
				'name LIKE "%'.$this->db->sanitize($name).'%" COLLATE NOCASE');
	}

	public function getRoots()
	{
		$columns = 't.id, t.name';
		$table = "$this->mainTable t LEFT JOIN $this->relationsTable r
		ON r.child = t.id";
		$where = 'r.id IS NULL ORDER BY t.name ASC';
		return $this->getSelect($columns, $table, $where);
	}

	protected function findByMainAttribute($attribute)
	{
	return $this->findByName($attribute);
	}

	protected function loadById($id)
	{
		$tag = $this->factory->create(array($this->db->selectFirst(
				'name', $this->mainTable, "id = $id")->name, $id));

		$columns = 'r.child, t.name';
		$table = "$this->relationsTable r LEFT JOIN $this->mainTable t
		ON t.id = r.child";
		$where = "r.parent = $id ORDER BY t.name ASC";
		foreach ($this->db->select($columns, $table, $where) as $row)
			$tag->addChild($this->loadById($row[0]));

		return $tag;
}

	protected function createTables()
	{
		$this->db->createTable($this->mainTable, 'name TEXT UNIQUE NOT NULL');
		$this->db->createTable($this->relationsTable,
					'child INTEGER NOT NULL, parent INTEGER NOT NULL');
		return $this;
	}
	
	protected function log($message)
	{
		$this->logger->log($message, 'TAG COLLECTION');
		return $this;
	}
	
	public function indexParent(Tag $child, Tag $parent)
	{
	if (!isset($this->parentsIndex[$child->getId()]))
		$this->parentsIndex[$child->getId()] = array($parent->getId());
		else if (!in_array($parent->getId(), $this->parentsIndex[$child->getId()]))
			$this->parentsIndex[$child->getId()][] = $parent->getId();
	}
	
	public function unIndexParent(Tag $child, Tag $parent)
	{
		$index = array_search($parent->getId(), $this->parentsIndex[$child->getId()]);
		unset($this->parentsIndex[$child->getId()][$index]);
	}
	
	public function getIndexParents(Tag $child)
		{
		$self = $this;
		return isset($this->parentsIndex[$child->getId()])
		? array_map(function($id) use ($self) {
		return $self->find($id);
		}, $this->parentsIndex[$child->getId()])
		: array();
	}
}