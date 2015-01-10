<?php
namespace Tag;

use Model\Model;

class Tag extends Model
{
	private $name = '';
	private $children = array();
	private $parents = array();
	private $listeners = array();

	public function __construct(Factory $factory, $name, $id = 0)
	{
		parent::__construct($factory, $id);
		$this->name = $this->db->sanitize($name);
		if (empty($this->name))
			throw new Exception('Cannot create new tag with empty name');
	}

	public function __toString()
	{
		return $this->name;
	}

	public function addListener(Listener $listener)
	{
		$this->listeners[] = $listener;
	}

	public function save()
	{
		if (!$this->changed) return;
		$mainTable = $this->collection->getMainTable();
		$relationsTable = $this->collection->getRelationsTable();
		if (!$this->id)
			$this->id = $this->db->insert($mainTable, array('name' => $this->name));
		else
			$this->db->update($mainTable,
					array('name' => $this->name), array('id' => $this->id));
		 
		foreach ($this->getChildren() as $child) {
			if (!$this->db->exists($relationsTable,
					"child = {$child->getId()} AND parent = $this->id"))
						$this->db->insert($relationsTable,
								array('child' => $child->getId(), 'parent' => $this->id));
					$child->save();
		}
		 
		// Cerca se nel database sono registrati più figli di quelli reali,
		// in questo caso rimuovili
		$childIds = array_map(function(Tag $tag) {
			return $tag->getId();
		}, $this->children);
		foreach ($this->db->select('child', $relationsTable, "parent = $this->id") as $row) {
			$childId = $row['child'];
			if (!in_array($childId, $childIds))
				$this->db->exec("DELETE FROM $relationsTable WHERE child = $childId AND parent = $this->id");
		}
		 
		$this->changed = false;
		 
		return $this;
	}

	public function getName()
	{
		return $this->name;
	}

	public function setName($name)
	{
		$this->name = $this->db->sanitize($name);
		$this->changed = true;
		return $this;
	}

	public function getChildren()
	{
		return $this->children;
	}

	public function addChild(Tag $tag)
	{
		foreach ($this->children as $child) {
			if ($child->getName() === $tag->name)
				throw new \InvalidArgumentException('Tag '.$tag->getName().'
    					is already child of '.$this->name);
		}
		$this->children[] = $tag;
		$this->factory->getCollection()->indexParent($tag, $this);
		$this->changed = true;
		return $this;
	}

	public function removeChild(Tag $tag)
	{
		$found = null;
		foreach ($this->children as $id => $child) {
			if ($child->name === $tag->name) {
				$found = $id;
				break;
			}
		}
		if ($found === null)
			throw new InvalidArgumentException('Tag '.$tag->name.'
    			is not child of '.$this->name);
			 
			unset($this->children[$id]);
			$this->collection->unIndexParent($tag, $this);
			return $this;
	}

	public function getParents()
	{
		return $this->collection->getIndexParents($this);
	}

	public function delete()
	{
		foreach ($this->getParents() as $parent)
			$parent->removeChild($this)->save();
		 
		foreach ($this->getChildren() as $child)
			$this->removeChild($child);
		$this->save();
		 
		$this->db->exec('DELETE FROM '.$this->collection->getMainTable().
				" WHERE id = $this->id");
		 
		foreach ($this->listeners as $listener)
			$listener->onDelete($this);
	}

	protected function log($message)
	{
		$this->logger->log($message, "TAG {$this->id}");
	}
}