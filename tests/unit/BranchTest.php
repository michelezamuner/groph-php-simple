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
    	$path = Vector::create('programming languages');
    	$leaves = Vector::create();
    	$tail = Vector::create();
    	$roots = $this->coll->pathMatch($path, $leaves, $tail);
    	
    	$tag = $this->coll->findFirst('programming languages');
    	$expected = Vector::create($tag);
    	$this->assertEquals($expected, $roots);
    	$this->assertEquals($expected, $leaves);
    	$this->assertTrue($tail->isEmpty());
    }
    
    public function testMatchingCompletelyExistentPath()
    {
    	$path = Vector::create('software development',
    			'programming languages');
    	$leaves = Vector::create();
    	$tail = Vector::create();
    	$roots = $this->coll->pathMatch($path, $leaves, $tail);
    	
    	$expectedRoots = Vector::create($this->coll
    			->findFirst('software development'));
    	$this->assertEquals($roots, $expectedRoots);
    	
    	$expectedLeaves = Vector::create($this->coll
    			->findFirst('programming languages'));
    	$this->assertEquals($leaves, $expectedLeaves);
    	$this->assertTrue($tail->isEmpty());
    }
    
    public function testMatchingIncompletePath()
    {
    	$path = Vector::create('javascript', 'node js', 'null1');
    	$leaves = Vector::create();
    	$tail = Vector::create();
    	$roots = $this->coll->pathMatch($path, $leaves, $tail);
    	
    	$expectedRoots = Vector::create($this->coll
    			->findFirst('javascript'));
    	$expectedLeaves = Vector::create($this->coll
				->findFirst('node js'));
    	$expectedTail = Vector::create('null1');
    	
    	$this->assertEquals($expectedRoots, $roots);
    	$this->assertEquals($expectedLeaves, $leaves);
    	$this->assertEquals($expectedTail, $tail);
    }
    
    public function testCreatingCompletelyExistentPath()
    {
    	$path = Vector::create('interpreted programming languages',
			'javascript');
    	$leaves = Vector::create();
    	$size = $this->coll->getSize();
    	$roots = $this->coll->createPath($path, $leaves);
    	
    	$this->assertEquals($size, $this->coll->getSize());
    	$expectedLeaves = Vector::create($this->coll
			->findFirst('javascript'));
    	$this->assertEquals($expectedLeaves, $leaves);
    	$expectedRoots = Vector::create($this->coll
			->findFirst('interpreted programming languages'));
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
    	$path = Vector::create('javascript', 'Null1', 'Null2');
    	$leaves = Vector::create();
    	$size = $this->coll->getSize();
    	$roots = $this->coll->createPath($path, $leaves);
    	
    	$expectedRoots = Vector::create($this->coll
			->findFirst('javascript'));
    	$expectedLeaves = Vector::create($this->coll
			->findFirst('null2'));
    	$this->assertEquals($expectedRoots, $roots);
    	$this->assertEquals($expectedLeaves, $leaves);
    	$this->assertEquals($size + 2, $this->coll->getSize());
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
    	
    	$null1 = $this->coll->findFirst('null1');
    	$null2 = $this->coll->findFirst('null2');
    	$null3 = $this->coll->findFirst('null3');
    	$this->assertEquals($size + 3, $this->coll->getSize());
    	$this->assertEquals(Vector::create($null2, $null3), $tags);
    	$expectedRoots = Vector::create(
			$this->coll->findFirst('node js'),
    		$this->coll->findFirst('software development')
    	);
    	$this->assertEquals($expectedRoots, $roots);
    	$this->assertEquals($null2, $null1->getChild('null2'));
    }
}