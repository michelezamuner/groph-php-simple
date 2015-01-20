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
		$tagsNames = Vector::create();
		$string = preg_replace('/\s+,\s+/', ',', $string);
		$groups = empty($string) ? Array() : explode(',', $string);
		foreach ($groups as $group)
			$tagsNames[] = Vector::explode(':', $group)
				->map(function($tagName) { return trim($tagName); });
		return $tagsNames;
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
	
	public function getSize()
	{
		$result = (array)$this->db->selectFirst('COUNT(*)',
				$this->mainTable, '');
		return $result['COUNT(*)'];
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
	
	public function findAll($name)
	{
		$column = 'id';
		$table = $this->mainTable;
		$where = 'name = "'.$this->db->sanitize($name).'" COLLATE NOCASE';
		return Vector::create($this->getSelect($column, $table, $where));
	}
	
	public function findFirst($name)
	{
		return $this->findAll($name)->getFirst();
	}

	public function getRoots()
	{
		$columns = 't.id, t.name';
		$table = "$this->mainTable t LEFT JOIN $this->relationsTable r
		ON r.child = t.id";
		$where = 'r.id IS NULL ORDER BY t.name ASC';
		return $this->getSelect($columns, $table, $where);
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
	
	/**
	 * Dato un percorso, cioè una lista di nomi di tag
	 * in cui ogni tag è genitrice della successiva,
	 * aggiunge eventualmente le tag che mancano per
	 * far sì che quel percorso esista veramente nella
	 * collezione. Questo sarà fatto minimizzando il
	 * numero di tag da aggiungere, quindi nel caso
	 * limite in cui il percorso già esista, non sarà
	 * aggiunta alcuna tag. Inoltre, è possibile che
	 * questo percorso sia costruito più volte in più
	 * punti diversi. Ritorna le root tag dei percorsi
	 * così creati. Opzionalmente, scrive in $children
	 * le tag foglie dei percorsi creati.
	 *
	 * @param Vector $path il percorso da creare nella collezione
	 * @param Vector $leaves Le foglie dei percorsi creati
	 * @return Vector $roots Le root dei percorsi creati
	 */
	public function createPath(Vector $path, Vector& $leaves)
	{
		// Cerco le più lunge porzioni di $path che già
		// esistono nella collezione.
		/**
		 * @var Vector $realLeaves Le foglie delle migliori
		 * porzioni di $path esistenti.
		 */
		$bestLeaves = Vector::create();
		/**
		 * @var Vector $tail Le ultime tag di $path non trovate
		 * nella collezione, che avrebbero dovuto essere discendenti
		 * delle $bestLeaves.
		*/
		$tail = Vector::create();
		$roots = $this->pathMatch($path, $bestLeaves, $tail);
	
		// Se la lista delle migliori foglie è vuota, vuol dire
		// che nessuna parte di $path è stata trovata, e quindi
		// che bisogna creare l'intero $path da zero.
		if ($bestLeaves->isEmpty()) {
			$pathCopy = $path->copy();
			$leafId = 0;
			$root = $pathCopy->shift();
			$roots = Vector::create($root);
			$this->add(Array($root))->addBranch($pathCopy, $leafId);
			$leaves->append($this->find($leafId));
		}
		
		// Se l'intero path è stato trovato, ritorno
		// le migliori foglie come leaves
		else if ($tail->isEmpty())
		{
			$leaves = $bestLeaves;
		}
	
		// Altrimenti bisogna creare la coda di tag, ed aggiungerla
		// duplicandola a tutte le migliori foglie
		else  {
			foreach ($bestLeaves as $head) {
				$leafId = 0;
				$head->addBranch($tail, $leafId);
				$leaves->append($this->find($leafId));
			}
			
			// Se le root coincidono con le leaves, in
			// questo passaggio le root sono state
			// modificate, quindi bisogna aggiornare
			// l'oggetto $roots.
			if ($bestLeaves == $roots)
				$roots = $this->pathMatch($path);
		}

		return $roots;
	}
	
	/**
	 * Dato un percorso, cioè una lista di nomi di tag
	 * in cui ogni tag è genitrice della successiva,
	 * verifica se esistono nella collezione dei rami
	 * di Tag che corrispondono completamente o
	 * parzialmente al percorso. Ritorna la lista delle
	 * Tag da cui discendono i rami che corrispondono
	 * meglio. Opzionalmente, si può passare per
	 * riferimento una lista che sarà popolata con le
	 * ultime Tag del percorso trovate, e un'altra lista
	 * che conterrà la coda del percorso contenente le
	 * Tag non trovate nella collezione.
	 * @param Vector $path
	 * @param Vector $leaves
	 * @param Vector $tail
	 */
	public function pathMatch(Vector $path, Vector& $leaves = Null, Vector& $tail = Null)
	{
		/**
		 * @var Integer $max Il massimo numero di
		 * passi che si è riusciti a fare seguendo il
		 * percorso passato lungo tutti i possibili rami
		 * della collezione.
		 */
		$max = 0;
		/**
		 * @var Vector $roots Le Tag dalle quali discendono
		 * i rami che corrispondono meglio.
		 */
		$roots = Vector::create();
	
		$pathCopy = $path->copy();
		foreach ($this->findAll($pathCopy->shift()) as $tag) {
			/**
			 * @var Integer $score Quanti passi sono stati
			 * fatti all'interno di questo branch. Parte da 1
			 * perché il primo passo è fatto nella tag corrente,
			 * che esiste, altrimenti non saremmo entrati nel ciclo.
			 */
			$score = 1;
			$leaf = $tag->visitPath($pathCopy, function() use(& $score) {
				$score++;
			});
			if ($score > $max) {
				$max = $score;
				$roots = Vector::create(Array($tag));
				if ($leaves) $leaves->void()->append($leaf);
			} else if ($score === $max) {
				$roots->append($tag);
				if ($leaves) $leaves->append($leaf);
			}
		}
	
		if ($tail) $tail = $path->getTail($max);
		return $roots;
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
}