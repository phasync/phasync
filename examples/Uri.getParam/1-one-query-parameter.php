<?php

use phasync\Psr\Uri;

$uri = new Uri('/search?q=fibers&tags[]=php&tags[]=async');

var_dump($uri->getParam('q'));
var_dump($uri->getParam('tags'));
var_dump($uri->getParam('page'));
