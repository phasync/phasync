<?php

use phasync\Psr\ServerRequest;

print_r(ServerRequest::cookies('session=abc123; theme=dark%20blue; session=ignored'));
