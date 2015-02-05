<?php
use Tag\Tag;
use Tag\Factory as TagFactory;

class BranchTest extends \Codeception\TestCase\Test
{
    /**
     * @var \UnitTester
     */
    protected $tester;
    
    private $logger = Null;
    private $coll = Null;
    private $fixtures;
    
    public function __construct()
    {
    	parent::__construct();
    	$this->logger = new Logger(ROOT.'/test.log');
    	$data = json_decode(file_get_contents('groph.json'), true);
    	$this->fixtures = $data['tags'];
    }

    protected function _before()
    {
    	exec('rm -f '.ROOT.'/test.log');
    	$this->coll = null;
    	$db = new Database(':memory:', $this->logger);
    	$tagFactory = new TagFactory($db, $this->logger);
    	$this->coll = $tagFactory->getCollection();
    	$this->coll->load($this->fixtures);
    }

    protected function _after()
    {
    }
    
    public function testFindingAllTagsWithGivenName()
    {
    	$results = $this->coll->findAll('software development');
    	$this->assertEquals(1, $results->count());
    	$this->assertEquals('Software Development',
    			$results->getFirst()->getName());
    	$results = $this->coll->findAll('node js');
    	$this->assertEquals(2, $results->count());
    	$first = $results[0]; $second = $results[1];
    	$this->assertNotEquals($first->getId(), $second->getId());
    	$this->assertEquals($first->getName()->toNorm(),
    			$second->getName()->toNorm());
    }
    
    public function testVisitingPathFromTag()
    {
    	$tag1 = $this->coll->findFirst('software development');
    	$path = Vector::create(
    		'programming languages',
    		'interpreted programming languages',
    		'javascript'
    	);
    	$steps = 0;
    	$names = Vector::create();
    	$tag1->visitPath($path, function(Tag $tag) use (&$steps, &$names) {
    		$steps++;
    		$names->append($tag->getName());
    	});
    	$this->assertEquals(count($path), $steps);
    	$expected = Vector::create(
    		'Programming Languages',
    		'Interpreted Programming Languages',
    		'JavaScript'
    	);
    	$this->assertEquals($expected, $names);
    }
    
    public function testAddingBranchToTag()
    {
    	$branch = Vector::create('Prova1', 'Prova2');
    	$tag1 = $this->coll->findFirst('software development');
    	$count = 0;
    	$callback = function(Tag $tag) use (&$count) { $count ++; };
    	$tag1->visitPath($branch, $callback);
    	$this->assertEquals(0, $count);
    	
    	$leafId = 0;
    	$tag1->addBranch($branch, $leafId);
    	$tag1->visitPath($branch, $callback);
    	$this->assertEquals(2, $count);
    	
    	$prova1 = $this->coll->findFirst('prova1');
    	$prova2 = $this->coll->findFirst('prova2');
    	$this->assertEquals($leafId, $prova2->getId());
    	$this->assertEquals($prova2, $prova1->getChild('prova2'));
    }

    public function testMatchingCompletelyNonExistingPath()
    {
    	$path = Vector::create('Null1', 'Null2');
    	$leaves = Vector::create();
    	$tail = Vector::create();
    	$roots = $this->coll->pathMatch($path, $leaves, $tail);
    	$this->assertTrue($roots->isEmpty());
    	$this->assertTrue($leaves->isEmpty());
    	$this->assertEquals($tail, $path);
    }
    
    public function testMatchingSingleStepExistingPath()
    {
    	$path = Vector::create('node js');
    	$leaves = Vector::create();
    	$tail = Vector::create();
    	$roots = $this->coll->pathMatch($path, $leaves, $tail);
    	
    	$expected = $this->coll->findAll('node js');
    	$this->assertEquals($expected, $roots);
    	$this->assertEquals($expected, $leaves);
    	$this->assertTrue($tail->isEmpty());
    }
    
    public function testMatchingCompletelyExistentPath()
    {
    	$path = Vector::create('task runners', 'gulp');
    	$leaves = Vector::create();
    	$tail = Vector::create();
    	$roots = $this->coll->pathMatch($path, $leaves, $tail);
    	
    	$expectedRoots = $this->coll->findAll('task runners');
    	$this->assertEquals($roots, $expectedRoots);
    	
		$expectedLeaves = $this->coll->findAll('gulp');
    	$this->assertEquals($leaves, $expectedLeaves);
    	$this->assertTrue($tail->isEmpty());
    }
    
