<?php
class Logger
{
	private $file = '';
	
	public function __construct($file)
	{
		date_default_timezone_set('Europe/Rome');
		$this->file = __DIR__.'/'.$file;
	}
	
	public function log($message, $context)
	{
		$message = date('Y-m-d H:i:s')." [$context] $message";
		file_put_contents($this->file, $message.PHP_EOL, FILE_APPEND);
		return $this;
	}
}

class Database
{
	public static function sanitize($string)
	{
		return trim(preg_replace('/\s+/', ' ', $string));
	}
	
	private $db = null;
	private $logger = null;
	
	public function __construct($file, Logger $logger)
	{
		$this->db = new PDO("sqlite:$file");
		$this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$this->logger = $logger;
	}
	
	public function reset()
	{
		$tables = array();
		foreach ($this->select('name', 'sqlite_master', 'type=\'table\'') as $row)
			$tables[] = $row['name'];
		foreach ($tables as $table)
			$this->exec('DROP TABLE IF EXISTS '.$table);
	}
	
	public function createTable($table, $columns)
	{
		$this->exec("CREATE TABLE IF NOT EXISTS $table (
				id INTEGER PRIMARY KEY, $columns)");
		return $this;
	}
	
	public function exists($table, $where)
	{
		$result = (array)$this->selectFirst('COUNT(*)', $table, $where);
		return $result['COUNT(*)'] > 0;
	}
	
	public function select($fields, $table, $where)
	{
		$where = $where ? " WHERE $where" : '';
		return $this->query("SELECT $fields FROM $table$where");
	}
	
	public function selectFirst($fields, $table, $where)
	{
		return $this->select($fields, $table, $where)->fetchObject();
	}
	
