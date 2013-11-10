<?php

class buttonCustom extends button
{
	protected $name = 'Custom';

	function fetchButton( $type='Custom', $html = '', $id = 'custom' )
	{
		return $html;
	}

	
	public function fetchId( $type='Custom', $html = '', $id = 'custom' )
	{
		return $this->parent->name.'-'.$id;
	}
}