<?php
/*$rules = array(
    array('d', 'b'),
    array('d', 'c'),
    array('e', 'a'),
    array('e', 'b'),
    array('e', 'c'),
    array('e', 'd'),
    );*/
/*$tags = array(
    0 => 'Framework',
    1 => 'Angular JS',
    2 => 'JavaScript',
    3 => 'Single Page Applications',
    4 => 'PHP',
    5 => 'Behat',
    6 => 'Quality Assurance',
    7 => 'Testing',
    8 => 'Web',
    );*/
$tags = array(
    0 => 'Framework',
    1 => 'Angular JS',
    2 => 'JavaScript',
    3 => 'Single Page Applications',
    4 => 'PHP',
    5 => 'Behat',
    6 => 'Quality Assurance',
    7 => 'Testing',
    8 => 'Testing Frameworks',
);
$rules = array(
    1 => array(0),
    
);

function mergeSequences(Array $sequences) {
    $merge = array();
    $mergedSomething = false;
    foreach ($sequences as $sequence) {
        $foundExistingMerge = false;
        foreach ($merge as $key => $merge_item) {
            if (count(array_intersect($merge_item, $sequence))) {
                $merge[$key] = array_unique(array_merge($merge_item, $sequence));
                $foundExistingMerge = true;
                if (!$mergedSomething) $mergedSomething = true;
                break;
            }
        }
        if (!$foundExistingMerge)
            $merge[] = $sequence;
    }
    if ($mergedSomething) $merge = mergeSequences($merge);
    return $merge;
}

function getSequences() {
    global $rules;
    return mergeSequences($rules);
}

function printTagTrees() {
    foreach (getSequences() as $sequence) {
        printTagTree($sequence);
    }
}

function printTagTree($sequence) {
    global $tags;
    $variations = getVariations($sequence);
    foreach ($variations as $variation) {
        $indentation = '';
        foreach ($variation as $id) {
            echo $indentation, $tags[$id], PHP_EOL;
            $indentation .= ' ';
        }
        echo PHP_EOL;
    }
}

function addTag($tag) {
    global $tags;
    $tags[] = $tag;
    return count($tags);
}

function addRule($from, $to) {
    global $tags, $rules;
    $fromId = array_search($from, $tags);
    $toId = array_search($to, $tags);
    $rules[] = array($toId, $fromId);
}

function printRules() {
    global $rules;
    foreach ($rules as $rule) {
        echo implode('', $rule), PHP_EOL;
    }
}

function printVariations($sequence) {
    foreach (getVariations(str_split($sequence)) as $variation)
        echo implode('', $variation), PHP_EOL;
}

function getVariations(Array $sequence, Array $head = array()) {
    $variations = array();
    $remainder = array_diff($sequence, $head);
    if (!count($remainder)) return array($head);
    foreach ($remainder as $element) {
        $tail = array_diff($remainder, array($element));
        $variation = array_merge($head, array($element), $tail);
        if (isVariationValid($head, $element, $tail)) {
            $newHead = array_merge($head, array($element));
            $newVariations = getVariations($sequence, $newHead);
            $variations = array_merge($variations, $newVariations);
        }
    }
    return $variations;
}

function isVariationValid(Array $head, $element, Array $tail) {
    $rulesBackwards = getRulesBackwards($element);
    $rulesForward = getRulesForward($element);
    $rulesInHead = array_intersect($head, $rulesBackwards);
    $rulesInTail = array_intersect($tail, $rulesForward);
    if (count($rulesInHead) || count($rulesInTail))
        return false;
    return true;
}

function getRulesBackwards($element) {
    global $rules;
    $rulesElements = array();
    foreach ($rules as $rule) {
        if ($rule[1] === $element)
            $rulesElements[] = $rule[0];
    }
    return $rulesElements;
}

function getRulesForward($element) {
    global $rules;
    $rulesElements = array();
    foreach ($rules as $rule) {
        if ($rule[0] === $element)
            $rulesElements[] = $rule[1];
    }
    return $rulesElements;
}