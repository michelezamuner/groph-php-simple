<?php
namespace Tag;

use Model\Model;
use Exception;
use Vector;
use Closure;

class Tag extends Model
{
	public static function normalize($name)
	{
		return strtolower($self->factory->getDb()
				->sanitize($name));
	}
	private $name = Null;
	private $children = array();
	private $parents = array();
	private $listeners = array();

	public function __construct(Factory $factory, $name, $id = 0)
	{
		if (empty($name))
			throw new Exception('Cannot create new tag with empty name');
		parent::__construct($factory, $id);
		$this->name = Name::create($name);
// 		$this->name = $this->db->sanitize($name);
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
	
	public function getChild($name)
	{
		$result = Null;
		$self = $this;
		foreach ($this->children as $child) {
			if ($child->getName()->matches($name)) {
				$result = $child;
				break;
			}
		}
		return $result;
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
	
	/**
	 * Crea un ramo di Tag (figlie le une delle altre) dai
	 * nomi passati, e lo aggiunge sotto la Tag corrente.
	 * Opzionalmente, ritorna nel parametro passato per
	 * riferimento l'id dell'ultima tag del ramo creata.
	 * @param Vector $names
	 */
	public function addBranch(Vector $names, & $leafId = Null)
	{
		$parent = $this;
		$child = Null;
		foreach ($names as $name) {
			$child = $this->collection->add(Array($name));
			$parent->addChild($child)->save();
			$parent = $child;
		}
		
		if ($leafId !== Null && $child !== Null)
			$leafId = $child->getId();
		
		return $this;
	}
	
	/**
	 * Visita i discendenti di questa Tag seguendo il
	 * percorso passato, finché è possibile, eseguendo
	 * la funzione di callback ad ogni passo. Ritorna
	 * l'ultima Tag visitata.
	 * @param Vector $path
	 * @param Closure $callback
	 */
	public function visitPath(Vector $path, Closure $callback)
	{
		$path = $path->copy();
		$child = $this->getChild($path->shift());
		
		if ($child) {
			$callback($child);
			return $child->visitPath($path, $callback);
		} else {
			return $this;
		}
	}

	protected function log($message)
	{
		$this->logger->log($message, "TAG {$this->id}");
	}
}