    public function testMatchingIncompletePath()
    {
    	$path = Vector::create('task runners', 'grunt', 'null1');
    	$leaves = Vector::create();
    	$tail = Vector::create();
    	$roots = $this->coll->pathMatch($path, $leaves, $tail);
    	
    	$expectedRoots = $this->coll->findAll('task runners');
		$expectedLeaves = $this->coll->findAll('grunt');
    	$expectedTail = Vector::create('null1');
    	
    	$this->assertEquals($expectedRoots, $roots);
    	$this->assertEquals($expectedLeaves, $leaves);
    	$this->assertEquals($expectedTail, $tail);
    }
    
    public function testCreatingCompletelyExistentPath()
    {
    	$path = Vector::create('task runners', 'gulp');
    	$leaves = Vector::create();
    	$size = $this->coll->getSize();
    	$roots = $this->coll->createPath($path, $leaves);
    	
    	$this->assertEquals($size, $this->coll->getSize());
    	$expectedLeaves = $this->coll->findAll('gulp');
    	$this->assertEquals($expectedLeaves, $leaves);
    	$expectedRoots = $this->coll->findAll('task runners');
    	$this->assertEquals($expectedRoots, $roots);
    }
    
    public function testCreatingCompletelyNonExistentPath()
    {
    	$path = Vector::create('Null1', 'Null2');
    	$leaves = Vector::create();
    	$size = $this->coll->getSize();
    	$roots = $this->coll->createPath($path, $leaves);
    	
    	$expectedRoots = Vector::create($this->coll
			->findFirst('Null1'));
    	$expectedLeaves = Vector::create($this->coll
			->findFirst('Null2'));
    	
    	$this->assertEquals($size + 2, $this->coll->getSize());
    	$this->assertEquals($expectedRoots, $roots);
    	$this->assertEquals($expectedLeaves, $leaves);
    }
    
    public function testCreatingIncompletePath()
    {
    	$path = Vector::create('node js', 'Null1', 'Null2');
    	$leaves = Vector::create();
    	$size = $this->coll->getSize();
    	$roots = $this->coll->createPath($path, $leaves);
    	
    	$expectedRoots = $this->coll->findAll('node js');
		$expectedLeaves = $this->coll->findAll('null2');
    	$this->assertEquals($expectedRoots, $roots);
    	$this->assertEquals($expectedLeaves, $leaves);
    	$expectedSize = $size + $expectedRoots->count() * 2;
    	$this->assertEquals($expectedSize, $this->coll->getSize());
    }
    
    public function testCreatingPathFromString()
    {
    	$string = 'Null2:Null1:node js, Null3:software development';
    	$groups = $this->coll->parseTagsString($string);
    	$tags = Vector::create();
    	$roots = Vector::create();
    	$size = $this->coll->getSize();
    	foreach ($groups as $group) {
    		$leaves = Vector::create();
    		$roots->merge($this->coll->createPath($group->reverse(), $leaves));
    		$tags->merge($leaves);
    	}
    	
    	$compare = function(Tag $a, Tag $b) {
    		return $a->getId() - $b->getId(); };
    	$expectedLeaves = $this->coll->findAll('null2')
    		->merge($this->coll->findAll('null3'))
    		->uasort($compare);
    	$this->assertEquals($expectedLeaves,
    			$tags->uasort($compare));
    	$expectedSize = $size
    		+ $this->coll->findAll('node js')->count() * 2
    		+ $this->coll->findAll('software development')->count();
    	$this->assertEquals($expectedSize, $this->coll->getSize());
		$expectedRoots = $this->coll->findAll('node js')
			->merge($this->coll->findAll('software development'));
    	$this->assertEquals($expectedRoots, $roots);
    	foreach ($this->coll->findAll('null1') as $null1) {
    		$children = $null1->getChildren();
    		$child = $children[0];
    		$this->assertTrue($child->getName()->matches('null2'));
    	}
    }
    
    public function testFindingTagsByPath()
    {
    	$path = Vector::create('task runners', 'gulp');
    	$results = $this->coll->findByPath($path);
    	$this->assertEquals(3, $results->count());
    	foreach($results as $tag) {
    		$this->assertNotNull($tag->getParent());
    		$this->assertTrue($tag->getName()->matches('gulp'));
    		$this->assertTrue($tag->getParent()
    			->getName()->matches('task runners'));
    		$grandpa = $tag->getParent()->getParent();
    		$name = $tag->getParent()->getParent()->getName();
    		$this->assertTrue($grandpa->getName()->matches('development tools')
				|| $grandpa->getName()->matches('node js'));
    		if ($grandpa->getName()->matches('node js')) {
    			$ggp = $grandpa->getParent();
    			$this->assertTrue($ggp->getName()->matches('javascript')
					|| $ggp->getName()->matches('asynchronous event-driven programming'));
    		} else {
    			$this->assertEquals($grandpa, $this->coll->findFirst('development tools'));
    		}
    	}
    }
}