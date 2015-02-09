<?php
namespace Resource;

use Model\Collection as ModelCollection;
use Tag\Tag;
use Tag\Listener;
use Tag\Factory as TagFactory;
use Vector;

class Collection extends ModelCollection implements Listener
{
	private $tagFactory = null;

	public function __construct(Factory $factory, TagFactory $tagFactory)
	{
		parent::__construct($factory);
		$this->tagFactory = $tagFactory;
	}

	public function onDelete(Tag $tag)
	{
		$this->db->exec('DELETE from '.$this->relationsTable.'
				WHERE tag = '.$tag->getId());
		return $this;
	}

	public function getMainTable()
	{
		return 'resource';
	}

	public function getRelationsTable()
	{
		return 'resource_tag';
	}

	public function load(Array $seeds)
	{
		$tagCollection = $this->tagFactory->getCollection();
		foreach ($seeds as $link => $attributes) {
			$tags = Vector::create();
			foreach($attributes[1] as $tagName)
				$tags->merge($tagCollection->findAll($tagName));
// 			$tags = array_map(function($tagName) use ($tagCollection) {
// 				return $tagCollection->findByName($tagName);
// 			}, $attributes[1]);
			$res = $this->findOrAdd(array($link, $attributes[0], (Array)$tags));
		}
	}

	public function findByLink($link)
	{
		$result = $this->db->selectFirst('id', $this->mainTable,
				"link = '$link' COLLATE NOCASE");
		return $result ? $this->loadById($result->id) : null;
	}

	public function findLike(Array $attributes)
	{
		$term = $attributes[0];
		return $this->getSelect('id', $this->mainTable,
				"link LIKE '%$term%' OR title LIKE '%$term%' COLLATE NOCASE");
	}

	public function findByTag(Tag $tag)
	{
		return $this->getSelect('resource', $this->relationsTable,
				'tag = '.$tag->getId(), 'resource');
	}

	protected function findByMainAttribute($attribute)
	{
		return $this->findByLink($attribute);
	}

	protected function loadById($id)
	{
		$res = $this->db->selectFirst('link, title', $this->mainTable, "id = $id");
		$tags = array();
		foreach ($this->db->select('tag', $this->relationsTable, "resource = $id") as $row) {
			$tags[] = $this->tagFactory->getCollection()->find($row[0]);
			$tag = $this->tagFactory->getCollection()->find($row[0]);
		}

		return $this->factory->create(array($res->link, $res->title, $tags, $id));
	}

	protected function createTables()
	{
		$this->db->createTable($this->mainTable,
				'link TEXT UNIQUE NOT NULL, title TEXT NOT NULL');
		$this->db->createTable($this->relationsTable,
				'resource INTEGER NOT NULL, tag INTEGER NOT NULL');
		return $this;
	}

	protected function log($message)
	{
		$this->logger->log($message, 'RESOURCE COLLECTION');
	}
}