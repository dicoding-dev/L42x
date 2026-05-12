<?php

// ${var} string interpolation - deprecated in PHP 8.2, removed in PHP 9
// but still triggers deprecation warning in PHP 8.4
// Fix: use {$var} instead
$name = 'world';
echo "Hello ${name}!";
