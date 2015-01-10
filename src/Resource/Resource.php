<?php
namespace Resource;

use Model\Model;
use Tag\Tag;
use Model\Vector;

class Resource extends Model
{
	private $link = '';
	private $title = '';
	private $tags = array();

	public function __construct(Factory $factory, $link, $title,
			Array $tags = array(), $id = 0)
	{
		parent::__construct($factory, $id);
		$this->link = trim($link);
		$this->title = $title;
		foreach ($tags as $tag)
			$this->addTag($tag);
	}

	public function __toString()
	{
		return $this->link;
	}

	public function save()
	{
		if (!$this->changed) return;
		$mainTable = $this->collection->getMainTable();
		$relationsTable = $this->collection->getRelationsTable();
		if (!$this->id) {
			$this->id = $this->db->insert($mainTable,
					array('link' => $this->link, 'title' => $this->title));
		} else {
			$this->db->update($mainTable,
					array('link' => $this->link, 'title' => $this->title),
					array('id' => $this->id));
		}
			
		foreach ($this->getTags() as $tag) {
			if (!$this->db->exists($relationsTable,
					"tag = {$tag->getId()} AND resource = $this->id")) {
					$this->db->insert($relationsTable,
							array('tag' => $tag->getId(), 'resource' => $this->id));
			}

		}
		$this->changed = false;
		return $this;
	}

	public function getLink()
	{
		return $this->link;
	}

	public function setLink($link)
	{
		$this->link = $link;
		$this->changed = true;
		return $this;
	}

	public function getTitle()
	{
		return $this->title;
	}

	public function setTitle($title)
	{
		$this->title = $title;
		$this->changed = true;
		return $this;
	}

	public function getTags()
	{
		return new Vector($this->tags);
	}

	public function setTags(Array $tags)
	{
		$this->tags = $tags;
	}

	public function addTag(Tag $tag)
	{
		$this->tags[] = $tag;
		$this->changed = true;
		return $this;
	}

	public function delete()
	{
		$this->db->exec('DELETE FROM '.$this->collection->getMainTable().'
				WHERE id = '.$this->id);
		$this->db->exec('DELETE FROM '.$this->collection->getRelationsTable().'
				WHERE resource = '.$this->id);
		return $this;
	}

	protected function log($message)
	{
		$this->logger->log($message, 'RESOURCE');
		return $this;
	}
}