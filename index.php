<?php
include 'vendor/autoload.php';

function getTagLinks(Tag\Tag $tag) {
	global $state;
	global $path, /*$searchQuery, */$selectedTag;
	$tagLink = $path.'?'.(empty($selectedTag) ? ''
						: 'tag='.urlencode($selectedTag).'&').
						urlencode('search:query').'='.
						urlencode('"tag:'.$tag->getName().'"').
						'&search=Search';
	$editLink = $path.'?'.(!$state->getSearchQuery() ? ''
						: urlencode('search:query').'='.urlencode($state->getSearchQuery()).
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
	global $state;
	global $tagCollection, $resCollection/*, $searchQuery*/;
	$results = array();
	$searchTerms = array();
	if ($state->getSearchQuery()) {
		// Prima trovo tutte le stringhe tra virgolette
		preg_match_all('/"([^"]+)"/', $state->getSearchQuery(), $matches);
		$searchTerms = array_merge($searchTerms, $matches[1]);
		// Poi prendo tutto quello che non è tra virgolette,
		// lo suddivido per spazi bianchi, e lo aggiungo
		$remainder = $state->getSearchQuery();
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
			$matchingRes = $searchedTag ? array_map(function(Resource\Resource $res) {
				return $res->getId();
			}, $resCollection->findByTag($searchedTag)) : array();
		} else {
			// Trova tutte le tag che corrispondono a questo termine,
			// compresi tutti i figli
			$firstLevelTags = $tagCollection->findLike(array($term));
			$matchingTags = $firstLevelTags;
			$desc = function(Tag\Tag $tag) use (&$desc) {
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
			$uniqueIds = array_unique(array_map(function(Tag\Tag $tag) {
				return $tag->getId();
			}, $matchingTags));
			$uniqueTags = array_map(function($id) use ($tagCollection) {
				return $tagCollection->find($id);
			}, $uniqueIds);
			foreach ($uniqueTags as $tag) {
				$matchingRes = array_merge($matchingRes, array_map(function(Resource\Resource $res) {
					return $res->getId();
				}, $resCollection->findByTag($tag)));
			}
		}
		
		$intersect = empty($intersect)
			? $matchingRes
			: array_intersect($intersect, $matchingRes);
	}
	foreach (array_unique($intersect) as $resId)
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
		$tagIds = array_map(function(Tag\Tag $tag) {
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
	
	// TODO: Check if this works with:
	// $res->getTags()->map(function(Tag $tag) { return (string)$tag; });
	$resources = array();
	foreach ($resCollection->findLike(array('')) as $res)
		$resources[$res->getLink()] = array($res->getTitle(),
				$res->getTags()->toStringsArray());
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
	$errors = error_get_last();
	if ($errors) {
		file_put_contents(__DIR__.'/errors.log',
				date('Y-m-d H:i:s').implode(PHP_EOL, $errors), FILE_APPEND);
		echo implode('<br>', $errors);
	}
});

set_error_handler(function($errno, $errstr, $errfile, $errline) {
	throw new Exception($errstr, $errno);
});

try {
	$conf = include('configuration.php');
	$logger = new Logger('groph.log');
	$database = new Database($conf['db'], $logger);
	$tagFactory = new Tag\Factory($database, $logger);
	$resFactory = new Resource\Factory($tagFactory, $database, $logger);
	$tagCollection = $tagFactory->getCollection();
	$resCollection = $resFactory->getCollection();
	$tagFactory->addListener($resCollection);
	$state = State\State::create($resCollection);
	
	switch ($state->getPost()) {
		case $state->getExport():
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
			break;
		case $state->getImport():
			$param = $state->getImport()->getParam('file')->getName();
			move_uploaded_file($_FILES[$param]['tmp_name'], 'groph.json');
			$database->reset();
			import('groph.json');
			break;
		case $state->getResourceAdd():
			// TODO: finding tags by name is going not to
			// be possible any more
			$add = $state->getResourceAdd();
			$tags = Vector::create();
			$tagCollection->createPathsFromString(
					$add->getParam('tags'), $tags);
// 			$groups = $tagCollection::parseTagsNames($add->getParam('tags'));
// 			foreach ($groups as $group) {
// 				$leaves = Vector::create();
// 				$tagCollection->createPath($group->reverse(), $leaves);
// 				$tags->merge($leaves);
// 			}
			$resCollection->add(array($add->getParam('link'),
					$add->getParam('title'), (array)$tags));
			break;
		case $state->getResourceEdit():
			$edit = $state->getResourceEdit();
			$tags = Vector::create();
			$tagCollection->createPathsFromString(
					$edit->getParam('tags'), $tags);
			$resCollection->find($edit->getParam('id'))
				->setLink($edit->getParam('link'))
				->setTitle($edit->getParam('title'))
				->setTags((Array)$tags)
				->save();
			break;
		default:
			break;
	}

	if (isset($_POST['tag:delete'])) {
		$tag = $tagCollection->findByName($_GET['tag'])->delete();
		if ($tag) $tag->delete();
	}
	
	$location = new Location();
	$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
	
	if (isset($_GET['tag'])) {
		$selectedTag = $_GET['tag'];
		$tag = $tagCollection->findByName($selectedTag);
		if ($tag) {
			$searchParents = implode(',', array_map(function(Tag\Tag $tag) {
				return $tag->getName();
			}, $tag->getParents()));
		} else {
			$selectedTag = null;
		}
	}
	
	if (isset($_POST['tag:add'])) {
		$newTagName = $_POST['tag:add:name'];
		$tagGroupsString = preg_replace('/\s+,\s+/', ',', $_POST['tag:add:parents']);
		$tagGroups = empty($tagGroupsString) ? array() : explode(',', $tagGroupsString);
		foreach ($tagGroups as $group) {
			$tagsNames = array_merge(array_reverse(explode(':', $group)),
				array($newTagName));
			getTagWithDescendants($tagsNames);
		}
		if (empty($tagGroups))
			$tagFactory->create(array($newTagName))->save();
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
			// La tag trovata potrebbe essere la stessa selected tag
			// nel caso in cui uno abbia cambiato il case del nome
			else if ($tag->getId() === $selectedTagObject->getId()) {
				$tag->setName($newName)->save();
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
		
		$realParents = array_map(function(Tag\Tag $tag) {
			return $tag->getName();
		}, $selectedTagObject->getParents());
		$parentsNames = preg_replace('/,\s+\s+/', ',', $_POST['tag:edit:parents']);
		$parentsNames = empty($parentsNames)
				? array() : explode(',', $parentsNames);
		
		// Rimuovi i parent che non sono più nella lista di parents
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
				$children = array_map(function(Tag\Tag $tag) {
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
		
		// Aggiorno $searchParents
		$searchParents = implode(',', array_map(function(Tag\Tag $tag) {
			return $tag->getName();
		}, $selectedTagObject->getParents()));
	}
	
	if (isset($_POST['res:delete'])/* && $selectedRes*/) {
		$selectedRes->delete();
		$selectedRes = null;
	}
		
	if (isset($_GET['ajax'])) exit('success');
	
	$searchResults = getSearchResults();
	$selectedRes = $state->getSelectedResource();
?>
<!doctype html>
<html lang="en">
	<head>
		<title>Groph</title>
		<meta charset="utf-8">
		<script src="//ajax.googleapis.com/ajax/libs/jquery/1.11.2/jquery.min.js"></script>
		<script>
			$(function() {
				<?php
				/**
				 * If the user is adding a resource with
				 * prefilled fields, add the new resource
				 * via AJAX, then close the popup window.
				 */
				if ($state->getResourceAddPrefill()->getIsSet()) {
					$add = $state->getResourceAdd();
					$prefill = $state->getResourceAddPrefill(); ?>
					$('input[name="<?php echo $add->getParam('tags')->getName(); ?>"]').focus();
					$('input[name="<?php echo $add?>"]').click(function(event) {
						event.preventDefault();
						<?php $url = $location->getClone()->setParam('ajax', '1')->getUrl(); ?>
						$.post('<?php echo $url; ?>', {
							'<?php echo $add; ?>': 'Add Resource',
							'<?php echo $add->getParam('link')->getName(); ?>': '<?php echo $prefill->getParam('link'); ?>',
							'<?php echo $add->getParam('title')->getName(); ?>': '<?php echo $prefill->getParam('title'); ?>',
							'<?php echo $add->getParam('tags')->getName(); ?>': $('input[name="<?php echo $add->getParam('tags')->getName(); ?>"]').val()
						}, function(data) {
							if (data !== 'success') alert(data);
							window.close();
						});
					});
				<?php } ?>

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
					<?php $edit = $state->getResourceEdit(); ?>
					$('input[name="<?php echo $edit; ?>"]').click(function(event) {
						var oldTitle = '<?php echo $selectedRes->getTitle(); ?>';
						var oldLink = '<?php echo $selectedRes->getLink(); ?>';
						var oldTags = '<?php echo $selectedRes->getTags()->implode(', '); ?>';
						var newTitle = $('input[name="<?php echo $edit->getParam('title')->getName(); ?>"]').val();
						var newLink = $('input[name="<?php echo $edit->getParam('link')->getName(); ?>"]').val();
						var newTags = $('input[name="<?php echo $edit->getParam('tags')->getName(); ?>"]').val();
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
			<input type="submit" name="<?php echo $state->getExport(); ?>" value="Export">
			<label>File</label>
			<input type="file" name="<?php echo $state->getImport()->getParam('file')->getName(); ?>">
			<input type="submit" name="<?php echo $state->getImport(); ?>" value="Import">
		</form>
		<form id="search">
			<fieldset>
				<legend>Search Resources</legend>
				<input type="text"
						name="<?php echo $state->getSearch()->getParam('query')->getName(); ?>"
						value="<?php echo $state->getSearchQuery(); ?>"
						<?php if (!$location->getParam('focus')):?>
							autofocus<?php endif;?>>
				<input type="submit" name="<?php echo $state->getSearch(); ?>" value="Search">
			</fieldset>
		</form>
		<form id="resource:add" method="POST">
			<fieldset>
				<legend>Add New Resource</legend>
				<label>Link</label>
				<?php $add = $state->getResourceAdd(); ?>
				<?php $prefill = $state->getResourceAddPrefill(); ?>
				<input type="text" name="<?php echo $add->getParam('link')->getName(); ?>"
						value="<?php echo $prefill->getParam('link'); ?>">
				<label>Title</label>
				<input type="text" name="<?php echo $add->getParam('title')->getName(); ?>"
						value="<?php echo $prefill->getParam('title'); ?>">
				<label >Tags</label>
				<input type="text" name="<?php echo $add->getParam('tags')->getName(); ?>">
				<input type="submit" name="<?php echo $add; ?>" value="Add Resource">
			</fieldset>
		</form>
		<?php if ($selectedRes): ?>
			<?php $edit = $state->getResourceEdit(); ?>
			<?php $delete = $state->getResourceDelete(); ?>
			<form id="res:edit" method="POST">
				<fieldset>
					<legend>Edit Resource <?php echo $selectedRes->getTitle(); ?></legend>
					<label>New Link:</label>
					<input type="hidden"
						name="<?php echo $edit->getParam('id')->getName(); ?>"
						value="<?php echo $selectedRes->getId(); ?>">
					<input type="text"
						name="<?php echo $edit->getParam('link')->getName(); ?>"
						value="<?php echo $selectedRes->getLink(); ?>"
						<?php if ($location->getParam('focus') === 'res'): ?>
						autofocus<?php endif; ?>>
					<label>New Title:</label>
					<input type="text"
						name="<?php echo $edit->getParam('title')->getName(); ?>"
						value="<?php echo $selectedRes->getTitle(); ?>">
					<label>New Tags:</label>
					<input type="text"
						name="<?php echo $edit->getParam('tags')->getName(); ?>"
						value="<?php echo $selectedRes->getTags()->implode(', '); ?>">
					<input type="submit" name="<?php echo $edit; ?>" value="Edit Resource">
					<input type="submit" name="<?php echo $delete; ?>" value="Delete Resource">
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
					<input type="text"
						name="tag:edit:name"
						value="<?php echo $selectedTagObject->getName(); ?>"
						<?php if ($location->getParam('focus') === 'tag'): ?>
						autofocus<?php endif; ?>>
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
		<h3><?php echo $state->getSearchQuery(); ?></h3>
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
							->setParam($state->getResourceSelect()->getParam('id')->getName(), $res->getId())
							->setParam('focus', 'res')->getUrl(); ?>">edit</a>
					<ul>
						<?php foreach ($res->getTags() as $tag): ?>
							<li><?php echo getTagLinks($tag); ?></li>
						<?php endforeach; ?>
					</ul>
				</li>
			<?php endforeach; ?>
		</ul>
		<p>Quick link url: javascript:(function()%7Bvar%20a%3Dwindow,b%3Ddocument,c%3DencodeURIComponent,d%3Da.open("http://<?php echo $_SERVER['HTTP_HOST'].$location->getPath(); ?>%3Fresource%3Aadd%3Aprefill%3Alink%3D"%2Bc(b.location)%2B"%26resource%3Aadd%3Aprefill%3Atitle%3D"%2Bc(b.title),"groph_popup","left%3D"%2B((a.screenX%7C%7Ca.screenLeft)%2B10)%2B",top%3D"%2B((a.screenY%7C%7Ca.screenTop)%2B10)%2B",height%3D420px,width%3D550px,resizable%3D1,alwaysRaised%3D1,scrollbars%3D1")%3Ba.setTimeout(function()%7Bd.focus()%7D,300)%7D)()%3B</p>
	</body>
</html>
<?php
} catch (Exception $e) {
	file_put_contents(__DIR__.'/errors.log',
	date('Y-m-d H:i:s').': '.$e->getMessage().PHP_EOL.
	printTrace($e->getTrace()), FILE_APPEND);
	echo '<p>', $e->getMessage(), '</p>';
	echo '<p>', printTrace($e->getTrace(), '<br>'), '</p>';
}
?>