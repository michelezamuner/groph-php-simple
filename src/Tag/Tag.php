<?php
namespace Tag;

use Model\Model;
use Exception;
use Vector;
use Closure;

class Tag extends Model
{
	private $name = Null;
// 	private $children = Array();
	private $children = False;
	private $listeners = array();
	private $parent = Null;
	private $removedChildren = array();
// 	public $ref = Null;
	
	public function __construct(Factory $factory, $name, $id = Null)
	{
// 		$this->ref = microtime(true);
		if (!is_numeric($id))
			$id = (int)"$id";
		parent::__construct($factory, $id);
		$this->name = Name::create($name);
	}

	public function __toString()
	{
		return (string)$this->name;
	}

	public function addListener(Listener $listener)
	{
		$this->listeners[] = $listener;
	}

	public function save()
	{
		if (!$this->changed) return;
		
// 		echo "Saving $this [$this->id]", PHP_EOL;
// 		if ($this->parent) echo "Parent of $this [$this->id] is $this->parent [$this->parent->id]", PHP_EOL;
// 		echo "Number of children: ".count($this->children), PHP_EOL;
		
		$mainTable = $this->collection->getMainTable();
		$relationsTable = $this->collection->getRelationsTable();
		
		// Create or update tag
		if (!$this->id) {
			$this->id = $this->db->insert($mainTable, array('name' => $this->name));
		} else {
			$this->db->update($mainTable,
					array('name' => $this->name), array('id' => $this->id));
		}
		
		// If this tag has a parent, add or update matching
		// relations record
		if ($this->getParent()) {
			if (!$this->db->exists($relationsTable, "child = $this->id")) {
				$this->db->insert($relationsTable,
						Array('child' => $this->id, 'parent' => $this->getParent()->id));
			} else {
				$this->db->update($relationsTable,
						Array('parent' => $this->getParent()->id), Array('child' => $this->id));
			}
		}
		// If this tag has no parent, delete matching
		// relations record if exists
		else if ($this->db->exists($relationsTable, "child = $this->id")) {
			$this->db->exec("DELETE FROM $relationsTable WHERE child = $this->id");
		}
		
		// Get children to save themselves and their own
		// parents
		foreach ($this->getChildren() as $child) {
// 			echo "Calling save() on $child [$child->id]", PHP_EOL;
			$child->save();
		}
		
		// Check if removed children are still without
		// a parent. In this case, delete their records
		// from relations table. If a new parent has been
		// set in the meanwhile, forget that ex child.
		foreach ($this->removedChildren as $id => $child) {
			if (!$child->parent)
				$this->db->exec("DELETE FROM $relationsTable WHERE child = $child->id");
// 			else
				unset($this->removedChildren[$id]);
		}
		
		// If new children where added, update database
// 		foreach ($this->getChildren() as $child) {
// 			if (!$this->db->exists($relationsTable,
// 					"child = {$child->getId()} AND parent = $this->id"))
// 						$this->db->insert($relationsTable,
// 								array('child' => $child->getId(), 'parent' => $this->id));
// 			$child->save();
// 		}
		
		// If old children were removed, update database
// 		$childIds = array_map(function(Tag $tag) {
// 			return $tag->getId();
// 		}, $this->children);
// 		foreach ($this->db->select('child', $relationsTable, "parent = $this->id") as $row) {
// 			$childId = $row['child'];
// 			if (!in_array($childId, $childIds))
// 				$this->db->exec("DELETE FROM $relationsTable WHERE child = $childId AND parent = $this->id");
// 		}
		 
		$this->changed = false;
		 
		return $this;
	}

	public function getName()
	{
		return $this->name;
	}

	public function setName($name)
	{
		$this->name = Name::create($name);
		$this->changed = true;
		return $this;
	}

