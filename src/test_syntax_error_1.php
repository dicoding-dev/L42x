<?php

// Implicitly nullable parameter - deprecated in PHP 8.4
// Fix: use ?DateTime $date = null
function processDate(DateTime $date = null): void
{
    echo $date?->format('Y-m-d');
}
