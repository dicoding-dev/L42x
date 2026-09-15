<?php

namespace Illuminate\Contracts\Encryption;

interface Encrypter
{
    public function encrypt($value, $serialize = true);
    public function decrypt($payload, $unserialize = true);
    public function getKey();
    public function getAllKeys();
    public function getPreviousKeys();
}
