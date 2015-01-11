<?php
include 'vendor/autoload.php';
// TODO: change add to resource:add
// TODO: change name (in add) to title
return Vector::create(array(
	'db' => 'groph-singleparent.sqlite',
	'actions' => array(
		'manage' => array(
			'post' => array(
				'import' => 'manage:import',
				'export' => 'manage:export',
			),
			'file' => 'manage:file',
		),
		'search' => array(
			'query' => 'search:query',
			'search' => 'search',
		),
		'add' => array(
			'link' => 'add:link',
			'name' => 'add:name',
			'tags' => 'add:tags',
			'add' => 'add',
		),
		'resource' => array(
			'edit' => array(
				'title' => 'res:edit:title',
				'link' => 'res:edit:link',
				'tags' => 'res:edit:tags',
				'edit' => 'res:edit',
				'delete' => 'res:delete'
			),
		),
		'tag' => array(
			'add' => array(
				'name' => 'tag:add:name',
				'parents' => 'tag:add:parents',
				'add' => 'tag:add',
			),
			'edit' => array(
				'name' => 'tag:edit:name',
				'parents' => 'tag:edit:parents',
				'edit' => 'tag:edit',
				'delete' => 'tag:delete',
			)
		)
	),
));