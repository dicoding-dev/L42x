<?php

// BC bridge: Illuminate\Hashing\HasherInterface → Illuminate\Contracts\Hashing\Hasher
// Remove this file after the flip to stock L13.

class_alias(\Illuminate\Contracts\Hashing\Hasher::class, 'Illuminate\Hashing\HasherInterface');
