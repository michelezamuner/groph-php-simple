<?php
namespace Tag;

interface Listener
{
	public function onDelete(Tag $tag);
}