	public function insert($table, Array $values)
	{
		$refs = self::getRefs($values);
		$cmd = $this->prepare('INSERT INTO '.$table.'
				('.implode(', ', array_keys($values)).')
				VALUES ('.implode(', ', $refs).')');
		foreach ($refs as $column => $ref)
			$cmd->bindParam($ref, $values[$column]);
		$cmd->execute();
		return $this->db->lastInsertId();
	}
	
	public function update($table, Array $values, Array $where)
	{
		$allValues = array_merge($values, $where);
		$refsValues = self::getRefsEquals($values);
		$refsWhere = self::getRefsEquals($where);
		
		$cmd = $this->prepare('UPDATE '.$table.'
				SET '.implode(', ', $refsValues).'
				WHERE '.implode(' AND ', $refsWhere));
		foreach (self::getRefs($allValues) as $column => $ref)
			$cmd->bindParam($ref, $allValues[$column]);
		$cmd->execute();
	}
	
	public function exec($statement)
	{
		return $this->db->exec($statement);
	}
	
	public function query($query)
	{
		return $this->db->query($query);
	}
	
	public function prepare($statement)
	{
		return $this->db->prepare($statement);
	}
	
	private static function getRefs(Array $values)
	{
		return self::mapRefs($values,
			function($column) { return ":$column"; });
	}
	
	private static function getRefsEquals(Array $values)
	{
		return self::mapRefs($values,
			function($column) { return "$column = :$column"; });
	}
	
	private static function mapRefs(Array $values, $callback)
	{
		$refs = array();
		foreach ($values as $column => $value)
			$refs[$column] = $callback($column);
		return $refs;
	}
	
	private function log($message)
	{
		$this->logger->log($message, 'DATABASE');
		return $this;
	}
}

abstract class ModelFactory
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

class TagFactory extends ModelFactory
{
	private $listeners = array();
	
	public function create(Array $attributes)
	{
		$name = $attributes[0];
		$id = isset($attributes[1]) ? $attributes[1] : 0;
		$tag = new Tag($this, $name, $id);
		foreach ($this->listeners as $listener)
			$tag->addListener($listener);
		return $tag;
	}
	
	public function addListener(TagListener $listener)
	{
		$this->listeners[] = $listener;
	}
	
	protected function createCollection()
	{
		return new TagCollection($this);
	}
	
	protected function log($message)
	{
		$this->logger->log($message, 'TAG FACTORY');
	}
}

class ResourceFactory extends ModelFactory
{
	private $tagFactory = null;
	
	public function __construct(TagFactory $tagFactory, Database $database, Logger $logger)
	{
		$this->tagFactory = $tagFactory;
		parent::__construct($database, $logger);
	}
	
	public function create(Array $params)
	{
		$link = trim($params[0]);
		$name = Database::sanitize($params[1]);
		$tagFactory = $this->tagFactory;
		$tags = isset($params[2]) ? $params[2] : array();
		$id = isset($params[3]) ? $params[3] : 0;
		return new Resource($this, $link, $name, $tags, $id);
	}
	
	protected function createCollection()
	{
		return new ResourceCollection($this, $this->tagFactory);
	}
	
	protected function log($message)
	{
		$this->logger->log($message, 'RESOURCE FACTORY');
	}
}

abstract class ModelCollection
{
	protected $db = null;
	protected $factory = null;
	protected $logger = null;
	protected $mainTable = '';
	protected $relationsTable = '';
	
	public function __construct(ModelFactory $factory)
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

class TagCollection extends ModelCollection
{
	private $parentsIndex = array();
	
	public function __construct(TagFactory $factory)
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
		$tag = $this->factory->create(array(
			$this->db->selectFirst('name', $this->mainTable, "id = $id")->name,
			$id));
		
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
		unset($this->parentsIndex[$child->getId()][$parent->getId()]);
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

class ResourceCollection extends ModelCollection implements TagListener
{
	private $tagFactory = null;
	
	public function __construct(ResourceFactory $factory, TagFactory $tagFactory)
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
			$tags = array_map(function($tagName) use ($tagCollection) {
				return $tagCollection->findByName($tagName);
			}, $attributes[1]);
			$res = $this->findOrAdd(array($link, $attributes[0], $tags));
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
		$link = $attributes[0];
		return $this->getSelect('id', $this->mainTable,
				"link LIKE '%$link%' COLLATE NOCASE");
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

abstract class Model
{
	protected $factory = null;
	protected $logger = null;
	protected $db = null;
	protected $collection = null;
	protected $id = 0;
	protected $changed = true;
	
	public function __construct(ModelFactory $factory, $id = 0)
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

interface TagListener
{
	public function onDelete(Tag $tag);
}

class Tag extends Model
{
    private $name = '';
    private $children = array();
    private $parents = array();
    private $listeners = array();
    
    public function __construct(TagFactory $factory, $name, $id = 0)
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
    
    public function addListener(TagListener $listener)
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
    			throw new InvalidArgumentException('Tag '.$tag->getName().' 
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

class Resource extends Model
{
	private $link = '';
	private $title = '';
	private $tags = array();
	
	public function __construct(ResourceFactory $factory, $link, $title,
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
		return new ModelArray($this->tags);
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

class ModelArray extends ArrayObject
{
	public function map($callback)
	{
		return new self(array_map($callback), $this->getArrayCopy());
	}
	
	public function implode($glue)
	{
		return implode($glue, $this->getArrayCopy());
	}
}

class Location
{
	private $path = null;
	private $params = array();
	
	public function __construct()
	{
		$components = parse_url($_SERVER['REQUEST_URI']);
		$this->path = $components['path'];
		if (isset($components['query'])) {
			foreach (explode('&', $components['query']) as $paramString) {
				$paramElems = explode('=', $paramString);
				$this->params[urldecode($paramElems[0])] = urldecode($paramElems[1]);
			}
		}
	}
	
	public function getPath()
	{
		return $this->path;
	}
	
	public function getParam($name)
	{
		return isset($this->params[$name]) ? $this->params[$name] : '';
	}
	
	public function setParam($name, $value)
	{
		$this->params[$name] = $value;
		return $this;
	}
	
	public function getUrl()
	{
		$params = array();
		foreach ($this->params as $name => $value)
			$params[] = urlencode($name).'='.urlencode($value);
		return $this->path.(empty($params) ? '' : '?'.implode('&', $params));
	}
	
	public function getClone()
	{
		return clone $this;
	}
}

function getTagLinks(Tag $tag) {
	global $path, $searchQuery, $selectedTag;
	$tagLink = $path.'?'.(empty($selectedTag) ? ''
						: 'tag='.urlencode($selectedTag).'&').
						urlencode('search:query').'='.
						urlencode('"tag:'.$tag->getName().'"').
						'&search=Search';
	$editLink = $path.'?'.(empty($searchQuery) ? ''
						: urlencode('search:query').'='.urlencode($searchQuery).
						'&search=Search&').'tag='.urlencode($tag->getName()).'&focus=tag';
	return '<a href="'.$tagLink.'">'.$tag->getName().'</a> <a href="'.
			$editLink.'">edit</a>';
}

function getTreeView(Array $roots, $ind) {
    $tree = '';
    foreach ($roots as $branch) {
        $tree .= $ind.'<dl>'.PHP_EOL;
		$tree .= $ind.'  '.'<dt>'.getTagLinks($branch).'</dt>'.PHP_EOL;
        $tree .= $ind.'  '.'<dd>'.PHP_EOL;
        $tree .= getTreeView($branch->getChildren(), $ind.'  ');
        $tree .= $ind.'  '.'</dd>'.PHP_EOL;
        $tree .= $ind.'</dl>'.PHP_EOL;
    }
    return $tree;
}

function getSearchResults() {
	global $tagCollection, $resCollection, $searchQuery;
	$results = array();
	$searchTerms = array();
	if (!empty($searchQuery)) {
		// Prima trovo tutte le stringhe tra virgolette
		preg_match_all('/"([^"]+)"/', $searchQuery, $matches);
		$searchTerms = array_merge($searchTerms, $matches[1]);
		// Poi prendo tutto quello che non è tra virgolette,
		// lo suddivido per spazi bianchi, e lo aggiungo
		$remainder = $searchQuery;
		foreach ($matches[1] as $match)
			$remainder = trim(str_replace("\"$match\"", '', $remainder));
		$remainder = empty($remainder) ? array() : explode(' ', $remainder);
		$searchTerms = array_merge($searchTerms, $remainder);
	}
	
	$intersect = array();
	
	foreach ($searchTerms as $term) {
		$matchingRes = array();
		
		// Se $term comincia con 'tag:', vuol dire che vogliamo
		// solo i figli diretti di questa tag.
		if (preg_match('/^tag:([^:]+)$/', $term, $matches)) {
			$searchedTag = $tagCollection->findByName($matches[1]);
			$matchingRes = $searchedTag ? array_map(function(Resource $res) {
				return $res->getId();
			}, $resCollection->findByTag($searchedTag)) : array();
		} else {
			// Trova tutte le tag che corrispondono a questo termine,
			// compresi tutti i figli
			$firstLevelTags = $tagCollection->findLike(array($term));
			$matchingTags = $firstLevelTags;
			$desc = function(Tag $tag) use (&$desc) {
				$children = $tag->getChildren();
				$descendants = $children;
				foreach ($children as $child)
					$descendants = array_merge($descendants, $desc($child));
				return $descendants;
			};
			foreach ($firstLevelTags as $tag) {
				// Recuperiamo la gerarchia di figli
				// e la aggiungiamo all'elenco di matching tags
				$matchingTags = array_merge(
						$matchingTags, $desc($tag));
			}
			$uniqueIds = array_unique(array_map(function(Tag $tag) {
				return $tag->getId();
			}, $matchingTags));
			$uniqueTags = array_map(function($id) use ($tagCollection) {
				return $tagCollection->find($id);
			}, $uniqueIds);
			foreach ($uniqueTags as $tag) {
				$matchingRes = array_merge($matchingRes, array_map(function(Resource $res) {
					return $res->getId();
				}, $resCollection->findByTag($tag)));
			}
		}
		
		$intersect = empty($intersect)
			? $matchingRes
			: array_intersect($intersect, $matchingRes);
	}
	foreach ($intersect as $resId)
		$results[] = $resCollection->find($resId);
	if (empty($searchTerms))
		$results = $resCollection->findLike(array(''));
	return $results;
}

function import($file) {
	global $tagCollection, $resCollection;
	$data = json_decode(file_get_contents($file), true);
	$tagCollection->load($data['tags']);
	$resCollection->load($data['resources']);
}

function prettifyJson($json) {
	$result = '';
	$pos = 0; // indentation level
	$strLen = strlen($json);
	$indentStr = "\t";
	$newLine = "\n";
	$prevChar = '';
	$outOfQuotes = true;
	for ($i = 0; $i < $strLen; $i++) {
		// Speedup: copy blocks of input which don't matter re string detection and formatting.
		$copyLen = strcspn($json, $outOfQuotes ? " \t\r\n\",:[{}]" : "\\\"", $i);
		if ($copyLen >= 1) {
			$copyStr = substr($json, $i, $copyLen);
			// Also reset the tracker for escapes: we won't be hitting any right now
			// and the next round is the first time an 'escape' character can be seen again at the input.
			$prevChar = '';
			$result .= $copyStr;
			$i += $copyLen - 1; // correct for the for(;;) loop
			continue;
		}
		// Grab the next character in the string
		$char = substr($json, $i, 1);
		// Are we inside a quoted string encountering an escape sequence?
		if (!$outOfQuotes && $prevChar === '\\') {
			// Add the escaped character to the result string and ignore it for the string enter/exit detection:
			$result .= $char;
			$prevChar = '';
			continue;
		}
		// Are we entering/exiting a quoted string?
		if ($char === '"' && $prevChar !== '\\') {
			$outOfQuotes = !$outOfQuotes;
		}
		// If this character is the end of an element,
		// output a new line and indent the next line
		else if ($outOfQuotes && ($char === '}' || $char === ']')) {
			$result .= $newLine;
			$pos--;
			for ($j = 0; $j < $pos; $j++) {
				$result .= $indentStr;
			}
		}
		// eat all non-essential whitespace in the input as we do our own here and it would only mess up our process
		else if ($outOfQuotes && false !== strpos(" \t\r\n", $char)) {
			continue;
		}
		// Add the character to the result string
		$result .= $char;
		// always add a space after a field colon:
		if ($outOfQuotes && $char === ':') {
			$result .= ' ';
		}
		// If the last character was the beginning of an element,
		// output a new line and indent the next line
		else if ($outOfQuotes && ($char === ',' || $char === '{' || $char === '[')) {
			$result .= $newLine;
			if ($char === '{' || $char === '[') {
				$pos++;
			}
			for ($j = 0; $j < $pos; $j++) {
				$result .= $indentStr;
			}
		}
		$prevChar = $char;
	}
	return $result;
}

function export($file) {
	global $tagCollection, $resCollection;
	$tags = array();
	$getExport = function(Array $tags) use (&$getExport, $tagCollection) {
		$export = $tags;
		foreach ($tags as $tag)
			$export = array_merge($export, $getExport($tag->getChildren()));
		$tagIds = array_map(function(Tag $tag) {
			return $tag->getId();
		}, $export);
		return array_map(function($id) use ($tagCollection) {
			return $tagCollection->find($id);
		}, array_unique($tagIds));
	};
	foreach ($getExport($tagCollection->getRoots()) as $tag) {
		if (!isset($tags[$tag->getName()])) $tags[$tag->getName()] = array();
		foreach ($tag->getChildren() as $child) {
			if (!isset($tags[$child->getName()])) {
				$tags[$child->getName()] = array($tag->getName());
			} elseif (!isset($tags[$child->getName()][$tag->getName()])) {
				$tags[$child->getName()][] = $tag->getName();
			}
		}
	}
	$resources = array();
	foreach ($resCollection->findLike(array('')) as $res)
		$resources[$res->getLink()] = array($res->getTitle(),
				(array)$res->getTags());
	file_put_contents($file, prettifyJson(json_encode(array(
		'tags' => $tags, 'resources' => $resources
	))));
}

function getTagWithDescendants(Array $tagsNames) {
	global $tagCollection;
	$tag = $tagCollection->findOrAdd(array($tagsNames[0]));
	if (count($tagsNames) > 1) {
		array_shift($tagsNames);
		$tag->addChild(getTagWithDescendants($tagsNames))->save();
	}
	return $tag;
}

function printTrace(Array $trace, $sep = PHP_EOL) {
	$string = '';
	foreach ($trace as $id => $call) {
		$file = isset($call['file']) ? $call['file'] : '';
		$line = isset($call['line']) ? $call['line'] : '';
		$class = isset($call['class']) ? $call['class'] : '';
		$type = isset($call['type']) ? $call['type'] : '';
		$function = isset($call['function']) ? $call['function'] : '';
		$args = implode(', ', array_map(function($arg) {
			if (is_object($arg))
				return get_class($arg);
			else if (is_array($arg))
				return 'Array';
			else
				return (string)$arg;
		}, $call['args']));
		$string .= "#$id [$file($line): $class$type$function($args)$sep";
	}
	return $string;
}

register_shutdown_function(function() {
	global $export;
	if ($export) return;
	$errors = error_get_last();
	if ($errors) {
		file_put_contents(__DIR__.'/errors.log',
				date('Y-m-d H:i:s').implode(PHP_EOL, $errors), FILE_APPEND);
		echo '<p>', implode('<br>', $errors), '</p>';
	}
});

set_error_handler(function($errno, $errstr, $errfile, $errline) {
	throw new Exception($errstr, $errno);
});

try {
	$configuration = include('configuration.php');
	$logger = new Logger('groph.log');
// 	$database = new Database('groph.sqlite', $logger);
	$database = new Database($configuration['db'], $logger);
	$export = false;
	$import = false;
	if (isset($_POST['manage:import'])) {
		move_uploaded_file($_FILES['manage:file']['tmp_name'], 'groph.json');
		$database->reset();
		$import = true;
	}
	
	$tagFactory = new TagFactory($database, $logger);
	$resFactory = new ResourceFactory($tagFactory, $database, $logger);
	$tagCollection = $tagFactory->getCollection();
	$resCollection = $resFactory->getCollection();
	$tagFactory->addListener($resCollection);
	if ($import) import('groph.json');
// 	export('groph.json');
// 	import('groph.json');

	if (isset($_POST['manage:export'])) {
		$export = true;
		export('groph.json');
		header('Content-Description: File Transfer');
		header('Content-Type: application/octet-stream');
		header('Content-Disposition: attachment; filename=groph.json');
		header('Expires: 0');
		header('Cache-Control: must-revalidate');
		header('Pragma: public');
		header('Content-Length: ' . filesize('groph.json'));
		readfile('groph.json');
		exit();
	}

	if (isset($_POST['tag:delete'])) {
		$tag = $tagCollection->findByName($_GET['tag'])->delete();
		if ($tag) $tag->delete();
	}
	
	$location = new Location();
	$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
	$searchQuery = isset($_GET['search']) ? trim($_GET['search:query']) : '';
	$selectedRes =  isset($_GET['res'])
		? $resCollection->find($_GET['res']) : null;
	
	if (isset($_GET['tag'])) {
		$selectedTag = $_GET['tag'];
		$tag = $tagCollection->findByName($selectedTag);
		if ($tag) {
			$searchParents = implode(',', array_map(function(Tag $tag) {
				return $tag->getName();
			}, $tag->getParents()));
		} else {
			$selectedTag = null;
		}
	}
	
	if (isset($_POST['add'])) {
		$tagsString = preg_replace('/\s+,\s+/', ',', $_POST['add:tags']);
		$tagGroups = empty($tagsString) ? array() : explode(',', $tagsString);
		$tags = array();
		foreach ($tagGroups as $group) {
			$tagsNames = array_reverse(explode(':', $group));
			getTagWithDescendants($tagsNames);
			$tags[] = $tagCollection->findByName($tagsNames[count($tagsNames) - 1]);
		}
		$resCollection->add(array($_POST['add:link'], $_POST['add:name'], $tags));
	}
	
	if (isset($_POST['tag:add'])) {
		$newTagName = $_POST['tag:add:name'];
		$tagGroups = explode(',', preg_replace('/\s+,\s+/', ',',
				$_POST['tag:add:parents']));
		foreach ($tagGroups as $group) {
			$tagsNames = array_merge(array_reverse(explode(':', $group)),
				array($newTagName));
			getTagWithDescendants($tagsNames);
		}
	}
	
	if (isset($_POST['tag:edit']) && $selectedTag) {
		// Se è stato richiesto un cambio di nome e di parents
		// contemporaneamente, prima cambiamo il nome, con tutto
		// quello che comporta, e poi, dalla nuova situazione,
		// cambiamo i parents
		
		$selectedTagObject = $tagCollection->findByName($selectedTag);
		$newName = $_POST['tag:edit:name'];
		if ($newName !== $selectedTag) {
			$tag = $tagCollection->findByName($newName);
			// Se non esistono tag con il nuovo nome, semplicemente
			// rinomina la tag corrente
			if (!$tag) {
				$selectedTagObject->setName($newName)->save();
			}
			// Altrimenti, bisogna spostare tutti i child e le risorse
			// della tag corrente nell'altra, ed eliminare la tag corrente
			else {
				foreach ($selectedTagObject->getChildren() as $child)
					$tag->addChild($child)->save();
				foreach ($resCollection->findByTag($selectedTagObject) as $resource)
					$resource->addTag($tag)->save();
				$selectedTagObject->delete();
				$selectedTagObject = $tag;
			}
			
			// Una volta cambiato nome, nella query string è rimasto
			// tag: con il nome vecchio, quindi se io facessi subito change
			// parents, avrei un errore, perché al ricaricamento della
			// pagina si cercherebbe di ripristinare la tag col nome vecchio.
			// In questo modo invece sparisce il form di edit tag, e uno deve
			// ricliccare, aggiornando la query tag:.
			$selectedTag = null;
		}
		
		$realParents = array_map(function(Tag $tag) {
			return $tag->getName();
		}, $selectedTagObject->getParents());
		$parentsNames = preg_replace('/,\s+\s+/', ',', $_POST['tag:edit:parents']);
		$parentsNames = empty($parentsNames)
				? array() : explode(',', $parentsNames);
		
		foreach ($realParents as $parentName) {
			if (!in_array($parentName, $parentsNames)) {
				$parent = $tagCollection->findByName($parentName);
				$parent->removeChild($selectedTagObject)->save();
			}
		}
		
		foreach ($parentsNames as $parentName) {
			$parents = explode(':', $parentName);
			$parent = $tagCollection->findByName($parents[0]);
			// Se il parent non esiste, oppure se la tag selezionata non
			// è già figlia di quel parent, aggiungi quel parent
			if ($parent) {
				$children = array_map(function(Tag $tag) {
					return $tag->getId();
				}, $parent->getChildren());
			}
			if (!$parent || !in_array($selectedTagObject->getId(), $children)) {
				getTagWithDescendants(array_reverse($parents));
				$tagCollection->findByName($parents[0])
					->addChild($selectedTagObject)
					->save();
			}
		}
	}
	
	if (isset($_POST['res:delete']) && $selectedRes) {
		$selectedRes->delete();
		$selectedRes = null;
	}
	
	if (isset($_POST['res:edit']) && $selectedRes) {
		$selectedRes->setLink($_POST['res:edit:link'])
			->setTitle($_POST['res:edit:title']);
		if (!empty($_POST['res:edit:tags'])) {
			$tags = array();
			$tagsString = preg_replace('/\s+,\s+/', ',', $_POST['res:edit:tags']);
			$tagsGroups = empty($tagsString) ? array() : explode(',', $tagsString);
			foreach ($tagsGroups as $tagGroup) {
				$tagsNames = array_reverse(explode(':', $tagGroup));
				getTagWithDescendants($tagsNames);
				$tags[] = $tagCollection->findByName($tagsNames[count($tagsNames) - 1]);
			}
			$selectedRes->setTags($tags);
		}
		$selectedRes->save();
	}
	
	if (isset($_GET['ajax'])) exit('success');
	
	$searchResults = getSearchResults();
} catch (Exception $e) {
	file_put_contents(__DIR__.'/errors.log',
			date('Y-m-d H:i:s').': '.$e->getMessage().PHP_EOL.
			printTrace($e->getTrace()), FILE_APPEND);
	echo '<p>', $e->getMessage(), '</p>';
	echo '<p>', printTrace($e->getTrace(), '<br>'), '</p>';
}
?>
<!doctype html>
<html lang="en">
	<head>
		<title>Groph</title>
		<meta charset="utf-8">
		<script src="//ajax.googleapis.com/ajax/libs/jquery/1.11.2/jquery.min.js"></script>
		<script>
			$(function() {
				<?php if (isset($_GET['focus'])): ?>
					<?php if ($_GET['focus'] === 'tag'): ?>
						$('input[name="tag:edit:name"]').focus();
					<?php elseif ($_GET['focus'] === 'res'): ?>
						$('input[name="res:edit:title"]').focus();
					<?php endif; ?>
				<?php endif; ?>
				<?php if (isset($_GET['link'])): ?>
					$('input[name="add:tags"]').focus();
					$('input[name="add"]').click(function(event) {
						event.preventDefault();
						<?php $url = $location->getClone()
							->setParam('ajax', '1')->getUrl(); ?>
						$.post('<?php echo $url; ?>', {
							'add': 'Add Resource',
							'add:link': '<?php echo $_GET['link']; ?>',
							'add:name': '<?php echo $_GET['title']; ?>',
							'add:tags': $('input[name="add:tags"]').val()
						}, function(data) {
							if (data !== 'success') alert(data);
							window.close();
						});
					});
				<?php endif; ?>
				<?php if (isset($selectedTag)): ?>
					$('input[name="tag:edit"]').click(function(event) {
						var oldName = '<?php echo $selectedTag; ?>';
						var oldParents = '<?php echo $searchParents; ?>';
						var newName = $('input[name="tag:edit:name"]').val();
						var newParents = $('input[name="tag:edit:parents"]').val();
						var message = '';
						if (oldName !== newName)
							message += 'Changing name "' + oldName
									+ '" to "' + newName + '".';
						if (oldParents !== newParents)
							message += 'Changing parents "' + oldParents
									+ '" to "' + newParents + '".';
						var answer = false;
						if (message !== '') {
							message += ' Continue?';
							answer = confirm(message);
						} else {
							alert('No changes detected');
						}
						if (message === '' || !answer)
							event.preventDefault();
					});
					$('input[name="tag:delete"]').click(function(event) {
						var answer = confirm('Deleting tag ' + '<?php echo $selectedTag; ?>. Continue?');
						if (!answer)
							event.preventDefault();
					});
				<?php endif; ?>
				<?php if ($selectedRes): ?>
					$('input[name="res:edit"]').click(function(event) {
						var oldTitle = '<?php echo $selectedRes->getTitle(); ?>';
						var oldLink = '<?php echo $selectedRes->getLink(); ?>';
						var oldTags = '<?php echo $selectedRes->getTags()->implode(', '); ?>';
						var newTitle = $('input[name="res:edit:title"]').val();
						var newLink = $('input[name="res:edit:link"]').val();
						var newTags = $('input[name="res:edit:tags"]').val();
						var message = '';
						if (oldTitle !== newTitle)
							message += 'Changing title "' + oldTitle
								+ '" to "' + newTitle + '". ';
						if (oldLink !== newLink)
							message += 'Changing link "' + oldLink
								+ '" to "' + newLink + '". ';
						if (oldTags !== newTags)
							message += 'Changing tags "' + oldTags
								+ '" to "' + newTags + '". ';
						var answer = false;
						if (message !== '') {
							message += 'Continue?';
							answer = confirm(message);
						} else {
							alert('No changes detected');
						}
						if (message === '' || !answer)
							event.preventDefault();
					});
					$('input[name="res:delete"]').click(function(event) {
						answer = confirm('Deleting resource <?php
								echo $selectedRes->getTitle(); ?>. Continue?');
						if (!answer)
							event.preventDefault();
					});
				<?php endif; ?>
			});
		</script>
	</head>
	<body>
		<form id="manage" method="POST" enctype="multipart/form-data">
			<input type="submit" name="manage:export" value="Export">
			<label>File</label>
			<input type="file" name="manage:file">
			<input type="submit" name="manage:import" value="Import">
		</form>
		<form id="search">
			<fieldset>
				<legend>Search Resources</legend>
				<input type="text" name="search:query" value="<?php echo $searchQuery ?>">
				<input type="submit" name="search" value="Search">
			</fieldset>
		</form>
		<form id="add" method="POST">
			<fieldset>
				<legend>Add New Resource</legend>
				<label>Link</label>
				<?php $link = isset($_GET['link']) ? urldecode($_GET['link']) : ''; ?>
				<input type="text" name="add:link" value="<?php echo $link; ?>">
				<label>Title</label>
				<?php $title = isset($_GET['title']) ? urldecode($_GET['title']) : ''; ?>
				<input type="text" name="add:name" value="<?php echo $title; ?>">
				<label >Tags</label>
				<input type="text" name="add:tags">
				<input type="submit" name="add" value="Add Resource">
			</fieldset>
		</form>
		<?php if ($selectedRes): ?>
			<form id="res:edit" method="POST">
				<fieldset>
					<legend>Edit Resource <?php echo $selectedRes->getTitle(); ?></legend>
					<label>New Title:</label>
					<input type="text" name="res:edit:title" value="<?php
							echo $selectedRes->getTitle(); ?>">
					<label>New Link:</label>
					<input type="text" name="res:edit:link" value="<?php
							echo $selectedRes->getLink(); ?>">
					<label>New Tags:</label>
					<input type="text" name="res:edit:tags" value="<?php
							echo $selectedRes->getTags()->implode(', '); ?>">
					<input type="submit" name="res:edit" value="Edit Resource">
					<input type="submit" name="res:delete" value="Delete Resource">
				</fieldset>
			</form>
		<?php endif; ?>
		<form id="tag:add" method="POST">
			<fieldset>
				<legend>Add New Tag</legend>
				<label>Name</label>
				<input type="text" name="tag:add:name">
				<label>Parents</label>
				<input type="text" name="tag:add:parents">
				<input type="submit" name="tag:add" value="Add Tag">
			</fieldset>
		</form>
		<?php if (isset($selectedTag)): ?>
			<?php $selectedTagObject = $tagCollection->findByName($selectedTag); ?>
			<form id="tag:edit" method="POST">
				<fieldset>
					<legend>Edit Tag <?php echo $selectedTag; ?></legend>
					<label>New Name:</label>
					<input type="text" name="tag:edit:name" value="<?php echo $selectedTagObject->getName(); ?>">
					<label>New Parents:</label>
					<input type="text" name="tag:edit:parents" value="<?php echo $searchParents; ?>">
					<input type="submit" name="tag:edit" value="Edit Tag">
					<input type="submit" name="tag:delete" value="Delete Tag">
				</fieldset>
			</form>
		<?php endif; ?>
		<dl id="tree">
			<?php echo getTreeView($tagCollection->getRoots(), '      '); ?>
		</dl>
		<h3><?php echo $searchQuery; ?></h3>
		<ul id="resources">
			<?php foreach ($searchResults as $res): ?>
				<li>
					<?php if (preg_match('/^https?:\/\/(www\.)?[^\.\/]+\.[^\.\/]/', $res->getLink())): ?>
						<h4><a href="<?php echo $res->getLink(); ?>" target="_blank">
						<?php echo $res->getTitle(); ?></a></h4>
					<?php else: ?>
						<h4><?php echo $res->getTitle(); ?></h4>
						<p><?php echo $res->getLink(); ?></p>
					<?php endif; ?>
					<a href="<?php echo $location->getClone()
							->setParam('res', $res->getId())
							->setParam('focus', 'res')->getUrl(); ?>">edit</a>
					<ul>
						<?php foreach ($res->getTags() as $tag): ?>
							<li><?php echo getTagLinks($tag); ?></li>
						<?php endforeach; ?>
					</ul>
				</li>
			<?php endforeach; ?>
		</ul>
		<p>Quick link url: javascript:(function()%7Bvar%20a%3Dwindow,b%3Ddocument,c%3DencodeURIComponent,d%3Da.open("http://<?php echo $_SERVER['HTTP_HOST'].$location->getPath(); ?>%3Flink%3D"%2Bc(b.location)%2B"%26title%3D"%2Bc(b.title),"groph_popup","left%3D"%2B((a.screenX%7C%7Ca.screenLeft)%2B10)%2B",top%3D"%2B((a.screenY%7C%7Ca.screenTop)%2B10)%2B",height%3D420px,width%3D550px,resizable%3D1,alwaysRaised%3D1,scrollbars%3D1")%3Ba.setTimeout(function()%7Bd.focus()%7D,300)%7D)()%3B</p>
	</body>
</html>