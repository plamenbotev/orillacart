<?php

defined('_VALID_EXEC') or die('access denied');

interface SelfRegisterable{
	static public function register(array $elements);
	
}