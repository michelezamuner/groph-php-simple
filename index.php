<?php
include 'vendor/autoload.php';

function getTagLinks(Tag\Tag $tag, $parents = False) {
	global $state;
	
	$search = $state->getSearch()->getParam('query')->getName();
	$tagLink = $state->getLocation()->getClone()
		->setParam($search, 'tag:'.$tag->getId())->getUrl();
	$select = $state->getTagSelect()->getParam('id')->getName();
	$editLink = $state->getLocation()->getClone()
		->setParam($select, $tag->getId())
		->setParam('focus', 'tag')->getUrl();
	
	$name = $parents
		? $tag->getUniquePath()->implode(':')
		: $tag->getName();
	return <<<RET
<a href="$tagLink" class="name">$name</a>
<a href="$editLink">edit</a>
<a href="javascript:void(0)"
	class="select-tag"
	path="{$tag->getUniquePath()->implode(':')}">select</a>
<form method="POST" style="display: inline-block;">
	<input type="hidden"
		name="{$state->getTagDelete()->getParam('id')->getName()}"
		value="{$tag->getId()}">
	<input type="submit" name="{$state->getTagDelete()}" value="Delete">
</form>
RET;
}

function getTreeView(Array $roots, $ind) {
    $tree = '';
    foreach ($roots as $branch) {
    	$closed = true;
    	if (isset($_SESSION['tree']) && isset($_SESSION['tree'][$branch->getId()])) {
    		$closed = $_SESSION['tree'][$branch->getId()] === 'closed';
    	}
        $tree .= $ind.'<dl tag-id="'.$branch->getId().'" class="tree'.($closed ? ' closed' : '').'">'.PHP_EOL;
		$tree .= $ind.'  '.'<dt>'.getTagLinks($branch).'</dt>'.PHP_EOL;
        $tree .= $ind.'  '.'<dd>'.PHP_EOL;
        $tree .= getTreeView($branch->getChildren(), $ind.'  ');
        $tree .= $ind.'  '.'</dd>'.PHP_EOL;
        $tree .= $ind.'</dl>'.PHP_EOL;
    }
    return $tree;
}

