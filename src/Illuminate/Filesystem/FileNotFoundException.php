<?php namespace Illuminate\Filesystem;

// @deprecated Canonical location is Illuminate\Contracts\Filesystem\FileNotFoundException (L13).
// Runtime alias keeps existing catch sites working until the illuminate/filesystem swap;
// class_alias makes the two names identical so `catch (Illuminate\Filesystem\FileNotFoundException)`
// still catches the thrown contract exception. App repoint is grep-driven (Psalm-blind to alias).
class_alias(\Illuminate\Contracts\Filesystem\FileNotFoundException::class, __NAMESPACE__.'\\FileNotFoundException');