	public function getChildren()
	{
// 		if (!count($this->children)) {
		if ($this->children === False) {
			$this->children = Array();
			$r = $this->collection->getRelationsTable();
			$m = $this->collection->getMainTable();
			$columns = 'r.child, t.name';
			$table = "$r r LEFT JOIN $m t ON t.id = r.child";
			$where = "r.parent = $this->id ORDER BY t.name ASC";
			foreach ($this->db->select($columns, $table, $where) as $row) {
				$this->children[] = $this->collection->find($row[0]);
			}
		}
		return $this->children;
	}
	
	public function getChild($name)
	{
		$result = Null;
		$self = $this;
		foreach ($this->getChildren() as $child) {
			if ($child->getName()->matches($name)) {
				$result = $child;
				break;
			}
		}
		return $result;
	}

	public function addChild(Tag $tag)
	{
		foreach ($this->getChildren() as $child) {
			if ($child->getName()->matches($tag->name))
				throw new Exception('Tag '.$this->name.
						' already has a child named '.$tag->name);
		}
		$this->children[] = $tag;
		
		// Remove tag from its old parent, but only if
		// it's different from $this
		$parent = $tag->getParent();
		if ($parent && $parent->id !== $this->id)
			$parent->removeChild($tag);
		$tag->setParent($this);
		
// 		$this->factory->getCollection()->indexParent($tag, $this);
		$this->changed = true;
		return $this;
	}

	public function removeChild(Tag $tag)
	{
		$found = null;
		foreach ($this->getChildren() as $id => $child) {
			if ((int)$child->id === (int)$tag->id) {
				$found = $id;
				break;
			}
		}
		if ($found === null)
			throw new Exception('Tag '.$tag->name.'
    			is not child of '.$this->name);
		
		unset($this->children[$found]);
		$this->removedChildren[] = $tag;
		$tag->setParent(Null);
// 		$this->collection->unIndexParent($tag, $this);
		$this->changed = True;
		return $this;
	}

// 	public function getParents()
// 	{
// 		return $this->collection->getIndexParents($this);
// 	}

	protected function setParent(Tag $parent = Null)
	{
		$this->parent = $parent;
		$this->changed = True;
	}
	
	public function getParent()
	{
		if (!$this->parent) {
			$result = $this->db->selectFirst('parent',
					$this->collection->getRelationsTable(),
					"child = $this->id");
			$this->parent = $result
				? $this->collection->find($result->parent)
			: Null;
		}
// 		return $result ? $this->collection->find($result->parent) : Null;

		return $this->parent;
// 		return $this->collection->getIndexParents($this);
// 		$parents = $this->getParents();
// 		return isset($parents[0]) ? $parents[0] : Null;
	}

	public function delete()
	{
		if ($this->getParent())
			$this->getParent()->removeChild($this)->save();
// 		foreach ($this->getParents() as $parent)
// 			$parent->removeChild($this)->save();
		 
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
	 * Se viene passato un Vector vuoto (quindi non viene
	 * aggiunta nessuna tag), per coerenza $leafId contiene
	 * l'id della tag corrente.
	 * @param Vector $names
	 */
	public function addBranch(Vector $names, &$leafId = Null)
	{
		$parent = $this;
		$child = Null;
		foreach ($names as $name) {
			$child = $this->collection->add(Array($name));
			$parent->addChild($child)->save();
			$parent = $child;
		}
		
		if ($leafId !== Null)
			$leafId = $child === Null ? $this->id : $child->getId();
		
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
	
	/**
	 * Search the collection for all tags named like
	 * this, and build a path of parents to uniquely
	 * identify this tag amongst all the others.
	 */
	public function getUniquePath()
	{
		$path = Vector::create($this->name);
		// Base case: there is only one tag named like
		// this: this one! Return a path with only this
		// tag.
		$tags=$this->collection->findAll($this->name);
		if ($tags->count() === 1) return $path;
		
		// If there's more than one tag named like this,
		// try with the parent.
		$parent = $this->getParent();
		
		// If this is a root tag (no parent), there is
		// more than one root tag with the same name.
		if (!$parent)
			throw new Exception("There are multiple root tags named $this->name");
		
		return $path->merge($parent->getUniquePath());
	}

	protected function log($message)
	{
		$this->logger->log($message, "TAG {$this->id}");
	}
}