function getSearchResults() {
	global $state, $tagCollection, $resCollection;
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
			$searchedTag = $tagCollection->find($matches[1]);
			$matchingRes = $searchedTag ? array_map(function(Resource\Resource $res) {
				return $res->getId();
			}, $resCollection->findByTag($searchedTag)) : array();
			
		// If an empty string was searched, return all resources
		} elseif ($term === '') {
			$matchingRes = array_map(function (Resource\Resource $resource) {
				return $resource->getId();
			}, $resCollection->findLike(array($term)));
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
			
			// Qui $matchingRes contiene le risorse corrispondenti
			// alle tag associate alla keyword corrente $term.
			// Ora cerca $term anche tra i nomi e i link delle
			// risorse.
			$matchingRes = array_merge($matchingRes, array_map(function(Resource\Resource $res) {
				return $res->getId();
			}, $resCollection->findLike(array($term))));
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

function export($file) {
	global $tagCollection, $resCollection;
	$tags = array();
	foreach ($tagCollection->findLike(array('')) as $tag) {
		$parentPath = $tag->getParent()
			? $tag->getParent()->getUniquePath()->implode(':')
			: '';
		$tags[] = array((string)$tag->getName() => $parentPath);
	}
	
	$resources = array();
	foreach ($resCollection->findLike(array('')) as $res)
		$resources[$res->getLink()] = array($res->getTitle(),
				$res->getTags()->toStringsArray());
	file_put_contents($file, Vector::create(Array(
		'tags' => $tags, 'resources' => $resources
	))->toJson());
}

function printTrace(Array $trace, $sep = PHP_EOL) {
	$string = '';
	foreach ($trace as $id => $call) {
		$file = isset($call['file']) ? $call['file'] : '';
		$line = isset($call['line']) ? $call['line'] : '';
		$class = isset($call['class']) ? $call['class'] : '';
		$type = isset($call['type']) ? $call['type'] : '';
		$function = isset($call['function']) ? $call['function'] : '';
		$args = isset($call['args'])
			? implode(', ', array_map(function($arg) {
				if (is_object($arg))
					return get_class($arg);
				else if (is_array($arg))
					return 'Array';
				else
					return (string)$arg;
			}, $call['args']))
			: '';
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
	$state = State\State::create($tagCollection, $resCollection);
	
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
			$add = $state->getResourceAdd();
			$tags = Vector::create();
			$title = trim((string)$add->getParam('title'));
			if (empty($title))
				throw new Exception('Missing title');
			$link = trim((string)$add->getParam('link'));
			if (empty($link))
				throw new Exception('Missing link');
			$tagsGroups = trim((string)$add->getParam('tags'));
			if (empty($tagsGroups))
				throw new Exception('Missing tags');
			$tagCollection->createPathsFromString(
					$tagsGroups, $tags);
			$resCollection->add(array($link, $title, (array)$tags));
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
		case $state->getResourceDelete():
			$id = $state->getResourceDelete()->getParam('id');
			$id = (int)"$id";
			if (empty($id))
				$id = $state->getSelectedResource()->getId();
			if (empty($id))
				throw new Exception('Missing resource id');
			$resCollection->find("$id")->delete();
			break;
		case $state->getTagAdd():
			$add = $state->getTagAdd();
			$name = trim((string)$add->getParam('name'));
			if (empty($name))
				throw new Exception('Empty name');
			$parent = trim((string)$add->getParam('parent'));
			if (empty($parent))
				throw new Exception('Empty parent');
			$path = $tagCollection->parseTagsGroup($parent)
				->unshift($name);
			$tagCollection->createPath($path->reverse());
			break;
		case $state->getTagEdit():
			$edit = $state->getTagEdit();
			$newParent = $edit->getParam('parent');
			$newName = $edit->getParam('name');
			// Check parent's format
			if (!$tagCollection->isSingleGroup($newParent))
				throw new Exception('Tags can only have one parent');
			$tag = $tagCollection->find($edit->getParam('id'));
			// If parent already exists, check if it already has a
			// child with the given name
			$results = $tagCollection->findByPath(
				$tagCollection->parseTagsGroup($newParent)->reverse());
			$existingParent = $results->count()
				? $results->getFirst() : Null;
			if ($existingParent && in_array($newName,
					array_map(function(Tag\Tag $tag) { return "$tag"; },
						$existingParent->getChildren())))
				throw new Exception("Tag $existingParent already has a child named $newName");
			// Try to create parent path, and
			// check if it has changed.
			$parents = Vector::create();
			$tagCollection->createPathsFromString(
					$edit->getParam('parent'), $parents);
			if ($parents->count() > 1)
				throw new Exception('Tags cannot have more than one parent');
			if ($parents->count() > 0) {
				$parent = $parents->getFirst();
				// If it's the same parent, remove the old version
				// of this child
				if ($tag->getParent() && $parent->getId() === $tag->getParent()->getId())
					$parent->removeChild($tag);
				$parent->addChild($tag)->save();
			} else if ($tag->getParent()) {
				$tag->getParent()->removeChild($tag)->save();
			}
			$tag->setName($edit->getParam('name'))->save();
			break;
		case $state->getTagDelete():
			$id = $state->getTagDelete()->getParam('id');
			$id = (int)"$id";
			if (empty($id))
				$id = $state->getSelectedTag()->getId();
			if (empty($id))
				throw new Exception('Missing tag id');
			$tagCollection->find("$id")->delete();
			break;
		case $state->getTreeOpen():
			$id = $state->getTreeOpen()->getParam('id');
			if(!isset($_SESSION['tree']))
				$_SESSION['tree'] = Array();
			$_SESSION['tree']["$id"] = 'opened';
			exit;
			break;
		case $state->getTreeClose():
			$id = $state->getTreeClose()->getParam('id');
			if(!isset($_SESSION['tree']))
				$_SESSION['tree'] = Array();
			$_SESSION['tree']["$id"] = 'closed';
			exit;
			break;
		default:
			break;
	}
			
	if (isset($_GET['ajax'])) exit('success');
	
	$searchResults = getSearchResults();
	$selectedRes = $state->getSelectedResource();
	$selectedTag = $state->getSelectedTag();
?>
<!doctype html>
<html lang="en">
	<head>
		<title>Groph</title>
		<meta charset="utf-8">
		<link rel="stylesheet" href="styles.css">
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
					$('input[name="<?php echo $add?>"]').click(function(event) {
						event.preventDefault();
						<?php $url = $state->getLocation()
							->getClone()->setParam('ajax', '1')
							->getUrl(); ?>
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

				<?php if ($selectedTag): ?>
					<?php $edit = $state->getTagEdit(); ?>
					$('input[name="<?php echo $edit; ?>"]').click(function(event) {
						var oldName = '<?php echo $selectedTag->getName(); ?>';
						var oldParent = '<?php echo $selectedTag->getParent() ?
							$selectedTag->getParent()->getUniquePath()->implode(':') : ''; ?>';
						var newName = $('input[name="<?php echo $edit->getParam('name')->getName(); ?>"]').val();
						var newParent = $('input[name="<?php echo $edit->getParam('parent')->getName(); ?>"]').val();
						var message = '';
						if (oldName !== newName)
							message += 'Changing name "' + oldName
									+ '" to "' + newName + '".';
						if (oldParent !== newParent)
							message += 'Changing parent "' + oldParent
									+ '" to "' + newParent + '".';
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
				<?php endif; ?>
					$('input[name="<?php echo $state->getTagDelete(); ?>"]').click(function(event) {
						var id = $(this).parent().find('input[type="hidden"]').val();
						var tag = '';
						<?php if ($selectedTag): ?>
						if (parseInt(id) === <?php echo $selectedTag->getId(); ?>)
							tag = '<?php echo $selectedTag->getName(); ?>';
						else
						<?php endif; ?>
							tag = $(this).closest('dt').find('a.name').text();
						var answer = confirm('Deleting tag ' + tag + '. Continue?');
						if (!answer)
							event.preventDefault();
					});
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
				<?php endif; ?>
				$('input[name="<?php echo $state->getResourceDelete(); ?>"]').click(function(event) {
					var id = $(this).parent().find('input[type="hidden"]').val();
					var res = '';
					<?php if ($selectedRes): ?>
					if (parseInt(id) === <?php echo $selectedRes->getId(); ?>)
						res = '<?php echo $selectedRes->getTitle(); ?>';
					else
					<?php endif; ?>
						res = $(this).closest('li').find('h4 > a').text();
					var answer = confirm('Deleting resource ' + res + '. Continue?');
					if (!answer)
						event.preventDefault();
				});
				/**
				 * @var jQuery $inputToFill input element
				 * that has to be filled with tag names
				 * when using the "select" command
				 */
				var $inputToFill = undefined;
				$('input').focusout(function() {
					$fieldset = $(this).parent();
					$inputToFill = $fieldset.length
						? $fieldset.find('input[name="'
								+ $fieldset.attr('input-to-fill')
								+ '"]')
						: undefined;
				});
				$('.select-tag').click(function(event) {
					if ($inputToFill !== undefined) {
						var currentVal = $inputToFill.val();
						if (currentVal)
							currentVal += ',';
						$inputToFill.val(currentVal + $(this).attr('path'));
						$inputToFill.focus();
					}
				});
				$('.tree dt').each(function() {
					var closed = $(this).parent().hasClass('closed');
					var switchClass = 'switch' + (closed ? ' closed' : '');
					var switchSym = closed ? '+' : '-';
					$(this).prepend('<a href="javascript:void(0)" class="'
							+ switchClass + '">' + switchSym + '&nbsp;</a>');
				});
				$('.tree .switch').click(function() {
					var $container = $(this).closest('dl');
					var id = $container.attr('tag-id');
					var data = {};
					if ($container.hasClass('closed')) {
						data = {
							'tree:open': '',
							'tree:open:id': id
						};
					} else {
						data = {
							'tree:close': '',
							'tree:close:id': id
						};
					}
					$(this).text($container.hasClass('closed') ? '-\xA0' : '+\xA0');
					$container.toggleClass('closed');
					$.post('<?php echo $state->getLocation()->getUrl(); ?>',
						data
						, function(data) {
							console.log(data);
						})
						.fail(function() {
							console.dir(arguments);
					});
				});

				var tagsNames = {};
				function findTree($branch, branch, branchRoot) {
					var name = $branch.find('> dt > a.name').text()/*.toLowerCase()*/;
					if (!branch) {
						if (tagsNames.noColon === undefined)
							tagsNames.noColon = [];
						if (tagsNames.noColon.indexOf(name) == -1)
							tagsNames.noColon.push(name);
					} else {
						branch.push(branchRoot + ':' + name);
					}
					
					var $parent = $branch.parents('dl').first();
					if ($parent.length) {
						if (!branch) {
							var lname = name.toLowerCase();
							if (tagsNames[lname] === undefined)
								tagsNames[lname] = [];
							branch = tagsNames[lname];
							findTree($parent, branch, name);
						} else {
							findTree($parent, branch, branchRoot + ':' + name);
						}
					}
				}
				$('.tree').each(function() {
					findTree($(this));
				});
				
				function findStringInTree(string) {
					var results = [];
					if (string.indexOf(':') == -1) {
						for (var i in tagsNames.noColon) {
							var tagName = tagsNames.noColon[i];
							if (tagName.toLowerCase().indexOf(string) === 0)
								results.push(tagName);
						}
					} else {
						var split = string.split(':');
						var first = split[0];
						if (tagsNames[first] !== undefined) {
							var branch = tagsNames[first];
							for (var i in branch) {
								var tagName = branch[i];
								if (tagName.toLowerCase().indexOf(string) === 0
										&& tagName.split(':').length == split.length)
									results.push(tagName);
							}
						}
					}
					return results;
				}
				
				$('fieldset').each(function() {
					var tagBoxName = $(this).attr('input-to-fill');
					var $tagBox = $('input[name="' + tagBoxName + '"]');
					var $container = $('<span/>');
					$container.css({ 'position': 'relative' });
					$tagBox.wrap($container);
					var $hints = $('<select class="hints"></select>');
					$hints.css({
						'top': $tagBox.height() + 'px',
						'width': $tagBox.width() + 'px'
					});
					$hints.keydown(function(e) {
						if (e.keyCode == 32 || e.keyCode == 9) {
							e.preventDefault();
							var groups = $tagBox.val().split(',');
							groups[groups.length - 1] = $(this).children(':selected').html();
							$tagBox.val(groups.join());
							$(this).hide();
							$tagBox.focus();
						} else if (e.keyCode == 38 && $(this).find('option:first-child').is(':selected')) {
							$tagBox.focus();
						}
					});
					$tagBox.after($hints);
					// 8: backspace
					// 9: tab
					// 13: return
					// 16: shift
					// 17: ctrl
					// 32: space
					// 38: up
					// 40: down
					// 188: ,
					// 190: :
					$tagBox.bind('input', function(e) {
						var groups = $(this).val().split(',');
						var string = groups.length ? groups[groups.length - 1] : '';
						if (string != '') {
							var hintsHtml = '';
							var tagsFound = findStringInTree(string.toLowerCase());
							if (tagsFound.length > 0) {
								for (i in tagsFound) {
									hintsHtml += '<option>' + tagsFound[i] + '</option>';
								}
								$hints.html(hintsHtml);
								$hints.attr('size',tagsFound.length > 1 ? tagsFound.length : 2);
								$hints.find('option:first-child').attr('selected', 'selected');
								$hints.show();
							} else {
								$hints.hide();
							}
						} else {
							$hints.hide();
						}
					});
					$tagBox.keydown(function(e) {
						if (e.keyCode == 40) {
							$hints.find('option:first-child').attr('selected', 'selected');
							$hints.focus();
						} else if (e.keyCode == 188) {
							$hints.hide();
						} else if (e.keyCode == 9) {
							e.preventDefault();
							var groups = $(this).val().split(',');
							groups[groups.length - 1] = $hints.children(':selected').html();
							$(this).val(groups.join());
							$hints.hide();
						}
					});
				});
			});
		</script>
	</head>
	<body>
		<?php $quickLinkUrl = 'javascript:(function()%7Bvar%20a%3Dwindow,b%3Ddocument,c%3DencodeURIComponent,d%3Da.open("http://'.$_SERVER['HTTP_HOST'].$state->getLocation()->getPath().'%3Fresource%3Aadd%3Aprefill%3Alink%3D"%2Bc(b.location)%2B"%26resource%3Aadd%3Aprefill%3Atitle%3D"%2Bc(b.title.replace(/%27/g, "%26%2339")),"groph_popup","left%3D"%2B((a.screenX%7C%7Ca.screenLeft)%2B10)%2B",top%3D"%2B((a.screenY%7C%7Ca.screenTop)%2B10)%2B",height%3D420px,width%3D550px,resizable%3D1,alwaysRaised%3D1,scrollbars%3D1")%3Ba.setTimeout(function()%7Bd.focus()%7D,300)%7D)()%3B'; ?>
		<label>Quick link url: <input type="text" readonly="readonly" value='<?php echo $quickLinkUrl; ?>'></label>
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
						<?php if (!$state->getLocation()->getParam('focus')
								&& !$state->getResourceAddPrefill()->getIsSet()):?>
							autofocus<?php endif;?>>
				<input type="submit" name="<?php echo $state->getSearch(); ?>" value="Search">
			</fieldset>
		</form>
		<form id="resource:add" method="POST">
			<?php $add = $state->getResourceAdd(); ?>
			<fieldset input-to-fill="<?php echo $add->getParam('tags')->getName(); ?>">
				<legend>Add New Resource</legend>
				<label>Link</label>
				<?php $prefill = $state->getResourceAddPrefill(); ?>
				<input type="text" name="<?php echo $add->getParam('link')->getName(); ?>"
						value="<?php echo $prefill->getParam('link'); ?>">
				<label>Title</label>
				<input type="text" name="<?php echo $add->getParam('title')->getName(); ?>"
						value="<?php echo $prefill->getParam('title'); ?>">
					<label >Tags</label>
				<span>
					<input type="text" name="<?php echo $add->getParam('tags')->getName(); ?>"
						<?php if ($state->getResourceAddPrefill()->getIsSet()): ?>
						autofocus<?php endif; ?>
						autocomplete="off">
				</span>
				<input type="submit" name="<?php echo $add; ?>" value="Add Resource">
			</fieldset>
		</form>
		<?php if ($selectedRes): ?>
			<?php $edit = $state->getResourceEdit(); ?>
			<?php $delete = $state->getResourceDelete(); ?>
			<form id="res:edit" method="POST">
				<fieldset input-to-fill="<?php echo $edit->getParam('tags')->getName(); ?>">
					<legend>Edit Resource <?php echo $selectedRes->getTitle(); ?></legend>
					<label>New Link:</label>
					<input type="hidden"
						name="<?php echo $edit->getParam('id')->getName(); ?>"
						value="<?php echo $selectedRes->getId(); ?>">
					<input type="text"
						name="<?php echo $edit->getParam('link')->getName(); ?>"
						value="<?php echo $selectedRes->getLink(); ?>"
						<?php if ($state->getLocation()->getParam('focus') === 'res'): ?>
						autofocus<?php endif; ?>>
					<label>New Title:</label>
					<input type="text"
						name="<?php echo $edit->getParam('title')->getName(); ?>"
						value="<?php echo $selectedRes->getTitle(); ?>">
					<label>New Tags:</label>
					<input type="text"
						name="<?php echo $edit->getParam('tags')->getName(); ?>"
						value="<?php echo $selectedRes->getTags()->map(function(Tag\Tag $tag) {
							return $tag->getUniquePath()->implode(':'); })->implode(', '); ?>">
					<input type="submit" name="<?php echo $edit; ?>" value="Edit Resource">
					<input type="submit" name="<?php echo $delete; ?>" value="Delete Resource">
				</fieldset>
			</form>
		<?php endif; ?>
		<form id="tag:add" method="POST">
			<?php $add = $state->getTagAdd(); ?>
			<fieldset input-to-fill="<?php echo $add->getParam('parent')->getName(); ?>">
				<legend>Add New Tag</legend>
				<label>Name</label>
				<input type="text" name="<?php echo $add->getParam('name')->getName(); ?>">
				<label>Parent</label>
				<input type="text" name="<?php echo $add->getParam('parent')->getName(); ?>">
				<input type="submit" name="<?php echo $add; ?>" value="Add Tag">
			</fieldset>
		</form>
		<?php if ($selectedTag): ?>
			<?php $edit = $state->getTagEdit(); ?>
			<form id="tag:edit" method="POST">
				<fieldset input-to-fill="<?php echo $edit->getParam('parent')->getName(); ?>">
					<legend>Edit Tag <?php echo $selectedTag->getName(); ?></legend>
					<input type="hidden"
						name="<?php echo $edit->getParam('id')->getName(); ?>"
						value="<?php echo $selectedTag->getId(); ?>">
					<label>New Name:</label>
					<input type="text"
						name="<?php echo $edit->getParam('name')->getName(); ?>"
						value="<?php echo $selectedTag->getName(); ?>"
						<?php if ($state->getLocation()->getParam('focus') === 'tag'): ?>
						autofocus<?php endif; ?>>
					<label>New Parent:</label>
					<input type="text"
						name="<?php echo $edit->getParam('parent')->getName(); ?>"
						value="<?php echo $selectedTag->getParent() ?
							$selectedTag->getParent()->getUniquePath()->implode(':') : ''; ?>">
					<input type="submit" name="<?php echo $edit; ?>" value="Edit Tag">
					<input type="submit" name="tag:delete" value="Delete Tag">
				</fieldset>
			</form>
		<?php endif; ?>
		<div id="tree">
			<?php echo getTreeView($tagCollection->getRoots(), '      '); ?>
		</div>
		<?php
		$name = '';
		if (preg_match('/^tag:(\d+)$/', $state->getSearchQuery(), $matches)) {
			$tag = $tagCollection->find($matches[1]);
			if ($tag) $name = $tag->getUniquePath()->implode(':');
		} else {
			$name = $state->getSearchQuery();
		}
		?>
		<div id="resources">
			<h3><?php echo $name ? $name.'(Results: '.count($searchResults).')':''; ?></h3>
			<ul>
				<?php foreach ($searchResults as $res): ?>
					<li>
						<?php if (preg_match('/^https?:\/\/(www\.)?[^\.\/]+\.[^\.\/]/', $res->getLink())): ?>
							<h4><a href="<?php echo $res->getLink(); ?>" target="_blank">
							<?php echo $res->getTitle(); ?></a></h4>
						<?php else: ?>
							<h4><?php echo $res->getTitle(); ?></h4>
							<p><?php echo $res->getLink(); ?></p>
						<?php endif; ?>
						<a href="<?php echo $state->getLocation()->getClone()
								->setParam($state->getResourceSelect()->getParam('id')->getName(), $res->getId())
								->setParam('focus', 'res')->getUrl(); ?>">edit</a>
						<form method="POST" style="display: inline-block;">
							<?php $delete = $state->getResourceDelete(); ?>
							<input type="hidden"
								name="<?php echo $delete->getParam('id')->getName(); ?>"
								value="<?php echo $res->getId(); ?>">
							<input type="submit" name="<?php echo $delete; ?>" value="Delete">
						</form>
						<ul>
							<?php foreach ($res->getTags() as $tag): ?>
								<li><?php echo getTagLinks($tag, true); ?></li>
							<?php endforeach; ?>
						</ul>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
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
